<?php

namespace Tests\Feature;

use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->whereHas('occurrence', fn ($query) => $query->where('installment_id', $installment->id))
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
}
