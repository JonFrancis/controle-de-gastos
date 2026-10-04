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

    public function test_new_purchase_flow_starts_with_type_selector_and_simple_form_has_its_own_route(): void
    {
        $this->get('/purchases/create')->assertInertia(fn (Assert $page) => $page->component('Purchases/TypeSelector'));
        $this->get('/purchases/create/simple')->assertInertia(fn (Assert $page) => $page->component('Purchases/Form'));
    }

    public function test_purchase_can_be_created_with_amount_stored_in_cents(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();

        $this->post('/purchases', ['purchased_at' => '2026-10-12', 'description' => 'Mercado', 'card_name' => 'SUPERMERCADO TESTE', 'amount' => '123,45', 'payer_id' => $payer->id, 'payment_method_id' => $method->id])->assertRedirect();
        $this->assertDatabaseHas('purchases', ['description' => 'Mercado', 'card_name' => 'SUPERMERCADO TESTE', 'amount_cents' => 12345, 'archived_at' => null]);
    }

    public function test_purchases_page_filters_active_purchases_by_month_and_total(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();
        Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Outubro', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);
        Purchase::create(['purchased_at' => '2026-09-12', 'description' => 'Setembro', 'amount_cents' => 20000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);

        $this->get('/purchases?month=2026-10')->assertInertia(fn (Assert $page) => $page->component('Purchases/Index')->where('selectedMonth', '2026-10')->where('monthTotalCents', 10000)->has('purchases', 1)->where('purchases.0.description', 'Outubro'));
    }

    public function test_purchase_can_be_edited_archived_and_restored_without_deletion(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Mercado', 'card_name' => 'MERCADO', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);

        $this->patch("/purchases/{$purchase->id}", ['purchased_at' => '2026-10-13', 'description' => 'Mercado atualizado', 'card_name' => 'MERCADO NOVO', 'amount' => '110,00', 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id])->assertRedirect('/');
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Mercado atualizado', 'card_name' => 'MERCADO NOVO', 'amount_cents' => 11000]);

        $this->patch("/purchases/{$purchase->id}/archive")->assertRedirect('/');
        $this->assertNotNull($purchase->fresh()->archived_at);
        $this->get('/purchases?month=2026-10')->assertInertia(fn (Assert $page) => $page->has('purchases', 0)->where('monthTotalCents', 0));

        $this->patch("/purchases/{$purchase->id}/restore")->assertRedirect('/');
        $this->assertNull($purchase->fresh()->archived_at);
    }

    public function test_purchase_can_be_deleted_permanently_with_its_division(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Compra para excluir', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'participant_id' => $participant->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);
        $purchase->allocations()->create(['participant_id' => $participant->id, 'amount_cents' => 10000]);

        $this->delete("/purchases/{$purchase->id}")
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Compra excluída com sucesso.');

        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('purchase_allocations', ['purchase_id' => $purchase->id]);
    }

    public function test_purchase_creation_opens_division_and_division_selects_participants_and_category(): void
    {
        [$payer, $participant, $method, $category] = $this->catalogs();

        $response = $this->post('/purchases', ['purchased_at' => '2026-10-14', 'description' => 'Compra minha', 'card_name' => 'LOJA', 'amount' => '25,00', 'payer_id' => $payer->id, 'payment_method_id' => $method->id]);
        $purchase = Purchase::query()->where('description', 'Compra minha')->firstOrFail();
        $response->assertRedirect("/purchases/{$purchase->id}/allocations/edit");

        $this->assertDatabaseHas('purchases', ['description' => 'Compra minha', 'payer_id' => $payer->id, 'participant_id' => null, 'category_id' => null]);
        $this->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'amount', 'allocations' => [['participant_id' => $payer->id, 'category_id' => $category->id, 'amount' => '25,00']]])->assertRedirect('/');
        $this->assertDatabaseHas('purchase_allocations', ['purchase_id' => $purchase->id, 'participant_id' => $payer->id, 'category_id' => $category->id, 'amount_cents' => 2500]);

        $this->assertDatabaseMissing('purchase_allocations', ['purchase_id' => $purchase->id, 'participant_id' => $participant->id, 'category_id' => $category->id]);
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod, 3: Category} */
    private function catalogs(): array
    {
        $payer = Participant::query()->where('is_default', true)->firstOrFail();
        $participant = Participant::create(['name' => 'Maria']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $category = Category::create(['name' => 'Casa']);

        return [$payer, $participant, $method, $category];
    }
}
