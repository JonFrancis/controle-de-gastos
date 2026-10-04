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
            $maria = Participant::create(['name' => self::PREFIX.'Maria', 'active' => true, 'is_default' => false]);
            $carlos = Participant::create(['name' => self::PREFIX.'Carlos', 'active' => true, 'is_default' => false]);
            $card = PaymentMethod::create(['name' => self::PREFIX.'Cartão fecha dia 10', 'type' => PaymentMethod::TYPE_CREDIT, 'closing_day' => 10, 'active' => true]);
            $pix = PaymentMethod::create(['name' => self::PREFIX.'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);
            $mercado = Category::create(['name' => self::PREFIX.'Mercado', 'active' => true]);
            $lazer = Category::create(['name' => self::PREFIX.'Lazer', 'active' => true]);

            AppSetting::query()->updateOrCreate(['id' => 1], ['monthly_salary_cents' => 1000000]);

            Purchase::create([
                'purchased_at' => '2026-10-03',
                'description' => self::PREFIX.'Compra própria no Pix',
                'card_name' => 'SUPERMERCADO VISUAL',
                'amount_cents' => 12000,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $pix->id,
                'category_id' => $mercado->id,
            ]);

            $dividedPurchase = Purchase::create([
                'purchased_at' => '2026-10-07',
                'description' => self::PREFIX.'Compra dividida entre três pessoas',
                'card_name' => 'LOJA VISUAL ONLINE',
                'amount_cents' => 24000,
                'payer_id' => $self->id,
                'payment_method_id' => $card->id,
            ]);
            $ownAllocation = PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $self->id, 'category_id' => $lazer->id, 'amount_cents' => 9000]);
            $mariaAllocation = PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $maria->id, 'amount_cents' => 10000]);
            PurchaseAllocation::create(['purchase_id' => $dividedPurchase->id, 'participant_id' => $carlos->id, 'amount_cents' => 5000]);

            Purchase::create([
                'purchased_at' => '2026-10-15',
                'description' => self::PREFIX.'Item pendente para revisão',
                'card_name' => 'PENDENTE VISUAL',
                'amount_cents' => 3300,
                'payer_id' => $self->id,
                'participant_id' => $maria->id,
            ]);

            Purchase::create([
                'purchased_at' => '2026-09-11',
                'description' => self::PREFIX.'Compra que cai na fatura de outubro',
                'card_name' => 'FATURA VISUAL SETEMBRO',
                'amount_cents' => 8000,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $card->id,
                'category_id' => $mercado->id,
            ]);

            $installmentService->create([
                'start_date' => '2026-10-05',
                'description' => self::PREFIX.'Parcelamento de três vezes',
                'card_name' => 'CURSO VISUAL',
                'total' => '360,00',
                'installment_count' => 3,
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $card->id,
                'category_id' => $lazer->id,
            ]);

            $recurrenceService->create([
                'start_date' => '2026-09-15',
                'end_date' => '2026-12-15',
                'day_of_month' => 15,
                'description' => self::PREFIX.'Recorrência mensal',
                'card_name' => 'ASSINATURA VISUAL',
                'amount' => '45,00',
                'payer_id' => $self->id,
                'participant_id' => $self->id,
                'payment_method_id' => $card->id,
                'category_id' => $lazer->id,
            ]);

            $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-25', 'amount_cents' => 4000, 'note' => self::PREFIX.'Recebimento parcial']);
            ReceiptApplication::create(['receipt_id' => $receipt->id, 'source_type' => 'purchase_allocation', 'source_id' => $mariaAllocation->id, 'purchase_allocation_id' => $mariaAllocation->id, 'amount_cents' => 4000, 'source' => 'manual']);
        });

        $this->command?->info('Massa visual criada. Marcador: "'.self::PREFIX.'". Analise outubro de 2026.');
    }
}
