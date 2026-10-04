<?php

namespace Tests\Feature;

use App\Models\Installment;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_page_exposes_period_selection_reconciled_summary_and_editable_prompt(): void
    {
        $this->createExportData();

        $this->get('/exports?mode=period&start_date=2026-10-01&end_date=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Exports/Index')
                ->where('mode', 'period')
                ->where('startDate', '2026-10-01')
                ->where('endDate', '2026-10-31')
                ->where('summary.purchaseTotalCents', 10000)
                ->where('summary.allocationTotalCents', 10000)
                ->where('summary.receiptTotalCents', 2000)
                ->where('summary.occurrenceTotalCents', 3700)
                ->where('summary.totalDisbursedCents', 13700)
                ->where('summary.reconciliationPassed', true)
                ->where('counts.purchases', 1)
                ->where('counts.allocations', 2)
                ->where('counts.receipts', 1)
                ->where('counts.recurrences', 1)
                ->where('counts.installments', 1)
                ->where('prompt', fn (string $prompt): bool => str_contains($prompt, 'Analise os dados de gastos')));
    }

    public function test_selected_period_exports_each_csv_without_out_of_range_records(): void
    {
        $this->createExportData();

        $query = 'mode=period&start_date=2026-10-01&end_date=2026-10-31';
        $exports = [
            'purchases' => ['Mercado do mês', 'Fora do período'],
            'allocations' => ['7000', 'Fora do período'],
            'receipts' => ['2000', 'Fora do período'],
            'recurrences' => ['Assinatura mensal', 'Fora do período'],
            'installments' => ['Curso parcelado', 'Fora do período'],
        ];

        foreach ($exports as $type => [$included, $excluded]) {
            $response = $this->get("/exports/csv/{$type}?{$query}");

            $response->assertDownload("{$type}-2026-10-01-2026-10-31.csv");
            $this->assertStringContainsString($included, $response->streamedContent());
            $this->assertStringNotContainsString($excluded, $response->streamedContent());
        }
    }

    public function test_full_history_exports_records_outside_the_selected_period(): void
    {
        $this->createExportData();

        $response = $this->get('/exports/csv/purchases?mode=history');

        $response->assertDownload('purchases-historico-completo.csv');
        $this->assertStringContainsString('Fora do período', $response->streamedContent());
    }

    public function test_excel_export_contains_consolidated_sheets_and_markdown_reconciles_totals(): void
    {
        $this->createExportData();

        $query = 'mode=period&start_date=2026-10-01&end_date=2026-10-31';
        $excel = $this->get("/exports/excel?{$query}");
        $excel->assertDownload('gastos-2026-10-01-2026-10-31.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $excel->streamedContent());
        $archive = new ZipArchive;

        try {
            $this->assertSame(true, $archive->open($path));
            $this->assertNotFalse($archive->locateName('xl/worksheets/sheet1.xml'));
            $this->assertNotFalse($archive->locateName('xl/worksheets/sheet6.xml'));
            $this->assertStringContainsString('Mercado do mês', $archive->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertStringContainsString('Análise', $archive->getFromName('xl/workbook.xml'));
        } finally {
            $archive->close();
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $markdown = $this->get("/exports/markdown?{$query}");
        $markdown->assertDownload('analise-2026-10-01-2026-10-31.md');
        $this->assertStringContainsString('Total desembolsado: R$ 137,00', $markdown->streamedContent());
        $this->assertStringContainsString('Conferência de rateios: OK', $markdown->streamedContent());
    }

    public function test_prompt_export_is_available_without_an_ai_api_call(): void
    {
        $this->createExportData();

        $response = $this->get('/exports/prompt?mode=period&start_date=2026-10-01&end_date=2026-10-31');

        $response->assertDownload('prompt-2026-10-01-2026-10-31.md');
        $this->assertStringContainsString('Não envie mensagens nem altere os dados oficiais', $response->streamedContent());
    }

    public function test_period_export_requires_a_valid_date_range(): void
    {
        $this->get('/exports?mode=period&start_date=2026-11-01&end_date=2026-10-01')
            ->assertSessionHasErrors('end_date');
    }

    /** @return array{self: Participant, maria: Participant, pix: PaymentMethod} */
    private function createExportData(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria']);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX]);

        $purchase = Purchase::create([
            'purchased_at' => '2026-10-12',
            'description' => 'Mercado do mês',
            'card_name' => 'SUPERMERCADO REAL',
            'amount_cents' => 10000,
            'payer_id' => $self->id,
            'payment_method_id' => $pix->id,
        ]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $self->id, 'amount_cents' => 3000]);
        PurchaseAllocation::create(['purchase_id' => $purchase->id, 'participant_id' => $maria->id, 'amount_cents' => 7000]);
        Purchase::create(['purchased_at' => '2026-09-12', 'description' => 'Fora do período', 'amount_cents' => 9000, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $pix->id]);

        $receipt = Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-10-20', 'amount_cents' => 2000, 'note' => 'Pix recebido']);
        $receipt->applications()->create(['source_type' => 'purchase_allocation', 'source_id' => $purchase->id, 'amount_cents' => 2000, 'source' => 'manual']);
        Receipt::create(['participant_id' => $maria->id, 'received_at' => '2026-09-20', 'amount_cents' => 1000, 'note' => 'Fora do período']);

        $recurrence = Recurrence::create(['start_date' => '2026-10-01', 'day_of_month' => 15, 'description' => 'Assinatura mensal', 'amount_cents' => 2500, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $pix->id, 'active' => true]);
        RecurrenceOccurrence::create(['recurrence_id' => $recurrence->id, 'purchased_at' => '2026-10-15', 'description' => 'Assinatura mensal', 'amount_cents' => 2500, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $pix->id]);

        $installment = Installment::create(['start_date' => '2026-10-05', 'description' => 'Curso parcelado', 'total_cents' => 3600, 'installment_count' => 3, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $pix->id]);
        InstallmentOccurrence::create(['installment_id' => $installment->id, 'installment_number' => 1, 'purchased_at' => '2026-10-05', 'description' => 'Curso parcelado', 'amount_cents' => 1200, 'payer_id' => $self->id, 'participant_id' => $self->id, 'payment_method_id' => $pix->id]);

        return compact('self', 'maria', 'pix');
    }
}
