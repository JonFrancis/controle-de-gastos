<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallmentAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_installment_materializes_equal_allocations_with_exact_occurrence_totals(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $category = Category::create(['name' => 'Casa', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $response = $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra compartilhada',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id, 'category_id' => $category->id],
                ['participant_id' => $maria->id],
            ],
        ]);

        $response->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame('equal', $installment->allocation_mode);
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->orderBy('installment_number')->pluck('amount_cents')->all());
        $this->assertSame([
            [1667, 1666],
            [1666, 1667],
            [1667, 1667],
        ], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertDatabaseHas('installment_allocations', ['participant_id' => $self->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('installment_allocations', ['participant_id' => $maria->id, 'category_id' => null]);
    }

    public function test_amount_mode_preserves_defined_participant_totals_across_occurrences(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra por valores',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '60,00'],
                ['participant_id' => $maria->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame(6000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(4000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_percentage_mode_materializes_exact_percentages_and_rejects_invalid_sums(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->from('/installments')->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Percentuais inválidos',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '30,00'],
                ['participant_id' => $maria->id, 'percentage' => '60,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasErrors('allocations');

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra percentual',
            'total' => '100,00',
            'installment_count' => 3,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '25,00'],
                ['participant_id' => $maria->id, 'percentage' => '75,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->assertSame(2500, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(7500, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
        $this->assertSame([3333, 3333, 3334], $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn ($occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_adjusting_one_occurrence_redistributes_only_its_allocations_proportionally(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra ajustável',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();

        $this->patch("/installment-occurrences/{$occurrences[0]->id}", ['amount' => '75,00'])->assertRedirect('/installments');

        $updated = $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $this->assertSame([7500, 5000], $updated->pluck('amount_cents')->all());
        $this->assertSame([[3750, 3750], [2500, 2500]], $updated->map(fn ($occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
    }

    public function test_complete_schedule_edit_replaces_the_rule_for_active_occurrences(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra editável',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [
                ['participant_id' => $self->id],
                ['participant_id' => $maria->id],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '70,00'],
                ['participant_id' => $maria->id, 'amount' => '30,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $this->assertSame('amount', $installment->fresh()->allocation_mode);
        $this->assertSame(7000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $self->id)->sum('amount_cents'));
        $this->assertSame(3000, InstallmentAllocation::query()->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))->where('participant_id', $maria->id)->sum('amount_cents'));
    }

    public function test_receipt_reconciliation_applies_to_installment_allocations_in_date_order(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Compra para Maria',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '0,00'],
                ['participant_id' => $maria->id, 'amount' => '100,00'],
            ],
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '60,00'])->assertRedirect('/balances');

        $applications = ReceiptApplication::query()->where('source_type', 'installment_allocation')->orderBy('source_id')->get();
        $this->assertSame([5000, 1000], $applications->pluck('amount_cents')->all());
        $this->assertSame(2, $applications->count());
    }

    public function test_monthly_analysis_uses_installment_allocations_without_duplicating_occurrence_totals(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Compra analisada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
        ])->assertRedirect('/installments');

        $this->get('/analysis?month=2026-10&view=calendar')->assertInertia(fn (Assert $page) => $page
            ->where('summary.ownConsumptionCents', 2500)
            ->where('summary.paidForOthersCents', 2500)
            ->where('summary.totalDisbursedCents', 5000)
            ->where('participants.0.name', 'Maria')
            ->where('participants.0.grossCents', 2500)
            ->where('participants.0.items.0.amountCents', 2500));
    }

    public function test_inertia_create_and_complete_edit_screens_expose_rateio_fields(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();

        $this->get('/installments/create')->assertInertia(fn (Assert $page) => $page
            ->component('Installments/Create')
            ->has('participants')
            ->has('categories')
            ->has('paymentMethods'));

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra para edição',
            'total' => '100,00',
            'installment_count' => 2,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'percentage' => '25,00'],
                ['participant_id' => $maria->id, 'percentage' => '75,00'],
            ],
        ])->assertRedirect('/installments');

        $occurrence = Installment::query()->latest('id')->firstOrFail()->occurrences()->where('installment_number', 1)->firstOrFail();
        $this->get("/installment-occurrences/{$occurrence->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('Installments/Edit')
            ->where('installment.allocationMode', 'percentage')
            ->has('installment.allocations', 2)
            ->where('installment.allocations.1.percentageBasisPoints', 7500));
    }

    public function test_migration_converts_legacy_occurrences_and_preserves_receipt_application_history(): void
    {
        [$self, $maria, $paymentMethod] = $this->catalogs();
        $category = Category::create(['name' => 'Legado', 'active' => true]);
        $installment = Installment::create([
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento legado',
            'total_cents' => 10000,
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
            'category_id' => $category->id,
        ]);
        $occurrence = $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-10-01',
            'description' => $installment->description,
            'amount_cents' => 5000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
            'category_id' => $category->id,
        ]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-02', 'amount_cents' => 2000]);
        $application = ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'installment_occurrence', 'source_id' => $occurrence->id, 'amount_cents' => 2000, 'source' => 'manual']);

        Schema::dropIfExists('installment_allocations');
        Installment::query()->whereKey($installment->id)->update(['allocation_mode' => null]);

        $migration = require base_path('database/migrations/2026_10_09_013953_create_installment_allocations_table.php');
        $migration->up();

        $allocation = $occurrence->fresh()->allocations()->firstOrFail();
        $this->assertSame($maria->id, $allocation->participant_id);
        $this->assertSame($category->id, $allocation->category_id);
        $this->assertSame(5000, $allocation->amount_cents);
        $this->assertSame('installment_allocation', $application->fresh()->source_type);
        $this->assertSame($allocation->id, $application->fresh()->source_id);
        $this->assertSame(2000, $application->fresh()->amount_cents);
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        return [$self, $maria, $paymentMethod];
    }
}
