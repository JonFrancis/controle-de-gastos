<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_starts_as_a_navigable_inertia_page(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('monthLabel', 'Outubro de 2026')
                ->where('pendingReview', 0)
                ->has('summary')
                ->has('catalogs.participants')
                ->has('catalogs.categories')
                ->has('catalogs.paymentMethods')
            );
    }

    public function test_invoice_view_groups_credit_purchases_by_the_card_closing_cycle(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = PaymentMethod::create(['name' => 'Cartão principal', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);
        $debit = PaymentMethod::create(['name' => 'Débito', 'type' => PaymentMethod::TYPE_DEBIT]);

        $this->purchase('Compra anterior', '2026-09-11', $card, 1000, 'MERCADO CARTAO', $self);
        $this->purchase('Compra antes', '2026-10-09', $card, 1000, 'MERCADO CARTAO', $self);
        $this->purchase('Compra no dia', '2026-10-10', $card, 1000, 'MERCADO CARTAO', $self);
        $this->purchase('Compra depois', '2026-10-11', $card, 1000, 'MERCADO CARTAO', $self);
        $this->purchase('Compra no débito', '2026-10-05', $debit, 9000, null, $self);

        $this->get('/?view=invoice&month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('view', 'invoice')
                ->where('monthTotalCents', 3000)
                ->has('purchases', 0)
                ->has('invoiceGroups', 1)
                ->where('invoiceGroups.0.closingDate', '2026-10-10')
                ->where('invoiceGroups.0.totalCents', 3000)
                ->has('invoiceGroups.0.purchases', 3)
                ->where('invoiceGroups.0.purchases.0.cardName', 'MERCADO CARTAO')
            );
    }

    private function purchase(string $description, string $date, PaymentMethod $method, int $amount, ?string $cardName, Participant $self): void
    {
        Purchase::create([
            'purchased_at' => $date,
            'description' => $description,
            'card_name' => $cardName,
            'amount_cents' => $amount,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $method->id,
        ]);
    }
}
