<?php

namespace Tests\Feature;

use App\Models\Installment;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Recurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchases_page_renders_active_purchases_for_the_selected_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        Purchase::create([
            'purchased_at' => '2026-10-12',
            'description' => 'Compra de outubro',
            'amount_cents' => 12500,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        Purchase::create([
            'purchased_at' => '2026-09-12',
            'description' => 'Compra de setembro',
            'amount_cents' => 5000,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->get('/purchases?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->where('selectedMonth', '2026-10')
                ->where('view', 'calendar')
                ->has('purchases', 1)
                ->where('purchases.0.description', 'Compra de outubro')
                ->where('monthTotalCents', 12500)
            );
    }

    public function test_purchases_page_includes_active_installment_and_recurrence_occurrences_for_the_selected_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $recurrence = Recurrence::create([
            'start_date' => '2026-09-01',
            'day_of_month' => 15,
            'description' => 'Academia recorrente',
            'amount_cents' => 8000,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
            'active' => true,
        ]);
        $installment = $this->createInstallment($self, $paymentMethod);
        $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-10-08',
            'description' => 'Notebook parcelado',
            'amount_cents' => 15000,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->get('/purchases?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->has('purchases', 0)
                ->has('occurrences', 2)
                ->where('occurrences.0.description', 'Academia recorrente')
                ->where('occurrences.1.description', 'Notebook parcelado')
                ->where('monthTotalCents', 23000)
            );

        $this->assertNotNull($recurrence->occurrences()->whereDate('purchased_at', '2026-10-15')->first());
    }

    public function test_invoice_view_groups_credit_items_by_closing_cycle_and_keeps_original_purchase_date(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = PaymentMethod::create(['name' => 'Cartão principal', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);
        $this->createPurchase($self, $card, 'Compra anterior', '2026-09-11', 1000);
        $this->createPurchase($self, $card, 'Compra no fechamento', '2026-10-10', 2000);
        $this->createPurchase($self, $card, 'Compra próxima fatura', '2026-10-11', 4000);

        $this->get('/purchases?month=2026-10&view=invoice')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->where('view', 'invoice')
                ->has('purchases', 0)
                ->has('invoiceGroups', 1)
                ->where('invoiceGroups.0.paymentMethod', 'Cartão principal')
                ->where('invoiceGroups.0.closingDate', '2026-10-10')
                ->where('invoiceGroups.0.totalCents', 3000)
                ->has('invoiceGroups.0.purchases', 2)
                ->where('invoiceGroups.0.purchases.0.purchasedAt', '2026-10-10')
                ->where('invoiceGroups.0.purchases.1.purchasedAt', '2026-09-11')
            );
    }

    public function test_purchase_can_be_edited_and_deleted_from_the_purchases_page(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = $this->createPurchase($self, $paymentMethod, 'Compra editável', '2026-10-12', 10000);

        $this->get('/purchases?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('purchases.0.editUrl', "/purchases/{$purchase->id}/edit"));

        $this->patch("/purchases/{$purchase->id}", [
            'purchased_at' => '2026-10-13',
            'description' => 'Compra atualizada',
            'amount' => '110,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ])->assertRedirect('/');
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Compra atualizada', 'amount_cents' => 11000]);

        $this->delete("/purchases/{$purchase->id}")->assertRedirect('/');
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
    }

    private function createPurchase(Participant $self, PaymentMethod $paymentMethod, string $description, string $date, int $amount): Purchase
    {
        return Purchase::create([
            'purchased_at' => $date,
            'description' => $description,
            'amount_cents' => $amount,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
    }

    private function createInstallment(Participant $self, PaymentMethod $paymentMethod): Installment
    {
        return Installment::create([
            'start_date' => '2026-10-01',
            'description' => 'Notebook parcelado',
            'total_cents' => 30000,
            'installment_count' => 2,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
    }
}
