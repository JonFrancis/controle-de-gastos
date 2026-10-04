<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Installment;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Recurrence;
use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
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
                ->where('pendingReviewUrl', null)
                ->has('summary')
                ->where('charts.paymentMethodTotals', [])
                ->where('charts.movement', [])
                ->where('charts.categories', [])
                ->has('catalogs.participants')
                ->has('catalogs.categories')
                ->has('catalogs.paymentMethods')
            );
    }

    public function test_dashboard_exposes_a_direct_review_link_only_when_import_rows_are_pending(): void
    {
        $import = SpreadsheetImport::factory()->create();
        SpreadsheetImportRow::factory()->create(['spreadsheet_import_id' => $import->id, 'status' => 'pending_review']);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingReview', 1)
                ->where('pendingReviewUrl', route('imports.review', $import, false)));
    }

    public function test_dashboard_exposes_real_payment_totals_and_own_weekly_movement_with_category_filter(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $other = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $credit = PaymentMethod::create(['name' => 'Cartão', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10]);
        $food = Category::create(['name' => 'Alimentação']);
        $transport = Category::create(['name' => 'Transporte']);
        $otherCategory = Category::create(['name' => 'Categoria de outra pessoa']);

        $otherPurchase = Purchase::create([
            'purchased_at' => '2026-10-03',
            'description' => 'Compra de Maria',
            'amount_cents' => 10000,
            'payer_id' => $other->id,
            'participant_id' => $other->id,
            'payment_method_id' => $pix->id,
            'category_id' => $otherCategory->id,
        ]);
        $sharedPurchase = Purchase::create([
            'purchased_at' => '2026-10-10',
            'description' => 'Compra compartilhada',
            'amount_cents' => 12000,
            'payer_id' => $self->id,
            'payment_method_id' => $credit->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $self->id, 'category_id' => $food->id, 'amount_cents' => 3000]);
        PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $other->id, 'category_id' => $otherCategory->id, 'amount_cents' => 9000]);
        Purchase::create([
            'purchased_at' => '2026-10-18',
            'description' => 'Transporte próprio',
            'amount_cents' => 2000,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
            'category_id' => $transport->id,
        ]);
        Purchase::create([
            'purchased_at' => '2026-09-30',
            'description' => 'Fora do mês selecionado',
            'amount_cents' => 99999,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
            'category_id' => $food->id,
        ]);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('charts.paymentMethodTotals', [
                    ['name' => 'Pix', 'amountCents' => 12000],
                    ['name' => 'Cartão', 'amountCents' => 12000],
                ])
                ->where('charts.movement', [
                    ['week' => 2, 'label' => 'Semana 2', 'amountCents' => 3000],
                    ['week' => 3, 'label' => 'Semana 3', 'amountCents' => 2000],
                ])
                ->where('charts.categories', [
                    ['id' => $food->id, 'name' => 'Alimentação'],
                    ['id' => $transport->id, 'name' => 'Transporte'],
                ])
                ->where('charts.selectedCategoryId', null)
            );

        $this->get('/?month=2026-10&category='.$food->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('charts.movement', [
                    ['week' => 2, 'label' => 'Semana 2', 'amountCents' => 3000],
                ])
                ->where('charts.selectedCategoryId', $food->id)
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

    public function test_dashboard_calculates_own_consumption_for_selected_month_across_active_sources(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $other = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $sharedPurchase = Purchase::create([
            'purchased_at' => '2026-10-05',
            'description' => 'Compra compartilhada',
            'amount_cents' => 10000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $self->id, 'amount_cents' => 3000]);
        PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $other->id, 'amount_cents' => 7000]);

        $recurrence = Recurrence::create([
            'start_date' => '2026-10-10',
            'day_of_month' => 10,
            'description' => 'Assinatura',
            'amount_cents' => 2000,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
            'active' => true,
        ]);

        $installment = Installment::create([
            'start_date' => '2026-10-12',
            'description' => 'Compra parcelada',
            'total_cents' => 9000,
            'installment_count' => 3,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        InstallmentOccurrence::create([
            'installment_id' => $installment->id,
            'installment_number' => 1,
            'purchased_at' => '2026-10-12',
            'description' => 'Compra parcelada',
            'amount_cents' => 3000,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        Purchase::create([
            'purchased_at' => '2026-09-30',
            'description' => 'Fora do mês',
            'amount_cents' => 9999,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMonth', '2026-10')
                ->where('summary.ownConsumptionCents', 8000)
                ->where('summary.salaryCents', null)
                ->where('summary.salaryRemainingCents', null)
            );

        $this->assertDatabaseHas('recurrence_occurrences', [
            'recurrence_id' => $recurrence->id,
            'purchased_at' => '2026-10-10 00:00:00',
        ]);
    }

    public function test_dashboard_exposes_salary_configuration_state_and_negative_deficit(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        AppSetting::updateOrCreate(['id' => 1], ['monthly_salary_cents' => 10000]);
        Purchase::create([
            'purchased_at' => '2026-10-12',
            'description' => 'Gasto acima do salário',
            'amount_cents' => 12500,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.ownConsumptionCents', 12500)
                ->where('summary.salaryCents', 10000)
                ->where('summary.salaryRemainingCents', -2500)
            );
    }

    public function test_dashboard_exposes_expenses_and_net_balances_by_person_for_the_selected_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        Participant::create(['name' => 'Joana']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $sharedPurchase = Purchase::create([
            'purchased_at' => '2026-10-05',
            'description' => 'Compra compartilhada',
            'amount_cents' => 8000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $self->id, 'amount_cents' => 2000]);
        $mariaDebt = PurchaseAllocation::create(['purchase_id' => $sharedPurchase->id, 'participant_id' => $maria->id, 'amount_cents' => 6000]);

        $payablePurchase = Purchase::create([
            'purchased_at' => '2026-10-06',
            'description' => 'Compra paga por Maria',
            'amount_cents' => 1000,
            'payer_id' => $maria->id,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-07', 'amount' => '30,00'])
            ->assertRedirect('/balances');

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->has('personChart.expenses', 2)
                ->where('personChart.expenses.0.name', 'Maria')
                ->where('personChart.expenses.0.amountCents', 6000)
                ->where('personChart.expenses.1.name', 'Eu')
                ->where('personChart.expenses.1.amountCents', 3000)
                ->has('personChart.balances', 1)
                ->where('personChart.balances.0.name', 'Maria')
                ->where('personChart.balances.0.amountCents', 2000)
                ->where('personChart.balances.0.netCents', 2000)
                ->where('personChart.balances.0.creditCents', 0));

        $this->assertDatabaseHas('receipt_applications', [
            'purchase_allocation_id' => $mariaDebt->id,
            'amount_cents' => 3000,
            'source' => 'automatic',
        ]);
        $this->assertModelExists($payablePurchase);
    }

    public function test_dashboard_represents_participant_credit_in_the_balance_chart(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = Purchase::create([
            'purchased_at' => '2026-10-05',
            'description' => 'Compra para Maria',
            'amount_cents' => 2000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 2000]);

        $this->post('/receipts', ['participant_id' => $maria->id, 'received_at' => '2026-10-07', 'amount' => '30,00'])
            ->assertRedirect('/balances');

        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->has('personChart.balances', 1)
                ->where('personChart.balances.0.name', 'Maria')
                ->where('personChart.balances.0.amountCents', -1000)
                ->where('personChart.balances.0.netCents', 0)
                ->where('personChart.balances.0.creditCents', 1000));
    }

    public function test_dashboard_marks_shared_purchase_as_settled_after_receipt_in_the_selected_month(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = Purchase::create([
            'purchased_at' => '2026-10-05',
            'description' => 'Compra compartilhada quitada',
            'amount_cents' => 8000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $self->id, 'amount_cents' => 2000]);
        $mariaDebt = PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 6000]);

        $this->post(route('receipts.store'), [
            'participant_id' => $maria->id,
            'received_at' => '2026-10-07',
            'amount' => '60,00',
        ])->assertRedirect(route('balances'));

        $this->get(route('dashboard', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('personChart.balances', 1)
                ->where('personChart.balances.0.name', 'Maria')
                ->where('personChart.balances.0.amountCents', 0)
                ->where('personChart.balances.0.netCents', 0)
                ->where('personChart.balances.0.creditCents', 0)
                ->where('personChart.balances.0.status', 'settled'));

        $this->assertDatabaseHas('receipt_applications', [
            'purchase_allocation_id' => $mariaDebt->id,
            'amount_cents' => 6000,
            'source' => 'automatic',
        ]);
    }

    public function test_dashboard_balance_chart_changes_with_month_and_hides_historical_debt(): void
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);
        $purchase = Purchase::create([
            'purchased_at' => '2026-09-20',
            'description' => 'Dívida histórica',
            'amount_cents' => 5000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 5000]);

        $this->get(route('dashboard', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('selectedMonth', '2026-10')->where('personChart.balances', []));

        $this->get(route('dashboard', ['month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMonth', '2026-09')
                ->has('personChart.balances', 1)
                ->where('personChart.balances.0.name', 'Maria')
                ->where('personChart.balances.0.amountCents', 5000)
                ->where('personChart.balances.0.status', 'chargeable'));
    }

    public function test_dashboard_exposes_empty_person_chart_series_without_movements(): void
    {
        $this->get('/?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('personChart.expenses', [])
                ->where('personChart.balances', []));
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
