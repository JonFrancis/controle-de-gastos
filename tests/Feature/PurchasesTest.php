<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_can_be_created_with_amount_stored_in_cents(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();

        $this->post('/purchases', ['purchased_at' => '2026-10-12', 'description' => 'Mercado', 'card_name' => 'SUPERMERCADO TESTE', 'amount' => '123,45', 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id])->assertRedirect('/');
        $this->assertDatabaseHas('purchases', ['description' => 'Mercado', 'card_name' => 'SUPERMERCADO TESTE', 'amount_cents' => 12345, 'archived_at' => null]);
    }

    public function test_dashboard_filters_active_purchases_by_month_and_total(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();
        Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Outubro', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);
        Purchase::create(['purchased_at' => '2026-09-12', 'description' => 'Setembro', 'amount_cents' => 20000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);

        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('selectedMonth', '2026-10')->where('monthTotalCents', 10000)->has('purchases', 1)->where('purchases.0.description', 'Outubro'));
    }

    public function test_purchase_can_be_edited_archived_and_restored_without_deletion(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Mercado', 'card_name' => 'MERCADO', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);

        $this->patch("/purchases/{$purchase->id}", ['purchased_at' => '2026-10-13', 'description' => 'Mercado atualizado', 'card_name' => 'MERCADO NOVO', 'amount' => '110,00', 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id])->assertRedirect('/');
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Mercado atualizado', 'card_name' => 'MERCADO NOVO', 'amount_cents' => 11000]);

        $this->patch("/purchases/{$purchase->id}/archive")->assertRedirect('/');
        $this->assertNotNull($purchase->fresh()->archived_at);
        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->has('purchases', 0)->where('monthTotalCents', 0));

        $this->patch("/purchases/{$purchase->id}/restore")->assertRedirect('/');
        $this->assertNull($purchase->fresh()->archived_at);
    }

    public function test_missing_payer_or_participant_represents_the_user_without_self_catalog_entry(): void
    {
        [, $participant, $method, $category] = $this->catalogs();

        $this->post('/purchases', ['purchased_at' => '2026-10-14', 'description' => 'Compra minha', 'card_name' => 'LOJA', 'amount' => '25,00', 'payer_id' => '', 'participant_id' => '', 'payment_method_id' => $method->id, 'category_id' => $category->id])->assertRedirect('/');
        $this->post('/purchases', ['purchased_at' => '2026-10-15', 'description' => 'Paguei para Maria', 'card_name' => 'LOJA', 'amount' => '30,00', 'payer_id' => '', 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id])->assertRedirect('/');

        $this->assertDatabaseHas('purchases', ['description' => 'Compra minha', 'payer_id' => null, 'participant_id' => null, 'category_id' => $category->id]);
        $this->assertDatabaseHas('purchases', ['description' => 'Paguei para Maria', 'payer_id' => null, 'participant_id' => $participant->id, 'category_id' => null]);
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod, 3: Category} */
    private function catalogs(): array
    {
        $payer = Participant::create(['name' => 'Eu']);
        $participant = Participant::create(['name' => 'Maria']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $category = Category::create(['name' => 'Casa']);

        return [$payer, $participant, $method, $category];
    }
}
