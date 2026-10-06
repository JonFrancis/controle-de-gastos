<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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

    public function test_imported_purchase_keeps_purchase_actions_even_when_its_origin_is_installment(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = Purchase::create([
            'purchased_at' => '2026-10-12',
            'description' => 'Histórico importado',
            'amount_cents' => 12500,
            'origin' => Purchase::ORIGIN_INSTALLMENT,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->get('/purchases?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchases.0.type', 'purchase')
                ->where('purchases.0.origin', Purchase::ORIGIN_INSTALLMENT)
                ->where('purchases.0.editUrl', route('purchases.edit', $purchase, false))
                ->where('purchases.0.canDelete', true)
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
                ->where('occurrences.0.type', 'occurrence')
                ->where('occurrences.0.canDelete', false)
                ->where('monthTotalCents', 23000)
            );

        $this->assertDatabaseHas('recurrence_occurrences', [
            'recurrence_id' => $recurrence->id,
            'purchased_at' => '2026-10-15 00:00:00',
        ]);
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

    public function test_invoice_view_filters_manual_purchase_by_due_month_and_exposes_the_invoice_period(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = PaymentMethod::create([
            'name' => 'Nubank',
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => 5,
        ]);
        $card->latestInvoiceSetting()->update(['due_day' => 10]);
        $this->createPurchase($self, $card, 'Compra Nubank', '2026-09-06', 1500);

        $this->get('/purchases?month=2026-10&view=invoice')
            ->assertInertia(fn (Assert $page) => $page
                ->where('monthLabel', 'Faturas com vencimento em Outubro de 2026')
                ->where('invoiceGroups.0.paymentMethod', 'Nubank')
                ->where('invoiceGroups.0.periodStart', '2026-09-06')
                ->where('invoiceGroups.0.periodEnd', '2026-10-05')
                ->where('invoiceGroups.0.closingDate', '2026-10-05')
                ->where('invoiceGroups.0.dueDate', '2026-10-10')
                ->where('invoiceGroups.0.totalCents', 1500)
                ->where('invoiceGroups.0.purchases.0.purchasedAt', '2026-09-06')
            );
    }

    public function test_invoice_view_uses_due_month_for_sofisa_and_excludes_non_credit_purchases(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = $this->createCreditCard('Sofisa', 30, 10);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $this->createPurchase($self, $card, 'Compra Sofisa', '2026-10-15', 2500);
        $this->createPurchase($self, $pix, 'Compra Pix', '2026-10-15', 9000);

        $this->get('/purchases?month=2026-10&view=invoice')
            ->assertInertia(fn (Assert $page) => $page->has('invoiceGroups', 0)->where('monthTotalCents', 0));

        $this->get('/purchases?month=2026-11&view=invoice')
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoiceGroups', 1)
                ->where('invoiceGroups.0.paymentMethod', 'Sofisa')
                ->where('invoiceGroups.0.periodStart', '2026-10-01')
                ->where('invoiceGroups.0.periodEnd', '2026-10-30')
                ->where('invoiceGroups.0.closingDate', '2026-10-30')
                ->where('invoiceGroups.0.dueDate', '2026-11-10')
                ->where('invoiceGroups.0.totalCents', 2500)
            );
    }

    public function test_invoice_view_keeps_each_card_in_a_separate_group_when_due_on_the_same_date(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $nubank = $this->createCreditCard('Nubank', 5, 10);
        $sofisa = $this->createCreditCard('Sofisa', 30, 10);
        $this->createPurchase($self, $nubank, 'Compra Nubank', '2026-09-06', 1000);
        $this->createPurchase($self, $sofisa, 'Compra Sofisa', '2026-09-15', 2000);

        $this->get('/purchases?month=2026-10&view=invoice')
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoiceGroups', 2)
                ->where('invoiceGroups.0.paymentMethod', 'Nubank')
                ->where('invoiceGroups.0.totalCents', 1000)
                ->where('invoiceGroups.1.paymentMethod', 'Sofisa')
                ->where('invoiceGroups.1.totalCents', 2000)
            );
    }

    public function test_invoice_view_uses_the_version_in_effect_on_the_purchase_date(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = $this->createCreditCard('Cartão versionado', 10, 15);
        $card->invoiceSettings()->firstOrFail()->update(['effective_from' => '2026-01-01']);
        $card->invoiceSettings()->create([
            'closing_day' => 20,
            'due_day' => 25,
            'effective_from' => '2026-10-11',
        ]);
        $this->createPurchase($self, $card, 'Compra versão anterior', '2026-10-09', 1000);
        $this->createPurchase($self, $card, 'Compra versão nova', '2026-10-15', 2000);

        $this->get('/purchases?month=2026-10&view=invoice')
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoiceGroups', 2)
                ->where('invoiceGroups.0.closingDate', '2026-10-10')
                ->where('invoiceGroups.0.dueDate', '2026-10-15')
                ->where('invoiceGroups.0.periodStart', '2026-09-11')
                ->where('invoiceGroups.0.totalCents', 1000)
                ->where('invoiceGroups.1.closingDate', '2026-10-20')
                ->where('invoiceGroups.1.dueDate', '2026-10-25')
                ->where('invoiceGroups.1.periodStart', '2026-10-11')
                ->where('invoiceGroups.1.totalCents', 2000)
            );
    }

    public function test_invoice_view_places_same_day_closing_and_due_on_the_following_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = $this->createCreditCard('Cartão com vencimento igual', 10, 10);
        $this->createPurchase($self, $card, 'Compra no fechamento', '2026-10-10', 3000);

        $this->get('/purchases?month=2026-10&view=invoice')->assertInertia(fn (Assert $page) => $page->has('invoiceGroups', 0));
        $this->get('/purchases?month=2026-11&view=invoice')->assertInertia(fn (Assert $page) => $page
            ->where('invoiceGroups.0.dueDate', '2026-11-10')
            ->where('invoiceGroups.0.totalCents', 3000));
    }

    public function test_invoice_view_moves_a_purchase_after_closing_to_the_next_invoice(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = $this->createCreditCard('Cartão principal', 5, 10);
        $this->createPurchase($self, $card, 'Compra no fechamento', '2026-10-05', 1000);
        $this->createPurchase($self, $card, 'Compra depois do fechamento', '2026-10-06', 2000);

        $this->get('/purchases?month=2026-10&view=invoice')->assertInertia(fn (Assert $page) => $page
            ->where('invoiceGroups.0.totalCents', 1000)
            ->where('invoiceGroups.0.purchases.0.purchasedAt', '2026-10-05'));
        $this->get('/purchases?month=2026-11&view=invoice')->assertInertia(fn (Assert $page) => $page
            ->where('invoiceGroups.0.totalCents', 2000)
            ->where('invoiceGroups.0.purchases.0.purchasedAt', '2026-10-06'));
    }

    public function test_invoice_view_limits_closing_and_due_days_to_the_last_day_of_the_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $card = $this->createCreditCard('Cartão com dia 31', 31, 31);
        $this->createPurchase($self, $card, 'Compra de fevereiro', '2026-02-28', 4000);

        $this->get('/purchases?month=2026-03&view=invoice')->assertInertia(fn (Assert $page) => $page
            ->where('invoiceGroups.0.periodStart', '2026-02-01')
            ->where('invoiceGroups.0.periodEnd', '2026-02-28')
            ->where('invoiceGroups.0.closingDate', '2026-02-28')
            ->where('invoiceGroups.0.dueDate', '2026-03-31')
            ->where('invoiceGroups.0.totalCents', 4000));
    }

    public function test_purchase_edit_link_uses_the_named_route(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = $this->createPurchase($self, $paymentMethod, 'Compra editável', '2026-10-12', 10000);

        $this->get('/purchases?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('purchases.0.editUrl', route('purchases.edit', $purchase, false)));
    }

    public function test_purchase_can_be_updated_and_audit_the_change(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = $this->createPurchase($self, $paymentMethod, 'Compra editável', '2026-10-12', 10000);

        $this->patch(route('purchases.update', $purchase, false), [
            'purchased_at' => '2026-10-13',
            'description' => 'Compra atualizada',
            'amount' => '110,00',
            'payer_id' => $self->id,
            'payment_method_id' => $paymentMethod->id,
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'description' => 'Compra atualizada', 'amount_cents' => 11000]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => Purchase::class,
            'auditable_id' => $purchase->id,
        ]);
    }

    public function test_purchase_can_be_deleted_and_audit_the_deletion(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $paymentMethod = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = $this->createPurchase($self, $paymentMethod, 'Compra excluível', '2026-10-12', 10000);

        $this->delete(route('purchases.destroy', $purchase, false))->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_DELETE,
            'auditable_type' => Purchase::class,
            'auditable_id' => $purchase->id,
        ]);
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

    private function createCreditCard(string $name, int $closingDay, int $dueDay): PaymentMethod
    {
        $card = PaymentMethod::create(['name' => $name, 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => $closingDay]);
        $card->latestInvoiceSetting()->update(['due_day' => $dueDay, 'effective_from' => '2026-01-01']);

        return $card->fresh(['invoiceSettings']);
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
