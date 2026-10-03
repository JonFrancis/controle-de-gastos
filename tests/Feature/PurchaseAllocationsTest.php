<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_page_loads_and_equal_mode_splits_in_cents(): void
    {
        [$purchase, $maria, $joao] = $this->purchase();

        $this->get("/purchases/{$purchase->id}/allocations/edit")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->component('Purchases/Allocations'));

        $this->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'equal', 'allocations' => [['participant_id' => $purchase->payer_id, 'amount' => ''], ['participant_id' => $maria->id, 'amount' => ''], ['participant_id' => $joao->id, 'amount' => '']]])->assertRedirect("/purchases/{$purchase->id}/allocations/edit");

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'allocation_mode' => 'equal']);
        $this->assertSame([3334, 3333, 3333], PurchaseAllocation::where('purchase_id', $purchase->id)->orderBy('id')->pluck('amount_cents')->all());
    }

    public function test_amount_and_percentage_modes_require_exact_totals(): void
    {
        [$purchase, $maria] = $this->purchase();

        $this->from("/purchases/{$purchase->id}/allocations/edit")->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'amount', 'allocations' => [['participant_id' => $purchase->payer_id, 'amount' => '50,00'], ['participant_id' => $maria->id, 'amount' => '49,99']]])->assertRedirect("/purchases/{$purchase->id}/allocations/edit")->assertSessionHasErrors('allocations');
        $this->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'amount', 'allocations' => [['participant_id' => $purchase->payer_id, 'amount' => '50,00'], ['participant_id' => $maria->id, 'amount' => '50,00']]])->assertRedirect("/purchases/{$purchase->id}/allocations/edit");
        $this->assertSame('amount', $purchase->fresh()->allocation_mode);

        $this->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'percentage', 'allocations' => [['participant_id' => $purchase->payer_id, 'percentage' => '33,33'], ['participant_id' => $maria->id, 'percentage' => '66,67']]])->assertRedirect("/purchases/{$purchase->id}/allocations/edit");
        $this->assertSame(10000, PurchaseAllocation::where('purchase_id', $purchase->id)->sum('percentage_basis_points'));
        $this->assertSame(10000, PurchaseAllocation::where('purchase_id', $purchase->id)->sum('amount_cents'));
    }

    public function test_payer_stays_separate_from_allocation_participants(): void
    {
        [$purchase, $maria] = $this->purchase();

        $this->put("/purchases/{$purchase->id}/allocations", ['allocation_mode' => 'amount', 'allocations' => [['participant_id' => $maria->id, 'amount' => '100,00']]])->assertRedirect();

        $this->assertSame($purchase->payer_id, $purchase->fresh()->payer_id);
        $this->assertDatabaseHas('purchase_allocations', ['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 10000]);
    }

    /** @return array{0: Purchase, 1: Participant, 2: Participant} */
    private function purchase(): array
    {
        $payer = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $joao = Participant::create(['name' => 'João']);
        $method = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $category = Category::create(['name' => 'Casa']);
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Mercado', 'amount_cents' => 10000, 'payer_id' => $payer->id, 'payment_method_id' => $method->id, 'category_id' => $category->id]);

        return [$purchase, $maria, $joao];
    }
}
