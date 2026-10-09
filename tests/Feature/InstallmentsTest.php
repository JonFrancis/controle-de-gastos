<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
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

class InstallmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_installments_list_and_create_screens_are_separate(): void
    {
        $this->get('/installments')->assertInertia(fn (Assert $page) => $page->component('Installments/Index')->missing('participants')->missing('paymentMethods'));
        $this->get('/installments/create')->assertInertia(fn (Assert $page) => $page->component('Installments/Create')->has('participants')->has('paymentMethods'));
        $this->get('/recurrences')->assertInertia(fn (Assert $page) => $page->component('Recurrences/Index'));
    }

    public function test_installment_creation_generates_future_occurrences_with_exact_total(): void
    {
        $category = Category::create(['name' => 'Casa']);
        $card = PaymentMethod::create(['name' => 'Cartão', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Curso parcelado',
            'card_name' => 'CURSO ONLINE',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => null,
            'participant_id' => null,
            'payment_method_id' => $card->id,
            'category_id' => $category->id,
        ])->assertRedirect('/installments');

        $installment = Installment::query()->firstOrFail();

        $this->assertDatabaseHas('installments', [
            'id' => $installment->id,
            'total_cents' => 10000,
            'installment_count' => 3,
            'payer_id' => null,
            'participant_id' => null,
            'category_id' => $category->id,
        ]);
        $this->assertSame([3333, 3333, 3334], InstallmentOccurrence::query()->where('installment_id', $installment->id)->orderBy('installment_number')->pluck('amount_cents')->all());
        $this->assertSame(['2026-10-12', '2026-11-12', '2026-12-12'], InstallmentOccurrence::query()->where('installment_id', $installment->id)->orderBy('installment_number')->pluck('purchased_at')->map(fn ($date) => $date->toDateString())->all());
    }

    public function test_installments_listing_exposes_rateio_participants_and_own_category(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $category = Category::create(['name' => 'Casa']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $installment = Installment::create(['start_date' => '2026-10-12', 'description' => 'Compra rateada', 'total_cents' => 10000, 'installment_count' => 2, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id]);
        $occurrence = $installment->occurrences()->create(['installment_number' => 1, 'purchased_at' => '2026-10-12', 'description' => 'Compra rateada', 'amount_cents' => 5000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id]);
        InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => $self->id, 'category_id' => $category->id, 'amount_cents' => 2500]);
        InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => $maria->id, 'amount_cents' => 2500]);

        $this->get('/installments')
            ->assertInertia(fn (Assert $page) => $page
                ->where('installments.0.participant', 'Eu, Maria')
                ->where('installments.0.category', 'Casa'));
    }

    public function test_installments_listing_ignores_archived_occurrence_rateios_and_legacy_category_without_eu_rateio(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $joana = Participant::create(['name' => 'Joana']);
        $legacyCategory = Category::create(['name' => 'Legado']);
        $archivedCategory = Category::create(['name' => 'Arquivada']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $installment = Installment::create(['start_date' => '2026-10-12', 'description' => 'Compra com histórico', 'total_cents' => 10000, 'installment_count' => 2, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id, 'category_id' => $legacyCategory->id]);
        $activeOccurrence = $installment->occurrences()->create(['installment_number' => 1, 'purchased_at' => '2026-10-12', 'description' => 'Compra com histórico', 'amount_cents' => 5000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id]);
        InstallmentAllocation::create(['installment_occurrence_id' => $activeOccurrence->id, 'participant_id' => $maria->id, 'amount_cents' => 5000]);
        $archivedOccurrence = $installment->occurrences()->create(['installment_number' => 2, 'purchased_at' => '2026-11-12', 'description' => 'Compra com histórico', 'amount_cents' => 5000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id, 'archived_at' => now()]);
        InstallmentAllocation::create(['installment_occurrence_id' => $archivedOccurrence->id, 'participant_id' => $joana->id, 'category_id' => $archivedCategory->id, 'amount_cents' => 5000]);

        $this->get('/installments')
            ->assertInertia(fn (Assert $page) => $page
                ->where('installments.0.participant', 'Maria')
                ->where('installments.0.category', null));
    }

    public function test_installments_listing_uses_rateios_from_a_fully_archived_installment(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $legacyParticipant = Participant::create(['name' => 'Legado']);
        $maria = Participant::create(['name' => 'Maria']);
        $legacyCategory = Category::create(['name' => 'Categoria legada']);
        $rateioCategory = Category::create(['name' => 'Categoria rateada']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $archivedAt = now();
        $installment = Installment::create(['start_date' => '2026-10-12', 'description' => 'Parcelamento arquivado', 'total_cents' => 10000, 'installment_count' => 1, 'payer_id' => $self->id, 'participant_id' => $legacyParticipant->id, 'payment_method_id' => $paymentMethod->id, 'category_id' => $legacyCategory->id, 'archived_at' => $archivedAt]);
        $occurrence = $installment->occurrences()->create(['installment_number' => 1, 'purchased_at' => '2026-10-12', 'description' => 'Parcelamento arquivado', 'amount_cents' => 10000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id, 'category_id' => $legacyCategory->id, 'archived_at' => $archivedAt]);
        InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => $self->id, 'category_id' => $rateioCategory->id, 'amount_cents' => 5000]);
        InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => $maria->id, 'amount_cents' => 5000]);

        $this->get('/installments')
            ->assertInertia(fn (Assert $page) => $page
                ->where('installments.0.participant', 'Eu, Maria')
                ->where('installments.0.category', 'Categoria rateada'));
    }

    public function test_installment_occurrence_is_visible_in_calendar_and_invoice_cycle(): void
    {
        $card = PaymentMethod::create(['name' => 'Cartão', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);
        $this->createInstallment($card, '2026-10-12', 2);

        $this->get('/purchases?month=2026-10&view=calendar')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('occurrences', 1)->where('occurrences.0.description', 'Compra parcelada'));
        $this->get('/purchases?view=invoice&month=2026-11')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('invoiceGroups', 1)->has('invoiceGroups.0.purchases', 1)->where('invoiceGroups.0.purchases.0.origin', 'installment'));
    }

    public function test_installment_occurrence_can_be_adjusted_without_changing_other_occurrences(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();
        $this->assertSame([1, 2, 3], $occurrences->pluck('installment_number')->all());

        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '40,00'])->assertRedirect('/installments');

        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[1]->id, 'amount_cents' => 4000, 'is_adjusted' => true]);
        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[0]->id, 'amount_cents' => 3333]);
        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[2]->id, 'amount_cents' => 3334]);
    }

    public function test_only_the_first_occurrence_opens_the_complete_schedule_editor(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();

        $this->get("/installment-occurrences/{$occurrences[0]->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installments/Edit')
                ->where('installment.startDate', '2026-10-12')
                ->where('installment.endDate', '2026-12-12')
                ->where('installment.totalCents', 10000));

        $this->get("/installment-occurrences/{$occurrences[1]->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('Installments/OccurrenceForm'));
    }

    public function test_first_occurrence_editor_exposes_all_installment_rule_fields(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);
        $occurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->get("/installment-occurrences/{$occurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installments/Edit')
                ->has('participants')
                ->has('categories')
                ->has('paymentMethods')
                ->has('schedulePreview.installmentValues', 3)
                ->has('schedulePreview.invoiceImpacts', 3)
                ->where('schedulePreview.oldInstallmentCount', 3)
                ->where('schedulePreview.oldTotalCents', 10000)
                ->where('installment.description', 'Compra parcelada')
                ->where('installment.cardName', null)
                ->where('installment.paymentMethodId', $installment->payment_method_id));
    }

    public function test_first_occurrence_editor_exposes_the_rateio_rule_for_complete_edits(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'amount' => '40,00'],
                ['participant_id' => $maria->id, 'amount' => '60,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->get("/installment-occurrences/{$occurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('installment.allocationMode', 'amount')
                ->has('installment.allocations', 2)
                ->where('installment.allocations.0.participantId', $self->id)
                ->where('installment.allocations.0.amountCents', 4000)
                ->where('installment.allocations.1.participantId', $maria->id)
                ->where('installment.allocations.1.amountCents', 6000));
    }

    public function test_legacy_installment_editor_uses_legacy_fields_when_active_rateios_are_missing(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $installment = Installment::create([
            'start_date' => '2026-10-12',
            'description' => 'Parcelamento legado',
            'total_cents' => 10000,
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
            'category_id' => null,
        ]);

        $installment->occurrences()->createMany([
            ['installment_number' => 1, 'purchased_at' => '2026-10-12', 'description' => 'Parcelamento legado', 'amount_cents' => 5000, 'payer_id' => $self->id, 'participant_id' => $maria->id, 'payment_method_id' => $paymentMethod->id],
            ['installment_number' => 2, 'purchased_at' => '2026-11-12', 'description' => 'Parcelamento legado', 'amount_cents' => 5000, 'payer_id' => $self->id, 'participant_id' => $maria->id, 'payment_method_id' => $paymentMethod->id],
        ]);
        $firstOccurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->get("/installment-occurrences/{$firstOccurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('installment.allocationMode', 'equal')
                ->has('installment.allocations', 1)
                ->where('installment.allocations.0.participantId', $maria->id)
                ->where('installment.allocations.0.amountCents', 10000));

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'equal',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $this->assertSame([5000, 5000], $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get()->map(fn (InstallmentOccurrence $occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_null_eu_rateio_keeps_its_identity_and_receipt_application_when_reposted_from_the_editor(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $category = Category::create(['name' => 'Categoria própria']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $installment = Installment::create([
            'start_date' => '2026-10-12',
            'description' => 'Rateio migrado',
            'total_cents' => 10000,
            'installment_count' => 2,
            'allocation_mode' => 'amount',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        $installment->occurrences()->createMany([
            ['installment_number' => 1, 'purchased_at' => '2026-10-12', 'description' => 'Rateio migrado', 'amount_cents' => 5000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id],
            ['installment_number' => 2, 'purchased_at' => '2026-11-12', 'description' => 'Rateio migrado', 'amount_cents' => 5000, 'payer_id' => $self->id, 'payment_method_id' => $paymentMethod->id],
        ]);
        $occurrences = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $euAllocations = $occurrences->map(fn (InstallmentOccurrence $occurrence): InstallmentAllocation => InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => null, 'category_id' => $category->id, 'amount_cents' => 2500]));
        $occurrences->each(fn (InstallmentOccurrence $occurrence): InstallmentAllocation => InstallmentAllocation::create(['installment_occurrence_id' => $occurrence->id, 'participant_id' => $maria->id, 'amount_cents' => 2500]));
        $receipt = Receipt::create(['participant_id' => $self->id, 'received_at' => '2026-10-03', 'amount_cents' => 1000]);
        $application = ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'installment_allocation', 'source_id' => $euAllocations[0]->id, 'amount_cents' => 1000, 'source' => 'manual']);
        $firstOccurrence = $occurrences->firstOrFail();

        $this->get("/installment-occurrences/{$firstOccurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('installment.allocations.0.participantId', null));

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $self->id, 'participant_id_is_null' => true, 'category_id' => $category->id, 'amount' => '50,00'],
                ['participant_id' => $maria->id, 'amount' => '50,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('installment_allocations', ['id' => $euAllocations[0]->id, 'participant_id' => null, 'category_id' => $category->id]);
        $this->assertSame($euAllocations[0]->id, $application->fresh()->source_id);
        $this->assertSame($application->id, $application->fresh()->id);
        $this->assertSame(2, InstallmentAllocation::query()->whereNull('participant_id')->whereIn('installment_occurrence_id', $occurrences->pluck('id'))->count());
    }

    public function test_amount_rateio_editor_reconstructs_configured_values_from_all_active_occurrences(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $firstOccurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();

        $this->get("/installment-occurrences/{$firstOccurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('installment.allocations.0.participantId', $maria->id)
                ->where('installment.allocations.0.amountCents', 6000)
                ->where('installment.allocations.1.participantId', $joana->id)
                ->where('installment.allocations.1.amountCents', 4000));

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $updatedOccurrences = $installment->fresh()->occurrences()->whereNull('archived_at')->with('allocations')->orderBy('installment_number')->get();

        $this->assertSame(6000, $updatedOccurrences->flatMap(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->where('participant_id', $maria->id)->pluck('amount_cents')->all())->sum());
        $this->assertSame(4000, $updatedOccurrences->flatMap(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->where('participant_id', $joana->id)->pluck('amount_cents')->all())->sum());
    }

    public function test_resubmitting_rateio_after_an_occurrence_adjustment_uses_the_persisted_rule(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();

        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $this->get("/installment-occurrences/{$occurrences[0]->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('installment.allocations.0.amountCents', 6000)
                ->where('installment.allocations.1.amountCents', 4000));

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $updatedOccurrences = $installment->fresh()->occurrences()->whereNull('archived_at')->with('allocations')->orderBy('installment_number')->get();

        $this->assertSame([[2000, 1333], [2700, 1800], [2000, 1334]], $updatedOccurrences->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertSame([3333, 4500, 3334], $updatedOccurrences->map(fn (InstallmentOccurrence $occurrence): int => (int) $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_participant_change_with_receipt_application_creates_a_new_allocation_without_reusing_the_old_source(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
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
        $firstOccurrence = $installment->occurrences()->where('installment_number', 1)->firstOrFail();
        $oldMariaAllocation = $firstOccurrence->allocations()->where('participant_id', $maria->id)->firstOrFail();

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '20,00'])
            ->assertRedirect('/balances');
        $receiptApplication = ReceiptApplication::query()->where('source_id', $oldMariaAllocation->id)->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $joana->id, 'amount' => '100,00']],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $newJoanaAllocation = $firstOccurrence->fresh()->allocations()->where('participant_id', $joana->id)->firstOrFail();

        $this->assertNotSame($oldMariaAllocation->id, $newJoanaAllocation->id);
        $this->assertDatabaseHas('installment_allocations', ['id' => $oldMariaAllocation->id, 'participant_id' => $maria->id, 'amount_cents' => 0]);
        $this->assertSame($oldMariaAllocation->id, $receiptApplication->fresh()->source_id);
        $this->assertNotNull($receiptApplication->fresh()->superseded_at);

        $this->get("/installment-occurrences/{$firstOccurrence->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->has('installment.allocations', 1)
                ->where('installment.allocations.0.participantId', $joana->id)
                ->where('installment.allocations.0.amountCents', 10000));
    }

    public function test_malformed_allocations_are_rejected_without_a_prevalidation_type_error(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 2);

        $this->from('/installments')->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocations' => 'malformed',
            'confirmation' => '1',
        ])->assertSessionHasErrors('allocations');

        $this->from('/installments')->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocations' => ['malformed row'],
            'confirmation' => '1',
        ])->assertSessionHasErrors('allocations.0');
    }

    public function test_rateio_row_requires_the_participant_id_key_even_when_the_value_is_null(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 2);

        $this->from('/installments')->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [['amount' => '100,00']],
            'confirmation' => '1',
        ])->assertSessionHasErrors('allocations.0.participant_id');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => null, 'amount' => '100,00']],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();
    }

    public function test_complete_rateio_edit_propagates_new_rule_and_preserves_adjusted_occurrence_and_receipt_history(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $joana = Participant::create(['name' => 'Joana', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '60,00'],
                ['participant_id' => $joana->id, 'amount' => '40,00'],
            ],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $allocationIds = $occurrences->flatMap(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->pluck('id')->all())->values()->all();

        $this->post('/receipts', [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-03',
            'amount' => '2,00',
        ])->assertRedirect('/balances');

        $receiptApplication = ReceiptApplication::query()->where('source_type', 'installment_allocation')->firstOrFail();

        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [
                ['participant_id' => $maria->id, 'amount' => '70,00'],
                ['participant_id' => $joana->id, 'amount' => '30,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $updatedOccurrences = $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get();

        $this->assertSame([3333, 4500, 3334], $updatedOccurrences->pluck('amount_cents')->all());
        $this->assertSame([false, true, false], $updatedOccurrences->pluck('is_adjusted')->all());
        $this->assertSame([[2333, 1000], [3150, 1350], [2334, 1000]], $updatedOccurrences->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertSame($allocationIds, $updatedOccurrences->flatMap(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->pluck('id')->all())->values()->all());
        $this->assertNotNull($receiptApplication->fresh()->superseded_at);
        $this->assertCount(3, $receiptApplication->receipt->fresh()->applicationHistory);
        $this->assertSame($receiptApplication->source_id, $receiptApplication->receipt->fresh()->applications()->firstOrFail()->source_id);
    }

    public function test_adjusting_a_later_occurrence_redistributes_only_its_rateios_and_keeps_receipt_application_sources(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '60,00'], ['participant_id' => $self->id, 'amount' => '40,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->with('allocations')->orderBy('installment_number')->get();
        $firstAllocationIds = $occurrences[0]->allocations->pluck('id')->all();
        $secondAllocationIds = $occurrences[1]->allocations->pluck('id')->all();

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '10,00'])->assertRedirect('/balances');
        $applicationSourceIds = ReceiptApplication::query()->pluck('source_id')->all();

        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $updatedOccurrences = $installment->fresh()->occurrences()->with('allocations')->orderBy('installment_number')->get();

        $this->assertSame([2000, 3000], $updatedOccurrences[0]->allocations->sortBy('participant_id')->pluck('amount_cents')->all());
        $this->assertSame([1800, 2700], $updatedOccurrences[1]->allocations->sortBy('participant_id')->pluck('amount_cents')->all());
        $this->assertSame($firstAllocationIds, $updatedOccurrences[0]->allocations->pluck('id')->all());
        $this->assertSame($secondAllocationIds, $updatedOccurrences[1]->allocations->pluck('id')->all());
        $this->assertSame($applicationSourceIds, ReceiptApplication::query()->whereNull('superseded_at')->pluck('source_id')->all());
        $this->assertSame(4500, $updatedOccurrences[1]->allocations->sum('amount_cents'));
    }

    public function test_complete_rateio_edit_recalculates_occurrences_and_allocations_when_total_or_quantity_changes(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '60,00'], ['participant_id' => $self->id, 'amount' => '40,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '120,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [['participant_id' => $maria->id, 'percentage' => '25,00'], ['participant_id' => $self->id, 'percentage' => '75,00']],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $occurrences = $installment->fresh()->occurrences()->with('allocations')->whereNull('archived_at')->orderBy('installment_number')->get();

        $this->assertSame([4000, 4000, 4000], $occurrences->pluck('amount_cents')->all());
        $this->assertSame([false, false, false], $occurrences->pluck('is_adjusted')->all());
        $this->assertSame([[3000, 1000], [3000, 1000], [3000, 1000]], $occurrences->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertSame([4000, 4000, 4000], $occurrences->map(fn (InstallmentOccurrence $occurrence): int => $occurrence->allocations->sum('amount_cents'))->all());
    }

    public function test_complete_rateio_edit_updates_percentage_mode_participants_and_own_categories(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true]);
        $category = Category::create(['name' => 'Categoria nova']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra rateada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'amount',
            'allocations' => [['participant_id' => $maria->id, 'amount' => '50,00'], ['participant_id' => $self->id, 'amount' => '50,00']],
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'allocation_mode' => 'percentage',
            'allocations' => [
                ['participant_id' => $self->id, 'category_id' => $category->id, 'percentage' => '25,00'],
                ['participant_id' => $maria->id, 'percentage' => '75,00'],
            ],
            'confirmation' => '1',
        ])->assertRedirect('/installments')->assertSessionHasNoErrors();

        $occurrences = $installment->fresh()->occurrences()->with('allocations')->whereNull('archived_at')->orderBy('installment_number')->get();

        $this->assertSame('percentage', $installment->fresh()->allocation_mode);
        $this->assertSame([[1250, 3750], [1250, 3750]], $occurrences->map(fn (InstallmentOccurrence $occurrence): array => $occurrence->allocations->sortBy('participant_id')->pluck('amount_cents')->all())->all());
        $this->assertSame([$category->id, $category->id], $occurrences->map(fn (InstallmentOccurrence $occurrence): ?int => $occurrence->allocations->firstWhere('participant_id', $self->id)->category_id)->all());
        $this->assertSame([2500, 2500], $occurrences->map(fn (InstallmentOccurrence $occurrence): int => $occurrence->allocations->firstWhere('participant_id', $self->id)->percentage_basis_points)->all());
    }

    public function test_complete_rule_edit_propagates_fields_to_active_history_and_preserves_adjusted_amounts(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $payer = Participant::create(['name' => 'Pagador original', 'active' => true, 'is_default' => false]);
        $newPayer = Participant::create(['name' => 'Novo pagador', 'active' => true, 'is_default' => false]);
        $category = Category::create(['name' => 'Categoria original']);
        $newCategory = Category::create(['name' => 'Categoria nova']);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix original', 'type' => PaymentMethod::TYPE_PIX]);
        $newPaymentMethod = PaymentMethod::create(['name' => 'Pix novo', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-09-12',
            'description' => 'Descrição original',
            'card_name' => 'CARTÃO ORIGINAL',
            'total' => '100,00',
            'installment_count' => 3,
            'payer_id' => $payer->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'category_id' => $category->id,
        ])->assertRedirect('/installments');

        $installment = Installment::query()->latest('id')->firstOrFail();
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();
        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-09-12',
            'end_date' => '2026-11-12',
            'description' => 'Descrição corrigida',
            'card_name' => 'CARTÃO CORRIGIDO',
            'total' => '100,00',
            'payer_id' => $newPayer->id,
            'participant_id' => $self->id,
            'payment_method_id' => $newPaymentMethod->id,
            'category_id' => $newCategory->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $installment->refresh();
        $this->assertSame('Descrição corrigida', $installment->description);
        $this->assertSame('CARTÃO CORRIGIDO', $installment->card_name);
        $this->assertSame($newPayer->id, $installment->payer_id);
        $this->assertSame($newPaymentMethod->id, $installment->payment_method_id);
        $this->assertSame($newCategory->id, $installment->category_id);

        $updatedOccurrences = $installment->occurrences()->orderBy('installment_number')->get();
        $this->assertSame(['Descrição corrigida', 'Descrição corrigida', 'Descrição corrigida'], $updatedOccurrences->pluck('description')->all());
        $this->assertSame(['CARTÃO CORRIGIDO', 'CARTÃO CORRIGIDO', 'CARTÃO CORRIGIDO'], $updatedOccurrences->pluck('card_name')->all());
        $this->assertSame([$newPayer->id, $newPayer->id, $newPayer->id], $updatedOccurrences->pluck('payer_id')->all());
        $this->assertSame([$newPaymentMethod->id, $newPaymentMethod->id, $newPaymentMethod->id], $updatedOccurrences->pluck('payment_method_id')->all());
        $this->assertSame([$newCategory->id, $newCategory->id, $newCategory->id], $updatedOccurrences->pluck('category_id')->all());
        $this->assertSame([3333, 4500, 3334], $updatedOccurrences->pluck('amount_cents')->all());
        $this->assertTrue($updatedOccurrences[1]->is_adjusted);
    }

    public function test_total_or_quantity_change_recalculates_every_active_occurrence_and_clears_adjustments(): void
    {
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $installment = $this->createInstallment($paymentMethod, '2026-10-12', 3);
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();
        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '120,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $updatedOccurrences = $installment->fresh()->occurrences()->orderBy('installment_number')->get();
        $this->assertSame([4000, 4000, 4000], $updatedOccurrences->pluck('amount_cents')->all());
        $this->assertSame([false, false, false], $updatedOccurrences->pluck('is_adjusted')->all());
    }

    public function test_date_only_rule_edit_preserves_adjusted_amounts(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();
        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '45,00'])->assertRedirect('/installments');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-11-12',
            'end_date' => '2027-01-12',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $updatedOccurrences = $installment->fresh()->occurrences()->orderBy('installment_number')->get();
        $this->assertSame(['2026-11-12', '2026-12-12', '2027-01-12'], $updatedOccurrences->pluck('purchased_at')->map(fn ($date) => $date->toDateString())->all());
        $this->assertSame([3333, 4500, 3334], $updatedOccurrences->pluck('amount_cents')->all());
        $this->assertSame([false, true, false], $updatedOccurrences->pluck('is_adjusted')->all());
    }

    public function test_rule_edit_reconciles_receipt_balances_when_participant_changes(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true, 'is_default' => false]);
        $carlos = Participant::create(['name' => 'Carlos', 'active' => true, 'is_default' => false]);
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/installments', [
            'start_date' => '2026-10-12',
            'description' => 'Compra parcelada',
            'total' => '100,00',
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $paymentMethod->id,
        ])->assertRedirect('/installments');
        $installment = Installment::query()->latest('id')->firstOrFail();

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-03', 'amount' => '40,00'])
            ->assertRedirect('/balances');

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'payer_id' => $self->id,
            'participant_id' => $carlos->id,
            'payment_method_id' => $paymentMethod->id,
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.name', 'Carlos')
            ->where('participants.0.receivableCents', 5000)
            ->where('participants.1.name', 'Maria')
            ->where('participants.1.receivableCents', 0)
            ->where('participants.1.creditCents', 4000));
    }

    public function test_complete_rule_edit_audits_rule_occurrence_and_invoice_impact(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-11-12',
            'end_date' => '2027-01-12',
            'description' => 'Descrição auditada',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $audit = AuditLog::query()->where('auditable_type', $installment->getMorphClass())->where('auditable_id', $installment->id)->latest('id')->firstOrFail();
        $this->assertSame(['start_date', 'description'], $audit->metadata['changed_fields']);
        $this->assertCount(3, $audit->metadata['old_occurrences']);
        $this->assertCount(3, $audit->metadata['new_occurrences']);
        $this->assertCount(3, $audit->metadata['invoice_impacts']);
        $this->assertTrue($audit->metadata['balances_reconciled']);
        $this->assertSame('Compra parcelada', $audit->metadata['old_rule']['description']);
        $this->assertSame('Descrição auditada', $audit->metadata['new_rule']['description']);
    }

    public function test_confirmed_schedule_edit_expands_the_schedule_and_rebalances_every_occurrence(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 2);
        $occurrenceIds = $installment->occurrences()->orderBy('installment_number')->pluck('id')->all();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-11-12',
            'end_date' => '2027-02-12',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $installment->refresh();
        $this->assertSame('2026-11-12', $installment->start_date->toDateString());
        $this->assertSame(4, $installment->installment_count);
        $this->assertSame(10000, $installment->total_cents);
        $this->assertSame($occurrenceIds, $installment->occurrences()->orderBy('installment_number')->limit(2)->pluck('id')->all());
        $this->assertSame(['2026-11-12', '2026-12-12', '2027-01-12', '2027-02-12'], $installment->occurrences()->orderBy('installment_number')->pluck('purchased_at')->map(fn ($date) => $date->toDateString())->all());
        $this->assertSame([2500, 2500, 2500, 2500], $installment->occurrences()->orderBy('installment_number')->pluck('amount_cents')->all());
        $this->assertSame(4, $installment->occurrences()->whereNull('archived_at')->count());
    }

    public function test_confirmed_schedule_edit_shortens_by_archiving_excess_without_deleting_history(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 4);
        $occurrenceIds = $installment->occurrences()->orderBy('installment_number')->pluck('id')->all();

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-11-12',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $installment->refresh();
        $this->assertSame(2, $installment->installment_count);
        $this->assertSame($occurrenceIds, $installment->occurrences()->orderBy('installment_number')->pluck('id')->all());
        $this->assertSame(2, $installment->occurrences()->whereNull('archived_at')->count());
        $this->assertSame(2, $installment->occurrences()->whereNotNull('archived_at')->count());
        $this->assertSame([5000, 5000], $installment->occurrences()->whereNull('archived_at')->orderBy('installment_number')->pluck('amount_cents')->all());
    }

    public function test_schedule_edit_preserves_total_when_only_the_installment_count_changes(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2027-01-12',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $this->assertSame(10000, $installment->fresh()->total_cents);
        $this->assertSame([2500, 2500, 2500, 2500], $installment->fresh()->occurrences()->whereNull('archived_at')->orderBy('installment_number')->pluck('amount_cents')->all());
    }

    public function test_schedule_edit_treats_a_changed_total_as_authoritative(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-12',
            'total' => '120,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $this->assertSame(12000, $installment->fresh()->total_cents);
        $this->assertSame([4000, 4000, 4000], $installment->fresh()->occurrences()->whereNull('archived_at')->orderBy('installment_number')->pluck('amount_cents')->all());
    }

    public function test_schedule_edit_requires_explicit_confirmation_and_audits_the_impact(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);

        $this->from('/installments')->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2027-01-12',
            'total' => '100,00',
        ])->assertSessionHasErrors('confirmation');

        $this->assertSame(3, $installment->fresh()->installment_count);

        $this->patch("/installments/{$installment->id}/schedule", [
            'start_date' => '2026-10-12',
            'end_date' => '2027-01-12',
            'total' => '100,00',
            'confirmation' => '1',
        ])->assertRedirect('/installments');

        $audit = AuditLog::query()->where('auditable_type', $installment->getMorphClass())->where('auditable_id', $installment->id)->latest('id')->firstOrFail();
        $this->assertSame(AuditLog::ACTION_UPDATE, $audit->action);
        $this->assertSame('schedule_reschedule', $audit->metadata['type']);
        $this->assertCount(3, $audit->metadata['old_occurrences']);
        $this->assertCount(4, $audit->metadata['new_occurrences']);
    }

    public function test_archiving_installment_keeps_past_occurrences_and_hides_future_ones(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-01', 3);

        $this->patch("/installments/{$installment->id}/archive")->assertRedirect('/installments');

        $this->assertNotNull($installment->fresh()->archived_at);
        $this->assertNull($installment->occurrences()->where('installment_number', 1)->firstOrFail()->archived_at);
        $this->assertNotNull($installment->occurrences()->where('installment_number', 2)->firstOrFail()->archived_at);
        $this->get('/purchases?month=2026-10&view=calendar')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 3333)->has('occurrences', 1));
    }

    private function createInstallment(PaymentMethod $paymentMethod, string $startDate, int $count): Installment
    {
        $this->post('/installments', [
            'start_date' => $startDate,
            'description' => 'Compra parcelada',
            'total' => '100,00',
            'installment_count' => $count,
            'payment_method_id' => $paymentMethod->id,
        ]);

        return Installment::query()->latest('id')->firstOrFail();
    }
}
