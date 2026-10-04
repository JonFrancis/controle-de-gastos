<?php

namespace Tests\Feature;

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

        $this->get('/purchases?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('occurrences', 1)->where('occurrences.0.description', 'Compra parcelada'));
        $this->get('/purchases?view=invoice&month=2026-11')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 5000)->has('invoiceGroups', 1)->has('invoiceGroups.0.purchases', 1)->where('invoiceGroups.0.purchases.0.origin', 'installment'));
    }

    public function test_installment_occurrence_can_be_adjusted_without_changing_other_occurrences(): void
    {
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-12', 3);
        $occurrences = $installment->occurrences()->orderBy('installment_number')->get();

        $this->patch("/installment-occurrences/{$occurrences[1]->id}", ['amount' => '40,00'])->assertRedirect('/installments');

        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[1]->id, 'amount_cents' => 4000, 'is_adjusted' => true]);
        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[0]->id, 'amount_cents' => 3333]);
        $this->assertDatabaseHas('installment_occurrences', ['id' => $occurrences[2]->id, 'amount_cents' => 3334]);
    }

    public function test_archiving_installment_keeps_past_occurrences_and_hides_future_ones(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $installment = $this->createInstallment(PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]), '2026-10-01', 3);

        $this->patch("/installments/{$installment->id}/archive")->assertRedirect('/installments');

        $this->assertNotNull($installment->fresh()->archived_at);
        $this->assertNull($installment->occurrences()->where('installment_number', 1)->firstOrFail()->archived_at);
        $this->assertNotNull($installment->occurrences()->where('installment_number', 2)->firstOrFail()->archived_at);
        $this->get('/purchases?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('monthTotalCents', 3333)->has('occurrences', 1));
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
