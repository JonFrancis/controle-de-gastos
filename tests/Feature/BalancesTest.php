<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_registration_has_its_own_screen(): void
    {
        [, $maria] = $this->catalogs();

        $this->get('/receipts/create?month=2026-10')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Balances/CreateReceipt')
                ->where('selectedMonth', '2026-10')
                ->where('participants.0.id', $maria->id));
    }

    public function test_balances_separate_own_consumption_paid_for_others_and_owed_to_others(): void
    {
        [$self, $maria] = $this->catalogs();
        $this->purchase('2026-10-01', 10000, $self, [[$self, 4000], [$maria, 6000]]);
        $this->purchase('2026-10-02', 2000, $maria, [[$self, 2000]]);

        $this->get('/balances?month=2026-10')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Balances/Index')
                ->where('summary.ownConsumptionCents', 6000)
                ->where('summary.paidForOthersCents', 6000)
                ->where('summary.owedToOthersCents', 2000)
                ->where('participants.0.name', 'Maria')
                ->where('participants.0.receivableCents', 6000)
                ->where('participants.0.payableCents', 2000)
                ->where('participants.0.netCents', 4000));
    }

    public function test_receipt_is_applied_to_oldest_debts_and_excess_becomes_credit(): void
    {
        [$self, $maria] = $this->catalogs();
        $first = $this->purchase('2026-10-01', 5000, $self, [[$maria, 5000]])->allocations()->firstOrFail();
        $second = $this->purchase('2026-10-02', 7000, $self, [[$maria, 7000]])->allocations()->firstOrFail();

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '150,00', 'note' => 'Pix'])
            ->assertRedirect('/balances');

        $this->assertDatabaseHas('receipt_applications', ['purchase_allocation_id' => $first->id, 'amount_cents' => 5000, 'source' => 'automatic']);
        $this->assertDatabaseHas('receipt_applications', ['purchase_allocation_id' => $second->id, 'amount_cents' => 7000, 'source' => 'automatic']);
        $this->assertSame(3000, Receipt::query()->firstOrFail()->amount_cents - ReceiptApplication::query()->whereNull('superseded_at')->sum('amount_cents'));

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('participants.0.receivableCents', 0)->where('participants.0.creditCents', 3000));
    }

    public function test_receipt_reduces_the_person_open_debt_by_the_received_amount(): void
    {
        [$self, $maria] = $this->catalogs();
        $this->purchase('2026-10-01', 10000, $self, [[$maria, 10000]]);

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '40,00'])
            ->assertRedirect('/balances');

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 6000)
            ->where('participants.0.creditCents', 0));
    }

    public function test_receipt_application_screen_receives_a_date_only_value(): void
    {
        [, $maria] = $this->catalogs();
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount_cents' => 4000]);

        $this->get("/receipts/{$receipt->id}/edit")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Balances/ReceiptApplications')
                ->where('receipt.received_at', '2026-10-03')
                ->where('receipt.participant.name', 'Maria'));
    }

    public function test_dashboard_carries_historical_credit_into_the_selected_month(): void
    {
        [$self, $maria] = $this->catalogs();
        Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 3000]);
        $this->purchase('2026-10-03', 5000, $self, [[$maria, 5000]]);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('personChart.balances.0.name', 'Maria')
                ->where('personChart.balances.0.amountCents', 2000)
                ->where('personChart.balances.0.netCents', 5000)
                ->where('personChart.balances.0.creditCents', 3000)
            );
    }

    public function test_dashboard_keeps_historical_receipt_applications_when_loading_the_selected_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $purchase = $this->purchase('2026-10-03', 5000, $self, [[$maria, 5000]]);
        $allocation = $purchase->allocations()->firstOrFail();
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 2000]);
        ReceiptApplication::create([
            'receipt_id' => $receipt->id,
            'source_type' => 'purchase_allocation',
            'source_id' => $allocation->id,
            'purchase_allocation_id' => $allocation->id,
            'amount_cents' => 2000,
            'source' => 'manual',
        ]);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('personChart.balances.0.amountCents', 3000)
                ->where('personChart.balances.0.creditCents', 0)
            );
    }

    public function test_pending_movements_without_payment_method_are_excluded_from_balances(): void
    {
        [$self, $maria] = $this->catalogs();
        $purchase = Purchase::create([
            'purchased_at' => '2026-10-03',
            'description' => 'Lançamento pendente',
            'amount_cents' => 5000,
            'payer_id' => $self->id,
            'payment_method_id' => null,
        ]);
        $purchase->allocations()->create(['participant_id' => $maria->id, 'amount_cents' => 5000]);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('participants.0.name', 'Maria')
                ->where('participants.0.receivableCents', 0)
                ->where('participants.0.hasMovement', false)
            );
    }

    public function test_participant_balances_does_not_query_receipts_per_participant(): void
    {
        $this->catalogs();
        Participant::create(['name' => 'Joana']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "receipts"') && str_contains($sql, 'where "participant_id" = ?')) {
                $queries[] = $query->sql;
            }
        });

        app(BalanceService::class)->participantBalances(Carbon::parse('2026-10-31'), Carbon::parse('2026-10-01'));

        $this->assertSame([], $queries);
    }

    public function test_receipt_applications_can_be_adjusted_manually_without_losing_credit(): void
    {
        [$self, $maria] = $this->catalogs();
        $first = $this->purchase('2026-10-01', 6000, $self, [[$maria, 6000]])->allocations()->firstOrFail();
        $second = $this->purchase('2026-10-02', 6000, $self, [[$maria, 6000]])->allocations()->firstOrFail();
        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '100,00']);
        $receipt = Receipt::query()->firstOrFail();

        $this->put("/receipts/{$receipt->id}/applications", ['applications' => [['source_type' => 'purchase_allocation', 'source_id' => $second->id, 'amount' => '60,00']]])
            ->assertRedirect('/balances');

        $this->assertDatabaseHas('receipt_applications', ['receipt_id' => $receipt->id, 'purchase_allocation_id' => $second->id, 'amount_cents' => 6000, 'source' => 'manual']);
        $this->assertFalse(ReceiptApplication::query()->where('receipt_id', $receipt->id)->where('purchase_allocation_id', $first->id)->whereNull('superseded_at')->exists());
        $this->assertTrue(ReceiptApplication::query()->where('receipt_id', $receipt->id)->where('purchase_allocation_id', $first->id)->whereNotNull('superseded_at')->exists());
        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('participants.0.receivableCents', 6000)->where('participants.0.creditCents', 4000));
    }

    /** @return array{0: Participant, 1: Participant} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        Category::create(['name' => 'Casa']);

        return [$self, $maria];
    }

    /** @param list<array{0: Participant, 1: int}> $allocations */
    private function purchase(string $date, int $amountCents, Participant $payer, array $allocations, ?int $paymentMethodId = null): Purchase
    {
        $purchase = Purchase::create([
            'purchased_at' => $date,
            'description' => 'Compra '.$date,
            'amount_cents' => $amountCents,
            'payer_id' => $payer->id,
            'payment_method_id' => $paymentMethodId ?? PaymentMethod::query()->firstOrFail()->id,
        ]);

        foreach ($allocations as [$participant, $amount]) {
            PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $participant->id, 'amount_cents' => $amount]);
        }

        return $purchase;
    }
}
