<?php

namespace Tests\Feature;

use App\Models\Installment;
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

class InvoiceBillingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_select_each_card_by_its_own_invoice_due_month(): void
    {
        [$self, $maria] = $this->catalogs();
        $cardWithEarlyDueDate = $this->card('Cartão dia cinco', 5, 10);
        $cardWithLateClosing = $this->card('Cartão dia vinte', 20, 5);

        $this->purchase('2026-09-06', 'Fatura de outubro do primeiro cartão', 5000, $self, $maria, $cardWithEarlyDueDate);
        $this->purchase('2026-08-21', 'Fatura de outubro do segundo cartão', 7000, $self, $maria, $cardWithLateClosing);
        $this->purchase('2026-09-21', 'Fatura de novembro do segundo cartão', 11000, $self, $maria, $cardWithLateClosing);

        $this->get('/balances?month=2026-10')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 12000)
                ->where('participants.0.receivableCents', 12000)
                ->has('participants.0.receivableItems', 2)
                ->where('participants.0.receivableItems.0.description', 'Fatura de outubro do primeiro cartão')
                ->where('participants.0.receivableItems.0.due_date', '2026-10-10')
                ->where('participants.0.receivableItems.1.description', 'Fatura de outubro do segundo cartão')
                ->where('participants.0.receivableItems.1.due_date', '2026-10-05'));

        $this->get('/balances?month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 11000)
                ->where('participants.0.receivableCents', 11000)
                ->has('participants.0.receivableItems', 1)
                ->where('participants.0.receivableItems.0.description', 'Fatura de novembro do segundo cartão')
                ->where('participants.0.receivableItems.0.due_date', '2026-11-05'));
    }

    public function test_balances_cover_manual_installment_and_recurrence_sources_for_cards_and_loans(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = $this->card('Cartão de cobranças', 5, 10);
        $loan = PaymentMethod::create(['name' => 'Empréstimo de cobranças', 'type' => PaymentMethod::TYPE_LOAN]);

        $this->purchase('2026-09-06', 'Compra manual no cartão', 5000, $self, $maria, $card);
        $this->purchase('2026-09-06', 'Compra manual no empréstimo', 6000, $self, $maria, $loan)->update(['due_date' => '2026-10-20']);

        $installment = Installment::create([
            'start_date' => '2026-09-07',
            'description' => 'Parcela no cartão',
            'total_cents' => 7000,
            'installment_count' => 1,
            'payer_id' => $self->id,
            'payment_method_id' => $card->id,
        ]);
        $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-09-07',
            'description' => 'Parcela no cartão',
            'amount_cents' => 7000,
            'payer_id' => $self->id,
            'payment_method_id' => $card->id,
        ])->allocations()->create(['participant_id' => $maria->id, 'amount_cents' => 7000]);

        $recurrence = Recurrence::create([
            'start_date' => '2026-09-08',
            'due_date' => '2026-10-25',
            'day_of_month' => 8,
            'description' => 'Recorrência no empréstimo',
            'amount_cents' => 8000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
            'active' => true,
        ]);
        $recurrence->occurrences()->create([
            'purchased_at' => '2026-09-08',
            'due_date' => '2026-10-25',
            'description' => 'Recorrência no empréstimo',
            'amount_cents' => 8000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => $loan->id,
        ]);

        $this->get('/balances?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidForOthersCents', 26000)
                ->where('participants.0.receivableCents', 26000)
                ->has('participants.0.receivableItems', 4));
    }

    public function test_versioned_card_settings_classify_old_and_new_purchases_by_their_effective_rules(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = $this->card('Cartão versionado', 5, 10);
        $card->invoiceSettings()->create([
            'closing_day' => 20,
            'due_day' => 5,
            'effective_from' => '2026-10-11',
        ]);

        $this->purchase('2026-10-04', 'Compra antes da troca', 1000, $self, $maria, $card);
        $this->purchase('2026-10-15', 'Compra depois da troca', 2000, $self, $maria, $card);

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paidForOthersCents', 1000)
            ->where('participants.0.receivableItems.0.description', 'Compra antes da troca'));

        $this->get('/balances?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paidForOthersCents', 2000)
            ->where('participants.0.receivableItems.0.description', 'Compra depois da troca'));
    }

    public function test_non_invoice_methods_stay_in_calendar_movements_and_out_of_invoice_balances(): void
    {
        [$self, $maria] = $this->catalogs();

        foreach ([
            ['Pix de outubro', PaymentMethod::TYPE_PIX],
            ['Débito de outubro', PaymentMethod::TYPE_DEBIT],
            ['Dinheiro de outubro', PaymentMethod::TYPE_CASH],
            ['Outro de outubro', PaymentMethod::TYPE_OTHER],
        ] as [$description, $type]) {
            $method = PaymentMethod::create(['name' => $description, 'type' => $type]);
            $this->purchase('2026-10-12', $description, 1000, $self, $maria, $method);
        }

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paidForOthersCents', 0)
            ->where('participants.0.hasMovement', false));

        $this->get('/analysis?month=2026-10&view=calendar')->assertInertia(fn (Assert $page) => $page
            ->where('summary.totalDisbursedCents', 4000)
            ->has('purchaseReview', 4)
            ->where('purchaseReview.0.description', 'Pix de outubro'));
    }

    public function test_receipts_reduce_the_selected_invoice_and_historical_credit_carries_to_later_periods(): void
    {
        [$self, $maria] = $this->catalogs();
        $card = $this->card('Cartão de recebimentos', 5, 10);
        $purchase = $this->purchase('2026-09-06', 'Fatura atual', 5000, $self, $maria, $card);
        Receipt::create([
            'participant_id' => $maria->id,
            'received_at' => '2026-09-30',
            'amount_cents' => 3000,
        ]);
        $receipt = Receipt::create([
            'participant_id' => $maria->id,
            'received_at' => '2026-10-01',
            'amount_cents' => 2000,
        ]);
        ReceiptApplication::create([
            'receipt_id' => $receipt->id,
            'source_type' => 'purchase_allocation',
            'source_id' => $purchase->allocations()->firstOrFail()->id,
            'purchase_allocation_id' => $purchase->allocations()->firstOrFail()->id,
            'amount_cents' => 2000,
            'source' => 'manual',
        ]);

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 3000)
            ->where('participants.0.receivableItems.0.applied_cents', 2000)
            ->where('participants.0.receivableItems.0.outstanding_cents', 3000)
            ->where('participants.0.creditCents', 3000));

        $this->purchase('2026-10-06', 'Fatura seguinte', 7000, $self, $maria, $card);

        $this->get('/balances?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('participants.0.receivableCents', 7000)
            ->where('participants.0.receivableItems.0.applied_cents', 0)
            ->where('participants.0.receivableItems.0.outstanding_cents', 7000)
            ->where('participants.0.creditCents', 3000));
    }

    public function test_manual_installment_and_recurrence_movements_without_payment_methods_are_not_auto_charged(): void
    {
        [$self, $maria] = $this->catalogs();
        $purchase = Purchase::create([
            'purchased_at' => '2026-10-01',
            'description' => 'Compra sem forma',
            'amount_cents' => 1000,
            'payer_id' => $self->id,
            'payment_method_id' => null,
        ]);
        $purchase->allocations()->create(['participant_id' => $maria->id, 'amount_cents' => 1000]);

        $installment = Installment::create([
            'start_date' => '2026-10-01',
            'description' => 'Parcela sem forma',
            'total_cents' => 2000,
            'installment_count' => 1,
            'payer_id' => $self->id,
            'payment_method_id' => null,
        ]);
        $installment->occurrences()->create([
            'installment_number' => 1,
            'purchased_at' => '2026-10-02',
            'description' => 'Parcela sem forma',
            'amount_cents' => 2000,
            'payer_id' => $self->id,
            'payment_method_id' => null,
        ])->allocations()->create(['participant_id' => $maria->id, 'amount_cents' => 2000]);

        $recurrence = Recurrence::create([
            'start_date' => '2026-10-01',
            'day_of_month' => 3,
            'description' => 'Recorrência sem forma',
            'amount_cents' => 3000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => null,
            'active' => true,
        ]);
        $recurrence->occurrences()->create([
            'purchased_at' => '2026-10-03',
            'description' => 'Recorrência sem forma',
            'amount_cents' => 3000,
            'payer_id' => $self->id,
            'participant_id' => $maria->id,
            'payment_method_id' => null,
        ]);

        $this->get('/balances?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paidForOthersCents', 0)
            ->where('participants.0.receivableCents', 0)
            ->where('participants.0.hasMovement', false));
    }

    /** @return array{0: Participant, 1: Participant} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);

        return [$self, $maria];
    }

    private function card(string $name, int $closingDay, int $dueDay): PaymentMethod
    {
        $card = PaymentMethod::create([
            'name' => $name,
            'type' => PaymentMethod::TYPE_CREDIT,
            'closing_day' => $closingDay,
        ]);
        $card->latestInvoiceSetting()->update(['due_day' => $dueDay, 'effective_from' => '2026-01-01']);

        return $card;
    }

    private function purchase(string $date, string $description, int $amountCents, Participant $payer, Participant $participant, PaymentMethod $paymentMethod): Purchase
    {
        $purchase = Purchase::create([
            'purchased_at' => $date,
            'description' => $description,
            'amount_cents' => $amountCents,
            'payer_id' => $payer->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        PurchaseAllocation::create([
            'purchase_id' => $purchase->id,
            'participant_id' => $participant->id,
            'amount_cents' => $amountCents,
        ]);

        return $purchase;
    }
}
