<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecurrencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurrences_list_and_create_screens_are_separate(): void
    {
        $this->get('/recurrences')->assertInertia(fn (Assert $page) => $page->component('Recurrences/Index')->has('recurrences'));

        $this->get('/recurrences/create')->assertInertia(fn (Assert $page) => $page->component('Recurrences/Create')->has('participants')->has('paymentMethods'));
    }

    public function test_recurrence_creation_generates_monthly_occurrences_without_duplicates(): void
    {
        $category = Category::create(['name' => 'Casa']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $this->post('/recurrences', [
            'start_date' => '2026-01-31',
            'end_date' => '2026-03-31',
            'day_of_month' => 31,
            'description' => 'Aluguel',
            'card_name' => 'ALUGUEL',
            'amount' => '100,00',
            'payment_method_id' => $method->id,
            'category_id' => $category->id,
        ])->assertRedirect('/recurrences');

        $recurrence = Recurrence::query()->firstOrFail();

        $this->assertDatabaseHas('recurrences', [
            'id' => $recurrence->id,
            'amount_cents' => 10000,
            'day_of_month' => 31,
            'active' => true,
            'category_id' => $category->id,
        ]);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $recurrence->occurrences()->orderBy('purchased_at')->pluck('purchased_at')->map(fn ($date) => $date->toDateString())->all());

        $this->get('/?month=2026-02')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 10000)->has('occurrences', 1)->where('occurrences.0.origin', 'recurrence'));

        $this->assertSame(3, $recurrence->fresh()->occurrences()->count());
    }

    public function test_credit_recurrence_appears_in_the_correct_invoice_with_card_name(): void
    {
        $card = PaymentMethod::create(['name' => 'Cartão', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);

        $this->createRecurrence($card, '2026-11-09', '200,00', null);

        $this->get('/?view=invoice&month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('monthTotalCents', 20000)
            ->has('invoiceGroups', 1)
            ->where('invoiceGroups.0.purchases.0.origin', 'recurrence')
            ->where('invoiceGroups.0.purchases.0.cardName', 'SERVICO MENSAL')
        );
    }

    public function test_recurrence_occurrence_can_be_adjusted_without_changing_the_rule(): void
    {
        $recurrence = $this->createRecurrence(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', '100,00', '2026-12-12');
        $occurrence = $recurrence->occurrences()->whereDate('purchased_at', '2026-11-12')->firstOrFail();

        $this->patch("/recurrence-occurrences/{$occurrence->id}", ['amount' => '120,00'])->assertRedirect('/recurrences');

        $this->assertDatabaseHas('recurrence_occurrences', ['id' => $occurrence->id, 'amount_cents' => 12000, 'is_adjusted' => true]);
        $this->assertDatabaseHas('recurrences', ['id' => $recurrence->id, 'amount_cents' => 10000]);
        $this->assertDatabaseHas('recurrence_occurrences', ['recurrence_id' => $recurrence->id, 'amount_cents' => 10000, 'is_adjusted' => false]);
    }

    public function test_deactivating_a_recurrence_preserves_past_occurrences_and_hides_future_ones(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $recurrence = $this->createRecurrence(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-01', '50,00', null);

        $this->patch("/recurrences/{$recurrence->id}/deactivate")->assertRedirect('/recurrences');

        $this->assertDatabaseHas('recurrences', ['id' => $recurrence->id, 'active' => false]);
        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('occurrences', 1));
        $this->get('/?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 0)->has('occurrences', 0));

        $this->patch("/recurrences/{$recurrence->id}/activate")->assertRedirect('/recurrences');

        $this->assertDatabaseHas('recurrences', ['id' => $recurrence->id, 'active' => true]);
        $this->get('/?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('occurrences', 1));
    }

    public function test_recurrence_can_be_ended_by_date_without_removing_its_history(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $recurrence = $this->createRecurrence(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-01', '50,00', null);

        $this->patch("/recurrences/{$recurrence->id}", ['end_date' => '2026-11-30', 'active' => true])->assertRedirect('/recurrences');

        $this->assertSame('2026-11-30', $recurrence->fresh()->end_date->toDateString());
        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('occurrences', 1));
        $this->get('/?month=2026-12')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 0)->has('occurrences', 0));
        $this->assertSame(2, $recurrence->fresh()->occurrences()->whereNull('archived_at')->count());
    }

    private function createRecurrence(PaymentMethod $paymentMethod, string $startDate, string $amount, ?string $endDate): Recurrence
    {
        $this->post('/recurrences', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'day_of_month' => (int) substr($startDate, -2),
            'description' => 'Serviço mensal',
            'card_name' => 'SERVICO MENSAL',
            'amount' => $amount,
            'payment_method_id' => $paymentMethod->id,
        ]);

        return Recurrence::query()->latest('id')->firstOrFail();
    }
}
