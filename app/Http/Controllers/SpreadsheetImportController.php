<?php

namespace App\Http\Controllers;

use App\Http\Requests\MapSpreadsheetImportRequest;
use App\Http\Requests\StoreSpreadsheetImportRequest;
use App\Http\Requests\UpdateSpreadsheetImportRowRequest;
use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use App\Services\SpreadsheetImportService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SpreadsheetImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Imports/Create');
    }

    public function store(StoreSpreadsheetImportRequest $request, SpreadsheetImportService $service): RedirectResponse
    {
        $import = $service->create($request->file('file'));

        return to_route('imports.mapping', $import);
    }

    public function queue(): Response
    {
        $pendingImports = SpreadsheetImport::query()
            ->whereHas('rows', fn ($query) => $query->where('status', 'pending_review'))
            ->withCount(['rows as pending_rows_count' => fn ($query) => $query->where('status', 'pending_review')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'original_filename', 'status'])
            ->map(fn (SpreadsheetImport $import): array => [
                'id' => $import->id,
                'filename' => $import->original_filename,
                'status' => $import->status,
                'pendingRows' => (int) $import->pending_rows_count,
                'reviewUrl' => route('imports.review', $import, false),
            ])
            ->values();

        return Inertia::render('Imports/Queue', [
            'pendingReview' => (int) $pendingImports->sum('pendingRows'),
            'imports' => $pendingImports,
        ]);
    }

    public function mapping(SpreadsheetImport $import): Response
    {
        return Inertia::render('Imports/Mapping', [
            'import' => $import,
            'headers' => $import->headers,
            'rows' => $import->rows()->orderBy('sheet_name')->orderBy('row_number')->limit(8)->get(['id', 'sheet_name', 'row_number', 'raw_data']),
            'mapping' => $import->column_mapping ?? $this->suggestedMapping($import->headers),
        ]);
    }

    public function map(MapSpreadsheetImportRequest $request, SpreadsheetImport $import, SpreadsheetImportService $service): RedirectResponse
    {
        $service->map($import, $request->validated('mapping'), $request->validated('period_start'), $request->validated('period_end'));

        return to_route('imports.review', $import);
    }

    public function review(SpreadsheetImport $import): Response
    {
        return Inertia::render('Imports/Review', [
            'import' => $import,
            'rows' => $import->rows()->orderBy('sheet_name')->orderBy('row_number')->get()->map(fn (SpreadsheetImportRow $row): array => [
                'id' => $row->id,
                'sheetName' => $row->sheet_name,
                'rowNumber' => $row->row_number,
                'mappedData' => $row->mapped_data,
                'issues' => $row->issues ?? [],
                'status' => $row->status,
            ])->values(),
            'participants' => Participant::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateRow(UpdateSpreadsheetImportRowRequest $request, SpreadsheetImport $import, SpreadsheetImportRow $row, SpreadsheetImportService $service): RedirectResponse
    {
        abort_unless($row->spreadsheet_import_id === $import->id, 404);
        $service->review($row, $request->validated());

        return to_route('imports.review', $import);
    }

    public function confirm(SpreadsheetImport $import, SpreadsheetImportService $service): RedirectResponse
    {
        $service->confirm($import);

        return to_route('imports.review', $import)->with('success', 'Lançamentos revisados importados com sucesso.');
    }

    /** @param list<string> $headers @return array<string, string|null> */
    private function suggestedMapping(array $headers): array
    {
        $aliases = [
            'purchased_at' => ['data', 'date', 'data da compra'],
            'description' => ['descrição', 'descricao', 'description', 'estabelecimento'],
            'card_name' => ['nome no cartão', 'nome no cartao', 'cartão', 'cartao', 'card_name'],
            'amount' => ['valor', 'amount', 'valor total'],
            'payer' => ['pagador', 'payer'],
            'participant' => ['participante', 'participant', 'pessoa'],
            'payment_method' => ['forma', 'forma de pagamento', 'pagamento', 'payment_method'],
            'category' => ['categoria', 'category'],
            'origin' => ['origem', 'origin', 'tipo'],
        ];
        $mapping = [];
        foreach ($aliases as $field => $names) {
            $mapping[$field] = collect($headers)->first(fn (string $header): bool => in_array(mb_strtolower(trim($header)), $names, true));
        }

        return $mapping;
    }
}
