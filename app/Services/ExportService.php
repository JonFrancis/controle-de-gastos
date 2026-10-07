<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Participant;
use App\Models\PromptVersion;
use App\Models\Purchase;
use App\Models\PurchaseAllocation;
use App\Models\Receipt;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExportService
{
    /** @var list<string> */
    private const TYPES = ['purchases', 'allocations', 'receipts', 'recurrences', 'installments'];

    public function __construct(private readonly MonthlyAnalysisService $monthlyAnalysisService) {}

    /** @param array{mode?: string, start_date?: string|null, end_date?: string|null} $input */
    /** @return array{mode: 'period'|'history', start: CarbonImmutable|null, end: CarbonImmutable|null, label: string} */
    public function selection(array $input): array
    {
        $mode = ($input['mode'] ?? 'period') === 'history' ? 'history' : 'period';

        if ($mode === 'history') {
            return ['mode' => 'history', 'start' => null, 'end' => null, 'label' => 'historico-completo'];
        }

        $start = CarbonImmutable::parse($input['start_date']);
        $end = CarbonImmutable::parse($input['end_date']);

        return ['mode' => 'period', 'start' => $start, 'end' => $end, 'label' => $start->toDateString().'-'.$end->toDateString()];
    }

    /** @return array{purchases: list<array<string, mixed>>, allocations: list<array<string, mixed>>, receipts: list<array<string, mixed>>, recurrences: list<array<string, mixed>>, installments: list<array<string, mixed>>, summary: array<string, mixed>, counts: array<string, int>, analysis: array<string, mixed>} */
    public function data(array $selection): array
    {
        $purchases = $this->purchases($selection);
        $receipts = $this->receipts($selection);
        $recurrences = $this->recurrences($selection);
        $installments = $this->installments($selection);
        $allocationRows = $this->allocationRows($purchases);
        $purchaseRows = $purchases->map(fn (Purchase $purchase): array => $this->purchaseRow($purchase))->values()->all();
        $receiptRows = $receipts->map(fn (Receipt $receipt): array => $this->receiptRow($receipt))->values()->all();
        $recurrenceRows = $recurrences->map(fn (Recurrence $recurrence): array => $this->recurrenceRow($recurrence))->values()->all();
        $installmentRows = $installments->map(fn ($installment): array => $this->installmentRow($installment))->values()->all();
        $summary = $this->summary($purchases, $receipts, $recurrences, $installments);
        $analysis = $this->monthlyAnalysisService->analyzeRange($selection['start'], $selection['end']);

        return [
            'purchases' => $purchaseRows,
            'allocations' => $allocationRows->values()->all(),
            'receipts' => $receiptRows,
            'recurrences' => $recurrenceRows,
            'installments' => $installmentRows,
            'summary' => $summary,
            'analysis' => $analysis,
            'counts' => [
                'purchases' => count($purchaseRows),
                'allocations' => $allocationRows->count(),
                'receipts' => count($receiptRows),
                'recurrences' => count($recurrenceRows),
                'installments' => count($installmentRows),
            ],
        ];
    }

    public function csv(string $type, array $selection): StreamedResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $data = $this->data($selection);
        $rows = $data[$type];
        $headers = array_keys($rows[0] ?? $this->emptyHeaders($type));

        return response()->streamDownload(function () use ($headers, $rows): void {
            echo "\xEF\xBB\xBF";
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $header): mixed => $row[$header] ?? null, $headers), ';');
            }
            fclose($handle);
        }, $type.'-'.$selection['label'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{path: string, filename: string} */
    public function excel(array $selection): array
    {
        $data = $this->data($selection);
        $sheets = [
            'Compras' => $this->sheetData($data['purchases']),
            'Rateios' => $this->sheetData($data['allocations']),
            'Recebimentos' => $this->sheetData($data['receipts']),
            'Recorrencias' => $this->sheetData($data['recurrences']),
            'Parcelamentos' => $this->sheetData($data['installments']),
            'Análise' => [
                ['Campo', 'Valor'],
                ['Total de compras', $data['summary']['purchaseTotalCents']],
                ['Total de rateios', $data['summary']['allocationTotalCents']],
                ['Total de recebimentos', $data['summary']['receiptTotalCents']],
                ['Total de ocorrencias', $data['summary']['occurrenceTotalCents']],
                ['Total desembolsado', $data['summary']['totalDisbursedCents']],
                ['Conferencia', $data['summary']['reconciliationPassed'] ? 'OK' : 'Revisar'],
            ],
        ];
        $path = tempnam(sys_get_temp_dir(), 'gastos-export-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->addWorkbookFiles($archive, $sheets);
        $archive->close();

        return ['path' => $path, 'filename' => 'gastos-'.$selection['label'].'.xlsx'];
    }

    public function markdown(array $selection): StreamedResponse
    {
        $data = $this->data($selection);
        $content = $this->markdownContent($selection, $data);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'analise-'.$selection['label'].'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    public function prompt(array $selection, ?array $data = null): string
    {
        $savedPrompt = $this->savedPrompt($selection);
        if ($savedPrompt !== null) {
            return $savedPrompt;
        }

        $data ??= $this->data($selection);
        $summary = $data['summary'];

        return "# Prompt para análise de gastos\n\n"
            .'Analise os dados de gastos do escopo **'.$this->scopeLabel($selection)."**.\n\n"
            ."## Regras\n\n"
            ."- Preserve os valores em centavos e reconcilie os totais antes de interpretar.\n"
            ."- Separe consumo próprio, valor pago para terceiros e recebimentos.\n"
            ."- Aponte ambiguidades, dados ausentes e diferenças de rateio.\n"
            ."- Não invente categorias, pessoas ou lançamentos.\n"
            ."- Não envie mensagens nem altere os dados oficiais.\n\n"
            ."## Totais conhecidos\n\n"
            .'- Compras: '.$this->money($summary['purchaseTotalCents'])."\n"
            .'- Rateios: '.$this->money($summary['allocationTotalCents'])."\n"
            .'- Recebimentos: '.$this->money($summary['receiptTotalCents'])."\n"
            .'- Ocorrências: '.$this->money($summary['occurrenceTotalCents'])."\n"
            .'- Total desembolsado: '.$this->money($summary['totalDisbursedCents'])."\n\n"
            .$this->analysisMarkdown($data['analysis'])
            ."\n## Registros selecionados\n\n"
            ."Os registros abaixo já estão preenchidos para este escopo. Use somente estes dados, sem chamar APIs e sem solicitar que o usuário cole arquivos adicionais.\n\n"
            .'```json'."\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n```\n";
    }

    public function savePromptVersion(array $selection, string $content): PromptVersion
    {
        return PromptVersion::create([
            'mode' => $selection['mode'],
            'start_date' => $selection['start']?->toDateString(),
            'end_date' => $selection['end']?->toDateString(),
            'content' => $content,
        ]);
    }

    private function savedPrompt(array $selection): ?string
    {
        return PromptVersion::query()
            ->where('mode', $selection['mode'])
            ->when($selection['mode'] === 'period', fn ($query) => $query
                ->whereDate('start_date', $selection['start']->toDateString())
                ->whereDate('end_date', $selection['end']->toDateString()))
            ->latest('id')
            ->value('content');
    }

    private function purchases(array $selection): Collection
    {
        return Purchase::query()->with(['payer:id,name', 'participant:id,name', 'paymentMethod:id,name,type', 'category:id,name', 'allocations.participant:id,name', 'allocations.category:id,name', 'spreadsheetImportRow.spreadsheetImport:id,original_filename'])
            ->when($selection['start'], fn ($query, CarbonImmutable $start) => $query->whereDate('purchased_at', '>=', $start))
            ->when($selection['end'], fn ($query, CarbonImmutable $end) => $query->whereDate('purchased_at', '<=', $end))
            ->orderBy('purchased_at')->orderBy('id')->get();
    }

    private function receipts(array $selection): Collection
    {
        return Receipt::query()->with(['participant:id,name', 'applicationHistory'])
            ->when($selection['start'], fn ($query, CarbonImmutable $start) => $query->whereDate('received_at', '>=', $start))
            ->when($selection['end'], fn ($query, CarbonImmutable $end) => $query->whereDate('received_at', '<=', $end))
            ->orderBy('received_at')->orderBy('id')->get();
    }

    private function recurrences(array $selection): Collection
    {
        return Recurrence::query()->with(['paymentMethod:id,name', 'category:id,name', 'payer:id,name', 'participant:id,name', 'occurrences' => fn ($query) => $this->constrainOccurrences($query, $selection)])
            ->when($selection['end'], fn ($query, CarbonImmutable $end) => $query->whereDate('start_date', '<=', $end))
            ->when($selection['start'], fn ($query, CarbonImmutable $start) => $query->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start)))
            ->orderBy('start_date')->orderBy('id')->get();
    }

    private function installments(array $selection): Collection
    {
        return Installment::query()->with(['paymentMethod:id,name', 'category:id,name', 'payer:id,name', 'participant:id,name', 'occurrences' => fn ($query) => $this->constrainOccurrences($query, $selection)])
            ->when($selection['end'], fn ($query, CarbonImmutable $end) => $query->whereDate('start_date', '<=', $end))
            ->orderBy('start_date')->orderBy('id')->get();
    }

    private function constrainOccurrences(Builder|Relation $query, array $selection): void
    {
        $query->when($selection['start'], fn ($query, CarbonImmutable $start) => $query->whereDate('purchased_at', '>=', $start))
            ->when($selection['end'], fn ($query, CarbonImmutable $end) => $query->whereDate('purchased_at', '<=', $end));
    }

    private function purchaseRow(Purchase $purchase): array
    {
        return ['id' => $purchase->id, 'purchased_at' => $purchase->purchased_at->toDateString(), 'description' => $purchase->description, 'card_name' => $purchase->card_name, 'amount_cents' => $purchase->amount_cents, 'origin' => $purchase->origin, 'payer' => $purchase->payer?->name, 'participant' => $purchase->participant?->name, 'payment_method' => $purchase->paymentMethod?->name, 'category' => $purchase->category?->name, 'allocation_mode' => $purchase->allocation_mode, 'source_file' => $purchase->spreadsheetImportRow?->spreadsheetImport?->original_filename, 'source_sheet' => $purchase->spreadsheetImportRow?->sheet_name, 'source_row' => $purchase->spreadsheetImportRow?->row_number, 'archived_at' => $purchase->archived_at?->toIso8601String()];
    }

    private function allocationRows(Collection $purchases): Collection
    {
        return $purchases->flatMap(fn (Purchase $purchase): Collection => $purchase->allocations->map(fn (PurchaseAllocation $allocation): array => ['id' => $allocation->id, 'purchase_id' => $purchase->id, 'purchased_at' => $purchase->purchased_at->toDateString(), 'participant' => $allocation->participant?->name, 'category' => $allocation->category?->name, 'amount_cents' => $allocation->amount_cents, 'percentage_basis_points' => $allocation->percentage_basis_points]));
    }

    private function receiptRow(Receipt $receipt): array
    {
        $appliedCents = (int) $receipt->applicationHistory->whereNull('superseded_at')->sum('amount_cents');

        return ['id' => $receipt->id, 'received_at' => $receipt->received_at->toDateString(), 'participant' => $receipt->participant?->name, 'amount_cents' => $receipt->amount_cents, 'applied_cents' => $appliedCents, 'credit_cents' => max(0, $receipt->amount_cents - $appliedCents), 'note' => $receipt->note, 'is_manually_adjusted' => $receipt->is_manually_adjusted ? 'yes' : 'no', 'archived_at' => $receipt->archived_at?->toIso8601String()];
    }

    private function recurrenceRow(Recurrence $recurrence): array
    {
        return ['id' => $recurrence->id, 'start_date' => $recurrence->start_date->toDateString(), 'end_date' => $recurrence->end_date?->toDateString(), 'day_of_month' => $recurrence->day_of_month, 'description' => $recurrence->description, 'card_name' => $recurrence->card_name, 'amount_cents' => $recurrence->amount_cents, 'payer' => $recurrence->payer?->name, 'participant' => $recurrence->participant?->name, 'payment_method' => $recurrence->paymentMethod?->name, 'category' => $recurrence->category?->name, 'active' => $recurrence->active ? 'yes' : 'no', 'occurrences_in_range' => $recurrence->occurrences->count(), 'occurrence_total_cents' => (int) $recurrence->occurrences->whereNull('archived_at')->sum('amount_cents'), 'archived_at' => $recurrence->archived_at?->toIso8601String()];
    }

    private function installmentRow(Installment $installment): array
    {
        return ['id' => $installment->id, 'start_date' => $installment->start_date->toDateString(), 'description' => $installment->description, 'card_name' => $installment->card_name, 'total_cents' => $installment->total_cents, 'installment_count' => $installment->installment_count, 'payer' => $installment->payer?->name, 'participant' => $installment->participant?->name, 'payment_method' => $installment->paymentMethod?->name, 'category' => $installment->category?->name, 'occurrences_in_range' => $installment->occurrences->count(), 'occurrence_total_cents' => (int) $installment->occurrences->whereNull('archived_at')->sum('amount_cents'), 'archived_at' => $installment->archived_at?->toIso8601String()];
    }

    private function summary(Collection $purchases, Collection $receipts, Collection $recurrences, Collection $installments): array
    {
        $activePurchases = $purchases->whereNull('archived_at');
        $activeReceipts = $receipts->whereNull('archived_at');
        $activeRecurrenceOccurrences = $recurrences->flatMap->occurrences->whereNull('archived_at');
        $activeInstallmentOccurrences = $installments->flatMap->occurrences->whereNull('archived_at');
        $selfId = (int) Participant::query()->where('is_default', true)->value('id');
        $purchaseTotalCents = (int) $activePurchases->sum('amount_cents');
        $allocationTotalCents = (int) $activePurchases->sum(fn (Purchase $purchase): int => $purchase->allocations->isEmpty() ? $purchase->amount_cents : (int) $purchase->allocations->sum('amount_cents'));
        $occurrenceTotalCents = (int) $activeRecurrenceOccurrences->sum('amount_cents') + (int) $activeInstallmentOccurrences->sum('amount_cents');
        $transactions = $this->transactions($activePurchases, $activeRecurrenceOccurrences, $activeInstallmentOccurrences, $selfId);
        $ownConsumptionCents = (int) $transactions->where('participant_id', $selfId)->sum('amount_cents');
        $paidForOthersCents = (int) $transactions->filter(fn (array $row): bool => $row['payer_id'] === $selfId && $row['participant_id'] !== $selfId)->sum('amount_cents');
        $reconciliationPassed = $activePurchases->every(fn (Purchase $purchase): bool => $purchase->allocations->isEmpty() || (int) $purchase->allocations->sum('amount_cents') === $purchase->amount_cents);

        return ['purchaseTotalCents' => $purchaseTotalCents, 'allocationTotalCents' => $allocationTotalCents, 'receiptTotalCents' => (int) $activeReceipts->sum('amount_cents'), 'receiptAppliedCents' => (int) $activeReceipts->sum(fn (Receipt $receipt): int => (int) $receipt->applicationHistory->whereNull('superseded_at')->sum('amount_cents')), 'occurrenceTotalCents' => $occurrenceTotalCents, 'totalDisbursedCents' => $purchaseTotalCents + $occurrenceTotalCents, 'ownConsumptionCents' => $ownConsumptionCents, 'paidForOthersCents' => $paidForOthersCents, 'reconciliationPassed' => $reconciliationPassed && $allocationTotalCents === $purchaseTotalCents];
    }

    private function transactions(Collection $purchases, Collection $recurrenceOccurrences, Collection $installmentOccurrences, int $selfId): Collection
    {
        $transactions = collect();
        foreach ($purchases as $purchase) {
            if ($purchase->allocations->isEmpty()) {
                $transactions->push(['amount_cents' => $purchase->amount_cents, 'payer_id' => $purchase->payer_id ?? $selfId, 'participant_id' => $purchase->participant_id ?? $selfId]);

                continue;
            }
            foreach ($purchase->allocations as $allocation) {
                $transactions->push(['amount_cents' => $allocation->amount_cents, 'payer_id' => $purchase->payer_id ?? $selfId, 'participant_id' => $allocation->participant_id ?? $selfId]);
            }
        }
        foreach ($recurrenceOccurrences->concat($installmentOccurrences) as $occurrence) {
            $transactions->push(['amount_cents' => $occurrence->amount_cents, 'payer_id' => $occurrence->payer_id ?? $selfId, 'participant_id' => $occurrence->participant_id ?? $selfId]);
        }

        return $transactions;
    }

    private function markdownContent(array $selection, array $data): string
    {
        $summary = $data['summary'];

        return '# Análise de exportação'."\n\n"
            .'- Escopo: '.$this->scopeLabel($selection)."\n"
            .'- Compras: '.$data['counts']['purchases']."\n"
            .'- Rateios: '.$data['counts']['allocations']."\n"
            .'- Recebimentos: '.$data['counts']['receipts']."\n\n"
            .'## Totais'."\n\n"
            .'- Total de compras: '.$this->money($summary['purchaseTotalCents'])."\n"
            .'- Total de rateios: '.$this->money($summary['allocationTotalCents'])."\n"
            .'- Total de recebimentos: '.$this->money($summary['receiptTotalCents'])."\n"
            .'- Total de ocorrências: '.$this->money($summary['occurrenceTotalCents'])."\n"
            .'- Consumo próprio: '.$this->money($summary['ownConsumptionCents'])."\n"
            .'- Pago para terceiros: '.$this->money($summary['paidForOthersCents'])."\n"
            .'- Total desembolsado: '.$this->money($summary['totalDisbursedCents'])."\n\n"
            .'## Conferência'."\n\n"
            .'- Conferência de rateios: '.($summary['reconciliationPassed'] ? 'OK' : 'REVISAR')."\n"
            .'- Diferença entre compras e rateios: '.$this->money($summary['purchaseTotalCents'] - $summary['allocationTotalCents'])."\n\n"
            .$this->analysisMarkdown($data['analysis']);
    }

    /** @param array<string, mixed> $analysis */
    private function analysisMarkdown(array $analysis): string
    {
        $summary = $analysis['summary'];
        $content = "## Análise mensal\n\n"
            .'- Consumo próprio: '.$this->money($summary['ownConsumptionCents'])."\n"
            .'- Valor pago para terceiros: '.$this->money($summary['paidForOthersCents'])."\n"
            .'- Total desembolsado: '.$this->money($summary['totalDisbursedCents'])."\n\n"
            .'## Saldos de participantes'."\n\n";

        if ($analysis['participants'] === []) {
            $content .= "Nenhum participante com saldo no escopo.\n\n";
        } else {
            foreach ($analysis['participants'] as $participant) {
                $content .= '### '.$participant['name']."\n\n"
                    .'- Saldo bruto: '.$this->money($participant['grossCents'])."\n"
                    .'- Abatimentos: '.$this->money($participant['abatementsCents'])."\n"
                    .'- Cobrança líquida: '.$this->money($participant['finalCents'])."\n"
                    .'- Status: '.$participant['status']."\n";
                foreach ($participant['items'] as $item) {
                    $content .= '- '.$item['description'].' ('.$item['date'].'): '.$this->money($item['finalCents'])."\n";
                }
                $content .= "\n";
            }
        }

        $content .= "## Consumo próprio\n\n"
            ."### Por categoria\n\n"
            .$this->breakdownMarkdown($analysis['categories'])
            ."\n### Por forma de pagamento\n\n"
            .$this->breakdownMarkdown($analysis['paymentMethods']);

        return $content;
    }

    /** @param list<array{name: string, amountCents: int}> $rows */
    private function breakdownMarkdown(array $rows): string
    {
        if ($rows === []) {
            return "Nenhum consumo próprio no escopo.\n";
        }

        return collect($rows)->map(fn (array $row): string => '- '.$row['name'].': '.$this->money($row['amountCents']))->implode("\n")."\n";
    }

    private function scopeLabel(array $selection): string
    {
        return $selection['mode'] === 'history' ? 'histórico completo' : $selection['start']->format('d/m/Y').' a '.$selection['end']->format('d/m/Y');
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    private function sheetData(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return array_merge([array_keys($rows[0])], array_map('array_values', $rows));
    }

    private function emptyHeaders(string $type): array
    {
        return match ($type) {
            'purchases' => ['id' => null, 'purchased_at' => null, 'description' => null, 'amount_cents' => null],
            'allocations' => ['id' => null, 'purchase_id' => null, 'amount_cents' => null],
            'receipts' => ['id' => null, 'received_at' => null, 'amount_cents' => null],
            'recurrences' => ['id' => null, 'start_date' => null, 'description' => null, 'amount_cents' => null],
            'installments' => ['id' => null, 'start_date' => null, 'description' => null, 'total_cents' => null],
        };
    }

    private function addWorkbookFiles(ZipArchive $archive, array $sheets): void
    {
        $contentTypes = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">', '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>', '<Default Extension="xml" ContentType="application/xml"/>', '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'];
        $workbookSheets = [];
        $relationships = [];
        foreach (array_keys($sheets) as $index => $name) {
            $sheetNumber = $index + 1;
            $contentTypes[] = '<Override PartName="/xl/worksheets/sheet'.$sheetNumber.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets[] = '<sheet name="'.$this->xml($name).'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
            $relationships[] = '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';
            $archive->addFromString('xl/worksheets/sheet'.$sheetNumber.'.xml', $this->sheetXml($sheets[$name]));
        }
        $contentTypes[] = '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $contentTypes[] = '</Types>';
        $archive->addFromString('[Content_Types].xml', implode('', $contentTypes));
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('', $workbookSheets).'</sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationships).'</Relationships>');
        $archive->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf/></cellXfs></styleSheet>');
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $this->columnName($columnIndex).($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$cell.'"><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t>'.$this->xml((string) ($value ?? '')).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
