<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\InstallmentService;
use App\Services\RecurrenceService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VisualTestSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PREFIX = 'VISUAL TEST | ';

    public function run(InstallmentService $installmentService, RecurrenceService $recurrenceService): void
    {
        DB::transaction(function () use ($installmentService, $recurrenceService): void {
            $self = Participant::query()->where('is_default', true)->firstOrFail();
            $teste1 = Participant::create(['name' => 'Teste 1', 'active' => true, 'is_default' => false]);
            $teste2 = Participant::create(['name' => 'Teste 2', 'active' => true, 'is_default' => false]);
            $credit = PaymentMethod::create(['name' => 'Cartão de crédito', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10, 'active' => true]);
            $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);
            $debit = PaymentMethod::create(['name' => 'Cartão de débito', 'type' => PaymentMethod::TYPE_DEBIT, 'active' => true]);
            $cash = PaymentMethod::create(['name' => 'Dinheiro', 'type' => PaymentMethod::TYPE_CASH, 'active' => true]);
            $market = Category::create(['name' => 'Mercado', 'active' => true]);
            $leisure = Category::create(['name' => 'Lazer', 'active' => true]);
            $month = now()->startOfMonth();

            AppSetting::query()->updateOrCreate(['id' => 1], ['monthly_salary_cents' => 1000000]);

            Purchase::create([
                'purchased_at' => $month->copy()->addDays(2)->toDateString(),
                'description' => self::PREFIX.'Compra própria no Pix',
                'card_name' => 'SUPERMERCADO VISUAL',
                'amount_cents' => 12000,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $pix->id,
                'category_id' => $market->id,
            ]);

            $dividedPurchase = Purchase::create([
                'purchased_at' => $month->copy()->addDays(5)->toDateString(),
                'description' => self::PREFIX.'Compra dividida entre três pessoas',
                'card_name' => 'LOJA VISUAL ONLINE',
                'amount_cents' => 24000,
                'payer_id' => $self->id,
                'payment_method_id' => $credit->id,
            ]);
            PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $self->id, 'category_id' => $leisure->id, 'amount_cents' => 9000]);
            $teste1Allocation = PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $teste1->id, 'category_id' => $leisure->id, 'amount_cents' => 10000]);
            PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $teste2->id, 'category_id' => $leisure->id, 'amount_cents' => 5000]);

            Purchase::create([
                'purchased_at' => $month->copy()->addDays(6)->toDateString(),
                'description' => self::PREFIX.'Farmácia no débito',
                'card_name' => 'FARMÁCIA VISUAL',
                'amount_cents' => 6800,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $debit->id,
                'category_id' => $market->id,
            ]);

            Purchase::create([
                'purchased_at' => $month->copy()->addDays(7)->toDateString(),
                'description' => self::PREFIX.'Feira em dinheiro',
                'card_name' => 'FEIRA VISUAL',
                'amount_cents' => 3500,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $cash->id,
                'category_id' => $market->id,
            ]);

            Purchase::create([
                'purchased_at' => $month->copy()->addDays(8)->toDateString(),
                'description' => self::PREFIX.'Item pendente para revisão',
                'card_name' => 'PENDENTE VISUAL',
                'amount_cents' => 3300,
                'payer_id' => $self->id,
                'participant_id' => $teste1->id,
            ]);

            Purchase::create([
                'purchased_at' => $month->copy()->subMonth()->day(11)->toDateString(),
                'description' => self::PREFIX.'Compra que cai na fatura do mês',
                'card_name' => 'FATURA VISUAL',
                'amount_cents' => 8000,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $credit->id,
                'category_id' => $market->id,
            ]);

            $installmentService->create([
                'start_date' => $month->copy()->addDays(4)->toDateString(),
                'description' => self::PREFIX.'Parcelamento de três vezes',
                'card_name' => 'CURSO VISUAL',
                'total' => '360,00',
                'installment_count' => 3,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $credit->id,
                'category_id' => $leisure->id,
            ]);

            $recurrenceService->create([
                'start_date' => $month->copy()->subMonths(2)->day(15)->toDateString(),
                'end_date' => $month->copy()->addMonths(2)->day(15)->toDateString(),
                'day_of_month' => 15,
                'description' => self::PREFIX.'Recorrência mensal',
                'card_name' => 'ASSINATURA VISUAL',
                'amount' => '45,00',
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $credit->id,
                'category_id' => $leisure->id,
            ]);

            $receipt = Receipt::create([
                'participant_id' => $teste1->id,
                'received_at' => $month->copy()->addDays(9)->toDateString(),
                'amount_cents' => 4000,
                'note' => self::PREFIX.'Recebimento parcial',
            ]);
            ReceiptApplication::create([
                'receipt_id' => $receipt->id,
                'source_type' => 'purchase_allocation',
                'source_id' => $teste1Allocation->id,
                'purchase_allocation_id' => $teste1Allocation->id,
                'amount_cents' => 4000,
                'source' => 'manual',
            ]);
        });

        $this->command?->info('Massa visual criada. Marcador: "'.self::PREFIX.'". Analise o mês atual.');
    }
}
