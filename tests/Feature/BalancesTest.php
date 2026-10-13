<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Installment;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\Recurrence;
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

    public function test_billing_summary_uses_outstanding_amounts_after_historical_applications(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail();
        $receivable = $this->purchase('2026-09-06', 5000, $self, [[$maria, 5000]], $card->id)->allocations()->firstOrFail();
        $payable = $this->purchase('2026-09-06', 4000, $maria, [[$self, 4000]], $card->id)->allocations()->firstOrFail();
        $mariaReceipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 2000]);
        $selfReceipt = Receipt::create(['participant_id' => $self->id, 'received_at' => '2026-09-30', 'amount_cents' => 1000]);
        ReceiptApplication::create(['receipt_id' => $mariaReceipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $receivable->id, 'purchase_allocation_id' => $receivable->id, 'amount_cents' => 2000, 'source' => 'manual']);
        ReceiptApplication::create(['receipt_id' => $selfReceipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $payable->id, 'purchase_allocation_id' => $payable->id, 'amount_cents' => 1000, 'source' => 'manual']);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.ownConsumptionCents', 4000)
                ->where('summary.paidForOthersCents', 3000)
                ->where('summary.owedToOthersCents', 3000));
    }

    public function test_balances_classify_card_purchases_by_invoice_due_month_and_exclude_non_invoice_methods(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail();
        $pix = PaymentMethod::create(['name' => 'Pix avulso', 'type' => PaymentMethod::TYPE_PIX]);
        $this->purchase('2026-09-06', 5000, $self, [[$maria, 5000]], $card->id);
        $this->purchase('2026-10-06', 7000, $self, [[$maria, 7000]], $card->id);
        $this->purchase('2026-10-01', 9000, $self, [[$maria, 9000]], $pix->id);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 5000)
                ->where('participants.0.receivableCents', 5000)
                ->has('participants.0.receivableItems', 1)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-09-06')
            );

        $this->get('/balances?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 7000)
                ->where('participants.0.receivableCents', 7000)
                ->has('participants.0.receivableItems', 1)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-10-06')
            );
    }

    public function test_balances_classify_versioned_card_settings_using_the_purchase_date(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail();
        $card->latestInvoiceSetting()->update(['effective_from' => '2026-01-01']);
        $card->invoiceSettings()->create([
            'closing_day' => 20,
            'due_day' => 5,
            'effective_from' => '2026-10-11',
        ]);
        $this->purchase('2026-10-04', 1000, $self, [[$maria, 1000]], $card->id);
        $this->purchase('2026-10-15', 2000, $self, [[$maria, 2000]], $card->id);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 1000)
                ->where('participants.0.receivableCents', 1000)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-10-04')
            );

        $this->get('/balances?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 2000)
                ->where('participants.0.receivableCents', 2000)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-10-15')
            );
    }

    public function test_balances_classify_installment_and_recurrence_occurrences_by_invoice_due_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail();
        $installment = Installment::create([
            'start_date' => '2026-09-06',
            'description' => 'Parcelamento da Maria',
            'total_cents' => 3000,
            'installment_count' => 1,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
        ]);
        $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-09-06',
            'description' => 'Parcela da Maria',
            'amount_cents' => 3000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
        ]);
        $recurrence = Recurrence::create([
            'start_date' => '2026-09-01',
            'day_of_month' => 7,
            'description' => 'Recorrência da Maria',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
            'active' => true,
        ]);
        $recurrence->occurrences()->create([
            'purchased_at' => '2026-09-07',
            'description' => 'Ocorrência da Maria',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
        ]);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 7000)
                ->where('participants.0.receivableCents', 7000)
                ->has('participants.0.receivableItems', 2)
            );
    }

    public function test_balances_classify_loan_purchases_by_registered_due_month_without_card_closing_behavior(): void
    {
        [$self, $maria] = $this->catalogs();
        $loan = PaymentMethod::create(['name' => 'Empréstimo', 'type' => PaymentMethod::TYPE_LOAN]);
        $this->purchase('2026-09-06', 5000, $self, [[$maria, 5000]], $loan->id)->update(['due_date' => '2026-10-20']);
        $this->purchase('2026-10-06', 7000, $self, [[$maria, 7000]], $loan->id)->update(['due_date' => '2026-11-20']);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 5000)
                ->where('participants.0.receivableCents', 5000)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-09-06'));

        $this->get('/balances?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 7000)
                ->where('participants.0.receivableCents', 7000)
                ->where('participants.0.receivableItems.0.description', 'Compra 2026-10-06'));
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

    public function test_balances_include_old_loan_purchases_and_occurrences_by_selected_due_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $loan = PaymentMethod::create(['name' => 'Empréstimo histórico', 'type' => PaymentMethod::TYPE_LOAN]);

        $this->purchase('2025-01-06', 5000, $self, [[$maria, 5000]], $loan->id)->update(['due_date' => '2026-10-20']);

        $installment = Installment::create([
            'start_date' => '2025-01-06',
            'due_date' => '2026-10-20',
            'description' => 'Parcela histórica do empréstimo',
            'total_cents' => 3000,
            'installment_count' => 1,
            'payer_id' => $self->id,
            'payment_method_id' => $loan->id,
        ]);
        $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2025-01-06',
            'due_date' => '2026-10-20',
            'description' => 'Parcela histórica do empréstimo',
            'amount_cents' => 3000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
        ]);

        $recurrence = Recurrence::create([
            'start_date' => '2025-01-01',
            'due_date' => '2026-10-20',
            'day_of_month' => 6,
            'description' => 'Recorrência histórica do empréstimo',
            'amount_cents' => 2000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
            'active' => false,
        ]);
        $recurrence->occurrences()->create([
            'purchased_at' => '2025-01-06',
            'due_date' => '2026-10-20',
            'description' => 'Recorrência histórica do empréstimo',
            'amount_cents' => 2000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
        ]);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 10000)
                ->where('participants.0.receivableCents', 10000)
                ->has('participants.0.receivableItems', 3));
    }

    public function test_balances_classify_loan_installment_and_recurrence_occurrences_by_registered_due_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $loan = PaymentMethod::create(['name' => 'Empréstimo parcelado', 'type' => PaymentMethod::TYPE_LOAN]);
        $installment = Installment::create([
            'start_date' => '2026-09-06',
            'due_date' => '2026-10-20',
            'description' => 'Parcela do empréstimo',
            'total_cents' => 3000,
            'installment_count' => 1,
            'payer_id' => $self->id,
            'payment_method_id' => $loan->id,
        ]);
        $installmentOccurrence = $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-09-06',
            'due_date' => '2026-10-20',
            'description' => 'Parcela do empréstimo',
            'amount_cents' => 3000,
            'payer_id' => $self->id,
            'payment_method_id' => $loan->id,
        ]);
        $installmentOccurrence->allocations()->create(['participant_id' => $maria->id, 'amount_cents' => 3000]);
        $recurrence = Recurrence::create([
            'start_date' => '2026-09-01',
            'due_date' => '2026-10-25',
            'day_of_month' => 7,
            'description' => 'Cobrança recorrente do empréstimo',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
            'active' => true,
        ]);
        $recurrence->occurrences()->create([
            'purchased_at' => '2026-09-07',
            'due_date' => '2026-10-25',
            'description' => 'Cobrança recorrente do empréstimo',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
        ]);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 7000)
                ->where('participants.0.receivableCents', 7000)
                ->has('participants.0.receivableItems', 2));

        $this->get('/balances?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 4000)
                ->where('participants.0.receivableCents', 4000)
                ->has('participants.0.receivableItems', 1));
    }

    public function test_receipt_application_edit_is_scoped_to_the_selected_billing_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $this->purchase('2026-09-06', 5000, $self, [[$maria, 5000]]);
        $this->purchase('2026-10-06', 7000, $self, [[$maria, 7000]]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 5000]);

        $this->get("/receipts/{$receipt->id}/edit?month=2026-10")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Balances/ReceiptApplications')
                ->where('selectedMonth', '2026-10')
                ->has('debts', 1)
                ->where('debts.0.description', 'Compra 2026-09-06')
            );
    }

    public function test_receipt_application_edit_excludes_non_billable_items_even_when_they_are_already_applied(): void
    {
        [$self, $maria] = $this->catalogs();
        $allocations = collect([PaymentMethod::TYPE_PIX, PaymentMethod::TYPE_DEBIT, PaymentMethod::TYPE_CASH, PaymentMethod::TYPE_OTHER])->map(function (string $type, int $index) use ($self, $maria): PurchaseAllocation {
            $method = PaymentMethod::create(['name' => 'Forma '.$type, 'type' => $type]);

            return $this->purchase('2026-10-0'.($index + 1), 1000, $self, [[$maria, 1000]], $method->id)->allocations()->firstOrFail();
        });
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-10', 'amount_cents' => 4000]);
        $allocations->each(fn (PurchaseAllocation $allocation) => ReceiptApplication::create([
            'receipt_id' => $receipt->id,
            'source_type' => 'purchase_allocation',
            'source_id' => $allocation->id,
            'purchase_allocation_id' => $allocation->id,
            'amount_cents' => 1000,
            'source' => 'manual',
        ]));

        $this->get("/receipts/{$receipt->id}/edit?month=2026-10")
            ->assertInertia(fn (Assert $page) => $page->has('debts', 0));

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 0)
            ->where('participants.0.creditCents', 4000)
            ->where('receipts.0.appliedCents', 0)
            ->where('receipts.0.creditCents', 4000));
    }

    public function test_receipts_do_not_reconcile_or_manually_apply_non_billable_items(): void
    {
        [$self, $maria] = $this->catalogs();
        $allocations = collect([PaymentMethod::TYPE_PIX, PaymentMethod::TYPE_DEBIT, PaymentMethod::TYPE_CASH, PaymentMethod::TYPE_OTHER])->map(function (string $type, int $index) use ($self, $maria): PurchaseAllocation {
            $method = PaymentMethod::create(['name' => 'Forma '.$type, 'type' => $type]);

            return $this->purchase('2026-10-0'.($index + 1), 1000, $self, [[$maria, 1000]], $method->id)->allocations()->firstOrFail();
        });

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-10', 'amount' => '40,00'])
            ->assertRedirect('/balances');

        $receipt = Receipt::query()->firstOrFail();
        $this->assertSame(0, ReceiptApplication::query()->where('receipt_id', $receipt->id)->whereNull('superseded_at')->count());

        $this->from("/receipts/{$receipt->id}/edit")->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'purchase_allocation',
                'source_id' => $allocations->first()->id,
                'amount' => '10,00',
            ]],
        ])->assertRedirect("/receipts/{$receipt->id}/edit")->assertSessionHasErrors('applications');

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 0)
            ->where('participants.0.creditCents', 4000));
    }

    public function test_balance_details_include_card_fallback_and_loan_due_dates_without_replacing_purchase_dates(): void
    {
        [$self, $maria] = $this->catalogs();
        $loan = PaymentMethod::create(['name' => 'Empréstimo detalhado', 'type' => PaymentMethod::TYPE_LOAN]);
        $card = PaymentMethod::create(['name' => 'Cartão detalhado', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 5]);
        $purchase = $this->purchase('2025-01-06', 5000, $self, [[$maria, 5000]], $loan->id);
        $purchase->update(['due_date' => '2026-10-20']);
        $this->purchase('2026-09-06', 4000, $self, [[$maria, 4000]], $card->id);

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableItems.0.purchased_at', '2025-01-06')
            ->where('participants.0.receivableItems.0.due_date', '2026-10-20')
            ->where('participants.0.receivableItems.1.purchased_at', '2026-09-06')
            ->where('participants.0.receivableItems.1.due_date', '2026-10-05'));
    }

    public function test_manual_receipt_application_reduces_the_selected_invoice_item_and_preserves_the_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $allocation = $this->purchase('2026-09-06', 5000, $self, [[$maria, 5000]])->allocations()->firstOrFail();
        $this->purchase('2026-10-06', 7000, $self, [[$maria, 7000]]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 5000]);

        $this->put("/receipts/{$receipt->id}/applications?month=2026-10", [
            'applications' => [[
                'source_type' => 'purchase_allocation',
                'source_id' => $allocation->id,
                'amount' => '20,00',
            ]],
        ])->assertRedirect('/balances?month=2026-10')->assertSessionHasNoErrors();

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 3000)
            ->where('participants.0.receivableItems.0.amount_cents', 5000)
            ->where('participants.0.receivableItems.0.applied_cents', 2000)
            ->where('participants.0.receivableItems.0.outstanding_cents', 3000)
            ->where('receipts.0.appliedCents', 2000)
            ->where('receipts.0.creditCents', 3000)
        );
    }

    public function test_historical_participant_credit_remains_visible_in_a_later_billing_period(): void
    {
        [$self, $maria] = $this->catalogs();
        Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-30', 'amount_cents' => 3000]);
        $this->purchase('2026-10-06', 7000, $self, [[$maria, 7000]]);

        $this->get('/balances?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.name', 'Maria')
            ->where('participants.0.creditCents', 3000)
            ->where('participants.0.receivableCents', 7000)
        );
    }

    public function test_receipt_reduces_a_recurrence_occurrence_in_its_invoice_period(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail();
        $recurrence = Recurrence::create([
            'start_date' => '2026-09-01',
            'day_of_month' => 7,
            'description' => 'Recorrência cobrada',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
            'active' => true,
        ]);
        $occurrence = $recurrence->occurrences()->create([
            'purchased_at' => '2026-09-07',
            'description' => 'Ocorrência cobrada',
            'amount_cents' => 4000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $card->id,
        ]);

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-09-30',
            'amount' => '20,00',
        ])->assertRedirect('/balances');

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 2000)
            ->where('participants.0.receivableItems.0.source_type', 'recurrence_occurrence')
            ->where('participants.0.receivableItems.0.source_id', $occurrence->id)
            ->where('participants.0.receivableItems.0.applied_cents', 2000)
            ->where('participants.0.receivableItems.0.outstanding_cents', 2000)
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
        PaymentMethod::create(['name' => 'Cartão de cobrança', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 5]);
        PaymentMethod::query()->where('type', PaymentMethod::TYPE_CREDIT)->firstOrFail()->latestInvoiceSetting()->update(['due_day' => 10]);
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
