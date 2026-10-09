<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
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
