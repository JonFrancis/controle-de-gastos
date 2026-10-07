<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentOccurrence;
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
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installments/Edit')
                ->where('installment.startDate', '2026-10-12')
                ->where('installment.endDate', '2026-12-12')
                ->where('installment.totalCents', 10000));

        $this->get("/installment-occurrences/{$occurrences[1]->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('Installments/OccurrenceForm'));
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
