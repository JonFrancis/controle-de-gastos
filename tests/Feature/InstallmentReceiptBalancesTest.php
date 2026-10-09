<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallmentReceiptBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_charge_each_installment_allocation_without_repeating_the_occurrence_total(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento compartilhado',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '40,00'],
                ['participant_id' => $maria->id, 'amount' => '35,00'],
                ['participant_id' => $joana->id, 'amount' => '25,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $firstOccurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();
        $mariaAllocation = $firstOccurrence->allocations()->where('participant_id', $maria->id)->firstOrFail();
        $joanaAllocation = $firstOccurrence->allocations()->where('participant_id', $joana->id)->firstOrFail();

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('summary.ownConsumptionCents', 2000)
            ->where('summary.paidForOthersCents', 3000)
            ->where('participants.0.name', 'Joana')
            ->where('participants.0.receivableCents', 1250)
            ->where('participants.0.receivableItems.0.source_type', 'installment_allocation')
            ->where('participants.0.receivableItems.0.source_id', $joanaAllocation->id)
            ->where('participants.0.receivableItems.0.amount_cents', 1250)
            ->where('participants.1.name', 'Maria')
            ->where('participants.1.receivableCents', 1750)
            ->where('participants.1.receivableItems.0.source_type', 'installment_allocation')
            ->where('participants.1.receivableItems.0.source_id', $mariaAllocation->id)
            ->where('participants.1.receivableItems.0.amount_cents', 1750));
    }

    public function test_receipt_is_automatically_applied_to_the_oldest_installment_allocations(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento para recebimento',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $allocations = InstallmentAllocation::query()
            ->whereHas('occurrence', fn (Builder $query): Builder => $query->where('installment_id', $installment->id))
            ->join('installment_occurrences', 'installment_occurrences.id', '=', 'installment_allocations.installment_occurrence_id')
            ->orderBy('installment_occurrences.installment_number')
            ->select('installment_allocations.*')
            ->get();

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '70,00',
        ])->assertRedirect('/balances');

        $receipt = Receipt::query()->firstOrFail();
        $this->assertSame([
            [$allocations[0]->id, 5000],
            [$allocations[1]->id, 2000],
        ], ReceiptApplication::query()->where('receipt_id', $receipt->id)->orderBy('id')->get()->map(fn (ReceiptApplication $application): array => [$application->source_id, $application->amount_cents])->all());
        $this->assertDatabaseHas('receipt_applications', ['source_type' => 'installment_allocation', 'source_id' => $allocations[0]->id, 'source' => 'automatic']);
        $this->assertDatabaseHas('receipt_applications', ['source_type' => 'installment_allocation', 'source_id' => $allocations[1]->id, 'source' => 'automatic']);
    }

    public function test_receipt_applications_can_be_manually_reassigned_to_an_installment_allocation(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento com ajuste de recebimento',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $allocations = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()->pluck('allocations')->flatten();

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '60,00',
        ])->assertRedirect('/balances');
        $receipt = Receipt::query()->firstOrFail();

        $this->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'installment_allocation',
                'source_id' => $allocations[1]->id,
                'amount' => '40,00',
            ]],
        ])->assertRedirect('/balances')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('receipt_applications', [
            'receipt_id' => $receipt->id,
            'source_type' => 'installment_allocation',
            'source_id' => $allocations[1]->id,
            'amount_cents' => 4000,
            'source' => 'manual',
            'superseded_at' => null,
        ]);
        $this->assertFalse(ReceiptApplication::query()->where('receipt_id', $receipt->id)->where('source_id', $allocations[0]->id)->whereNull('superseded_at')->exists());
        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 5000)
            ->where('participants.0.creditCents', 2000));
    }

    public function test_manual_receipt_applications_reject_unknown_installment_allocations_without_changes(): void
    {
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-10', 'amount_cents' => 5000]);

        $this->from("/receipts/{$receipt->id}/edit")->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'installment_allocation',
                'source_id' => 999999,
                'amount' => '10,00',
            ]],
        ])->assertRedirect("/receipts/{$receipt->id}/edit")->assertSessionHasErrors('applications');

        $this->assertDatabaseMissing('receipt_applications', ['receipt_id' => $receipt->id]);
        $this->assertFalse($receipt->fresh()->is_manually_adjusted);
    }

    public function test_manual_receipt_applications_reject_amounts_above_an_installment_allocation_balance(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento com limite de recebimento',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $allocation = Installment::query()->latest('id')->firstOrFail()->occurrences()->firstOrFail()->allocations()->firstOrFail();
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-10', 'amount_cents' => 6000]);

        $this->from("/receipts/{$receipt->id}/edit")->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'installment_allocation',
                'source_id' => $allocation->id,
                'amount' => '60,00',
            ]],
        ])->assertRedirect("/receipts/{$receipt->id}/edit")->assertSessionHasErrors('applications');

        $this->assertDatabaseMissing('receipt_applications', ['receipt_id' => $receipt->id]);
        $this->assertFalse($receipt->fresh()->is_manually_adjusted);
    }

    public function test_legacy_installment_occurrences_keep_their_balance_and_receipt_source(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);
        $installment = Installment::create([
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento legado',
            'total_cents' => 10000,
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        $occurrence = InstallmentOccurrence::create([
            'installment_id' => $installment->id,
            'installment_number' => 1,
            'purchased_at' => '2026-10-01',
            'description' => $installment->description,
            'amount_cents' => 5000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 5000)
            ->where('participants.0.receivableItems.0.source_type', 'installment_occurrence')
            ->where('participants.0.receivableItems.0.source_id', $occurrence->id));

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '50,00',
        ])->assertRedirect('/balances');

        $this->assertDatabaseHas('receipt_applications', [
            'source_type' => 'installment_occurrence',
            'source_id' => $occurrence->id,
            'amount_cents' => 5000,
            'source' => 'automatic',
        ]);
    }

    public function test_rescheduling_multi_rateio_preserves_allocation_ids_and_rebuilds_balances_for_new_occurrences(): void
    {
        [$self, $maria, $joana, $paymentMethod] = $this->rateioCatalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento redimensionado',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $oldOccurrences = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $oldAllocationIds = $oldOccurrences->flatMap(fn (InstallmentOccurrence $occurrence): Collection => $occurrence->allocations->pluck('id'))->values()->all();
        $firstMariaAllocation = $oldOccurrences[0]->allocations->firstWhere('participant_id', $maria->id);
        $secondMariaAllocation = $oldOccurrences[1]->allocations->firstWhere('participant_id', $maria->id);

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '30,00',
        ])->assertRedirect('/balances');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-01',
            'total' => '120,00',
            'payment_method_id' => $paymentMethod->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $updatedOccurrences = $installment->fresh()->occurrences()->whereNull('archived_at')->with('allocations')->orderBy('installment_number')->get();
        $this->assertSame([4000, 4000, 4000], $updatedOccurrences->pluck('amount_cents')->all());
        $this->assertSame(6, $updatedOccurrences->sum(fn (InstallmentOccurrence $occurrence): int => $occurrence->allocations->count()));
        $this->assertSame([4000, 4000, 4000], $updatedOccurrences->map(fn (InstallmentOccurrence $occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
        $this->assertSame($oldAllocationIds, $updatedOccurrences->take(2)->flatMap(fn (InstallmentOccurrence $occurrence): Collection => $occurrence->allocations->pluck('id'))->values()->all());
        $this->assertSame([2400, 1600], $updatedOccurrences[0]->allocations->sortBy('participant_id')->pluck('amount_cents')->all());
        $this->assertSame([2400, 1600], $updatedOccurrences[2]->allocations->sortBy('participant_id')->pluck('amount_cents')->all());

        $this->assertSame([
            [$firstMariaAllocation->id, 2400],
            [$secondMariaAllocation->id, 600],
        ], ReceiptApplication::query()->where('receipt_id', Receipt::query()->firstOrFail()->id)->whereNull('superseded_at')->orderBy('id')->get()->map(fn (ReceiptApplication $application): array => [$application->source_id, $application->amount_cents])->all());
        $this->assertDatabaseHas('receipt_applications', [
            'receipt_id' => Receipt::query()->firstOrFail()->id,
            'source_type' => 'installment_allocation',
            'source_id' => $firstMariaAllocation->id,
            'amount_cents' => 2400,
            'source' => 'automatic',
            'superseded_at' => null,
        ]);
        $this->assertTrue(ReceiptApplication::query()
            ->where('receipt_id', Receipt::query()->firstOrFail()->id)
            ->where('source_id', $firstMariaAllocation->id)
            ->where('amount_cents', 3000)
            ->whereNotNull('superseded_at')
            ->exists());
        $this->get('/balances?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.name', 'Joana')
            ->where('participants.0.receivableCents', 3200)
            ->where('participants.1.name', 'Maria')
            ->where('participants.1.receivableCents', 1800));
    }

    public function test_rescheduling_rateios_records_old_and_new_allocation_snapshots(): void
    {
        [$self, $maria, $joana, $paymentMethod] = $this->rateioCatalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento auditado',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();
        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-01',
            'total' => '120,00',
            'payment_method_id' => $paymentMethod->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('auditable_type', $installment->getMorphClass())->where('auditable_id', $installment->id)->latest('id')->firstOrFail();
        $this->assertCount(4, $audit->metadata['old_allocations']);
        $this->assertCount(6, $audit->metadata['new_allocations']);
        $this->assertSame($audit->metadata['old_allocations'][0]['id'], $audit->metadata['new_allocations'][0]['id']);
        $this->assertSame(2400, $audit->metadata['new_allocations'][0]['amount_cents']);
        $this->assertSame(2400, $audit->metadata['new_allocations'][4]['amount_cents']);
    }

    public function test_receipt_listing_counts_only_active_applications_after_reassignment(): void
    {
        [$self, $maria, , $paymentMethod] = $this->rateioCatalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento com recebimento ajustado',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $secondAllocation = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get()[1]->allocations->firstOrFail();
        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-10', 'amount' => '100,00'])->assertRedirect('/balances');
        $receipt = Receipt::query()->firstOrFail();

        $this->put("/receipts/{$receipt->id}/applications", [
            'applications' => [['source_type' => 'installment_allocation', 'source_id' => $secondAllocation->id, 'amount' => '50,00']],
        ])->assertRedirect('/balances')->assertSessionHasNoErrors();

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('receipts.0.appliedCents', 5000)
            ->where('receipts.0.creditCents', 5000)
            ->where('receipts.0.manual', true));
    }

    public function test_rescheduling_archived_rateio_supersedes_manual_application_and_restores_receipt_credit(): void
    {
        [$self, $maria, , $paymentMethod] = $this->rateioCatalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento reduzido após recebimento',
            'total' => '120,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '120,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $archivedAllocation = $installment->occurrences()
            ->where('installment_number', 3)
            ->firstOrFail()
            ->allocations()
            ->firstOrFail();

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '30,00',
        ])->assertRedirect('/balances');
        $receipt = Receipt::query()->firstOrFail();

        $this->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'installment_allocation',
                'source_id' => $archivedAllocation->id,
                'amount' => '30,00',
            ]],
        ])->assertRedirect('/balances')->assertSessionHasNoErrors();

        $application = ReceiptApplication::query()->where('receipt_id', $receipt->id)->where('source', 'manual')->latest('id')->firstOrFail();
        $this->assertNull($application->superseded_at);

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-01',
            'total' => '100,00',
            'payment_method_id' => $paymentMethod->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertNotNull($application->superseded_at);
        $this->assertCount(2, $receipt->fresh()->applicationHistory);
        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('receipts.0.appliedCents', 0)
            ->where('receipts.0.creditCents', 3000)
            ->where('participants.1.receivableCents', 5000));
    }

    public function test_rescheduling_reconciles_manual_overapplication_without_reassigning_credit_to_another_debt(): void
    {
        [$self, $maria, $joana, $paymentMethod] = $this->rateioCatalogs();

        $this->post('/installments', [
            'start_date' => '2026-10-01',
            'description' => 'Parcelamento com saldo reduzido',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $mariaAllocation = $installment->occurrences()
            ->where('installment_number', 1)
            ->firstOrFail()
            ->allocations()
            ->where('participant_id', $maria->id)
            ->firstOrFail();

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-10',
            'amount' => '30,00',
        ])->assertRedirect('/balances');
        $receipt = Receipt::query()->firstOrFail();

        $this->put("/receipts/{$receipt->id}/applications", [
            'applications' => [[
                'source_type' => 'installment_allocation',
                'source_id' => $mariaAllocation->id,
                'amount' => '30,00',
            ]],
        ])->assertRedirect('/balances')->assertSessionHasNoErrors();
        $manualApplication = ReceiptApplication::query()
            ->where('receipt_id', $receipt->id)
            ->where('source', 'manual')
            ->latest('id')
            ->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-01',
            'total' => '60,00',
            'payment_method_id' => $paymentMethod->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $manualApplication->refresh();
        $this->assertNotNull($manualApplication->superseded_at);
        $this->assertSame([
            [$mariaAllocation->id, 1800, 'manual'],
        ], ReceiptApplication::query()
            ->where('receipt_id', $receipt->id)
            ->whereNull('superseded_at')
            ->get()
            ->map(fn (ReceiptApplication $application): array => [$application->source_id, $application->amount_cents, $application->source])
            ->all());
        $this->assertCount(3, $receipt->fresh()->applicationHistory);
        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('receipts.0.appliedCents', 1800)
            ->where('receipts.0.creditCents', 1200)
            ->where('participants.0.name', 'Joana')
            ->where('participants.0.receivableCents', 1200)
            ->where('participants.1.name', 'Maria')
            ->where('participants.1.receivableCents', 0));
    }

    /** @return array{0: Participant, 1: Participant, 2: Participant, 3: PaymentMethod} */
    private function rateioCatalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);

        return [$self, $maria, $joana, $paymentMethod];
    }
}
