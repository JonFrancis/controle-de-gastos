<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\Recurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonthlyAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_analysis_reconciles_people_totals_categories_origins_and_card_name(): void
    {
        [$self, $maria, $pix, $casa] = $this->catalogs();
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Mercado do mês', 'card_name' => 'SUPERMERCADO REAL', 'amount_cents' => 10000, 'payer_id' => $self->id, 'payment_method_id' => $pix->id]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $self->id, 'category_id' => $casa->id, 'amount_cents' => 3000]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 7000]);

        $this->get('/analysis?month=2026-10&view=calendar')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analysis/Index')
                ->where('view', 'calendar')
                ->where('summary.ownConsumptionCents', 3000)
                ->where('summary.paidForOthersCents', 7000)
                ->where('summary.totalDisbursedCents', 10000)
                ->where('categories.0.name', 'Casa')
                ->where('categories.0.amountCents', 3000)
                ->where('paymentMethods.0.name', 'Pix')
                ->where('origins.manual.amountCents', 10000)
                ->where('participants.0.name', 'Maria')
                ->where('participants.0.grossCents', 7000)
                ->where('participants.0.finalCents', 7000)
                ->where('participants.0.items.0.description', 'Mercado do mês')
                ->where('participants.0.items.0.finalCents', 7000)
                ->where('participants.0.message', "Olá, Maria!\n\nSegue o resumo das suas compras no período:\n- Mercado do mês (12/10/2026): R$ 70,00\n\nTotal bruto: R$ 70,00\nAbatimentos: R$ 0,00\nValor líquido: R$ 70,00")
                ->where('fullMessage', "Cobranças do período:\n\nMaria:\n- Mercado do mês (12/10/2026): R$ 70,00\nTotal bruto: R$ 70,00\nAbatimentos: R$ 0,00\nValor líquido: R$ 70,00")
                ->where('purchaseReview.0.description', 'Mercado do mês')
                ->where('purchaseReview.0.cardName', 'SUPERMERCADO REAL'));
    }

    public function test_invoice_analysis_uses_the_card_closing_cycle(): void
    {
        [$self, , , , $card] = $this->catalogs();
        $this->purchaseWithoutAllocation('2026-10-09', 1000, $self, $card, 'Antes do fechamento');
        $this->purchaseWithoutAllocation('2026-10-11', 2000, $self, $card, 'Depois do fechamento');

        $this->get('/analysis?month=2026-10&view=invoice')->assertInertia(fn (Assert $page) => $page->where('view', 'invoice')->where('summary.totalDisbursedCents', 1000)->has('purchaseReview', 1)->where('purchaseReview.0.description', 'Antes do fechamento'));
    }

    public function test_missing_payment_method_is_reported_as_pending_without_being_charged(): void
    {
        [$self] = $this->catalogs();
        $this->purchaseWithoutAllocation('2026-10-12', 1000, $self, null, 'Sem forma');

        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('pendingReview', 1)->where('summary.ownConsumptionCents', 0)->where('summary.totalDisbursedCents', 0)->where('participants', []));
    }

    public function test_abatement_is_applied_only_to_a_debt_in_the_analyzed_period(): void
    {
        [$self, $maria, $pix] = $this->catalogs();
        $oldPurchase = Purchase::create(['purchased_at' => '2026-09-12', 'description' => 'Dívida antiga', 'amount_cents' => 4000, 'payer_id' => $self->id, 'payment_method_id' => $pix->id]);
        $oldAllocation = PurchaseAllocation::create(['purchase_id' => $oldPurchase->id, 'participant_id' => $maria->id, 'amount_cents' => 4000]);
        $currentPurchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Dívida atual', 'amount_cents' => 7000, 'payer_id' => $self->id, 'payment_method_id' => $pix->id]);
        $currentAllocation = PurchaseAllocation::create(['purchase_id' => $currentPurchase->id, 'participant_id' => $maria->id, 'amount_cents' => 7000]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-20', 'amount_cents' => 8000]);
        ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $oldAllocation->id, 'purchase_allocation_id' => $oldAllocation->id, 'amount_cents' => 4000, 'source' => 'manual']);
        ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $currentAllocation->id, 'purchase_allocation_id' => $currentAllocation->id, 'amount_cents' => 4000, 'source' => 'manual']);

        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('participants.0.grossCents', 7000)->where('participants.0.abatementsCents', 4000)->where('participants.0.finalCents', 3000));
    }

    public function test_quitado_participant_is_flagged_without_a_charge_message(): void
    {
        [$self, $maria, $pix] = $this->catalogs();
        $purchase = Purchase::create(['purchased_at' => '2026-10-12', 'description' => 'Compra quitada', 'amount_cents' => 4000, 'payer_id' => $self->id, 'payment_method_id' => $pix->id]);
        $allocation = PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 4000]);
        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-20', 'amount_cents' => 4000]);
        ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $allocation->id, 'purchase_allocation_id' => $allocation->id, 'amount_cents' => 4000, 'source' => 'manual']);

        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.status', 'settled')
            ->where('participants.0.finalCents', 0)
            ->where('participants.0.message', "Olá, Maria!\n\nSua conta está quitada neste período. Nenhuma cobrança a enviar.")
            ->where('fullMessage', "Nenhuma cobrança a enviar neste período.\n\nQuitados ou sem cobrança: Maria."));
    }

    public function test_monthly_salary_is_saved_and_remaining_amount_is_calculated_after_own_consumption(): void
    {
        [$self, , $pix] = $this->catalogs();
        $this->purchaseWithoutAllocation('2026-10-12', 3000, $self, $pix, 'Meu gasto');

        $this->patch('/settings/salary?month=2026-10&view=calendar', ['amount' => '500,00'])->assertRedirect('/analysis?month=2026-10&view=calendar');

        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('summary.salaryCents', 50000)->where('summary.salaryRemainingCents', 47000));
    }

    public function test_calendar_analysis_materializes_recurring_occurrences_for_the_selected_month(): void
    {
        [$self, , $pix] = $this->catalogs();
        Recurrence::create([
            'start_date' => '2026-10-01',
            'day_of_month' => 15,
            'description' => 'Assinatura mensal',
            'amount_cents' => 2500,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
            'active' => true,
        ]);

        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('origins.recurrence.amountCents', 2500)
            ->where('origins.recurrence.count', 1)
            ->where('purchaseReview.0.description', 'Assinatura mensal'));
    }

    public function test_invoice_analysis_materializes_recurring_occurrences_for_the_selected_invoice(): void
    {
        [$self, , , , $card] = $this->catalogs();
        Recurrence::create([
            'start_date' => '2026-11-01',
            'day_of_month' => 9,
            'description' => 'Serviço na fatura',
            'card_name' => 'SERVICO REAL',
            'amount_cents' => 4200,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $card->id,
            'active' => true,
        ]);

        $this->get('/analysis?month=2026-11&view=invoice')->assertInertia(fn (Assert $page) => $page
            ->where('origins.recurrence.amountCents', 4200)
            ->where('origins.recurrence.count', 1)
            ->where('purchaseReview.0.cardName', 'SERVICO REAL'));
    }

    /** @return array{0: Participant, 1: Participant, 2: PaymentMethod, 3: Category, 4: PaymentMethod} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $card = PaymentMethod::create(['name' => 'Cartão', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);
        $casa = Category::create(['name' => 'Casa']);

        return [$self, $maria, $pix, $casa, $card];
    }

    private function purchaseWithoutAllocation(string $date, int $amountCents, Participant $self, ?PaymentMethod $method, string $description): void
    {
        Purchase::create(['purchased_at' => $date, 'description' => $description, 'amount_cents' => $amountCents, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $method?->id]);
    }
}
