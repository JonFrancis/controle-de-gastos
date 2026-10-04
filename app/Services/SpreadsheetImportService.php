<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class SpreadsheetImportService
{
    private bool $usesExcel1904DateSystem = false;

    public const FIELDS = [
        'purchased_at',
        'description',
        'card_name',
        'amount',
        'payer',
        'participant',
        'payment_method',
        'category',
        'origin',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function upload(UploadedFile $file): SpreadsheetImport
    {
        return $this->create($file);
    }

    /** @return array{headers: list<string>, rows: list<array{sheet_name: string, row_number: int, raw_data: array<string, string>>>, sheet_name: string} */
    public function create(UploadedFile $file): SpreadsheetImport
    {
        $parsed = $this->readFile($file);
        $storedPath = $file->storeAs('imports', Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        if ($storedPath === false) {
            throw new RuntimeException('Não foi possível armazenar a planilha para revisão.');
        }

        $import = SpreadsheetImport::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => hash_file('sha256', $file->getRealPath()) ?: '',
            'status' => 'mapping',
            'headers' => $parsed['headers'],
        ]);

        foreach ($parsed['rows'] as $row) {
            $import->rows()->create([
                'sheet_name' => $row['sheet_name'],
                'row_number' => $row['row_number'],
                'raw_data' => $row['raw_data'],
                'status' => 'pending_review',
            ]);
        }

        return $import;
    }

    /** @param array<string, string|null> $mapping */
    public function map(SpreadsheetImport $import, array $mapping, ?string $periodStart, ?string $periodEnd): void
    {
        if ($import->status === 'confirmed') {
            throw ValidationException::withMessages(['import' => 'Uma importação confirmada não pode ser remapeada.']);
        }

        $this->validateMapping($import, $mapping);
        $import->update([
            'column_mapping' => $mapping,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'review',
        ]);

        $batchFingerprints = [];
        $lookups = $this->lookupContext($import);
        foreach ($import->rows()->orderBy('id')->lazyById(200) as $row) {
            $normalized = $this->normalize($row->raw_data, $mapping);
            $issues = $this->issues($normalized, $import, $batchFingerprints, $lookups);
            $fingerprint = $normalized['fingerprint'] ?? null;
            if ($fingerprint !== null) {
                $batchFingerprints[$fingerprint] = true;
            }

            $row->update([
                'mapped_data' => $normalized,
                'issues' => $issues,
                'duplicate_fingerprint' => $fingerprint,
                'status' => 'pending_review',
                'purchase_id' => null,
                'reviewed_at' => null,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public function review(SpreadsheetImportRow $row, array $data): void
    {
        if ($row->status === 'imported') {
            throw ValidationException::withMessages(['row' => 'Uma linha já importada não pode ser alterada.']);
        }

        $mapped = $row->mapped_data ?? [];
        $changes = [
            'date_input' => 'purchased_at',
            'description' => 'description',
            'card_name' => 'card_name',
            'amount_input' => 'amount',
            'payer' => 'payer_name',
            'participant' => 'participant_name',
            'payment_method' => 'payment_method_name',
            'category' => 'category_name',
            'origin' => 'origin',
        ];
        foreach ($changes as $input => $field) {
            if (array_key_exists($input, $data)) {
                $mapped[$field] = $data[$input];
            }
        }
        foreach (['purchased_at', 'description', 'card_name', 'amount', 'payer_id', 'participant_id', 'payment_method_id', 'category_id', 'origin'] as $field) {
            if (array_key_exists($field, $data)) {
                $mapped[$field] = $data[$field];
            }
        }

        $mapped['purchased_at'] = $this->parseDate((string) ($mapped['purchased_at'] ?? ''));
        $mapped['amount_cents'] = $this->parseAmount($mapped['amount'] ?? $mapped['amount_cents'] ?? null);
        $issues = $this->issues($mapped, $row->spreadsheetImport, [], $this->lookupContext($row->spreadsheetImport));
        $action = $data['action'] ?? 'pending_review';
        $blockingIssues = collect($issues)->reject(fn (string $issue): bool => in_array($issue, ['possible_duplicate', 'date_outside_period'], true));
        $status = $action === 'rejected' ? 'rejected' : ($blockingIssues->isEmpty() && $action === 'approved' ? 'approved' : 'pending_review');

        $row->update([
            'mapped_data' => $mapped,
            'issues' => $issues,
            'status' => $status,
            'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null,
        ]);
    }

    public function confirm(SpreadsheetImport $import): int
    {
        $approvedRows = $import->rows()->where('status', 'approved')->whereNull('purchase_id')->get();
        if ($approvedRows->isEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Revise e aprove ao menos um lançamento válido antes de confirmar.']);
        }

        return DB::transaction(function () use ($import, $approvedRows): int {
            foreach ($approvedRows as $row) {
                $data = $row->mapped_data;
                $purchase = Purchase::create([
                    'purchased_at' => $data['purchased_at'],
                    'description' => $data['description'],
                    'card_name' => $data['card_name'] ?: null,
                    'amount_cents' => $data['amount_cents'],
                    'origin' => $data['origin'],
                    'payer_id' => $data['payer_id'],
                    'participant_id' => $data['participant_id'],
                    'payment_method_id' => $data['payment_method_id'],
                    'category_id' => $data['category_id'] ?: null,
                ]);

                $row->update([
                    'status' => 'imported',
                    'purchase_id' => $purchase->id,
                    'reviewed_at' => now(),
                ]);
            }

            $import->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $this->auditService->recordImport($import->original_filename, $approvedRows->count());

            return $approvedRows->count();
        });
    }

    /** @return array{headers: list<string>, rows: list<array{sheet_name: string, row_number: int, raw_data: array<string, string>>>, sheet_name: string} */
    private function readFile(UploadedFile $file): array
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->readXlsx($file->getRealPath(), $file->getClientOriginalName())
            : $this->readDelimited($file->getRealPath(), $file->getClientOriginalName());
    }

    /** @return array{headers: list<string>, rows: list<array{sheet_name: string, row_number: int, raw_data: array<string, string>>>, sheet_name: string} */
    private function readDelimited(string $path, string $filename): array
    {
        $this->usesExcel1904DateSystem = false;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler a planilha.']);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'A planilha está vazia.']);
        }

        $delimiter = $this->delimiter($firstLine);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = array_map(fn (mixed $header): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $header)), $headers ?: []);
        if (count($headers) < 2 || count(array_filter($headers, fn (string $header): bool => $header !== '')) < 2) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'A planilha precisa ter pelo menos duas colunas.']);
        }

        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $values = array_pad($values, count($headers), '');
            $rows[] = [
                'sheet_name' => pathinfo($filename, PATHINFO_FILENAME) ?: 'CSV',
                'row_number' => $rowNumber,
                'raw_data' => array_combine($headers, array_map(fn (mixed $value): string => trim((string) $value), array_slice($values, 0, count($headers)))),
            ];
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'sheet_name' => pathinfo($filename, PATHINFO_FILENAME) ?: 'CSV'];
    }

    /** @return array{headers: list<string>, rows: list<array{sheet_name: string, row_number: int, raw_data: array<string, string>>>, sheet_name: string} */
    private function readXlsx(string $path, string $filename): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo XLSX.']);
        }

        $workbook = simplexml_load_string((string) $archive->getFromName('xl/workbook.xml'));
        $relationships = simplexml_load_string((string) $archive->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook === false || $relationships === false) {
            $archive->close();
            throw ValidationException::withMessages(['file' => 'O arquivo XLSX não possui uma estrutura válida.']);
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $relationships->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $workbookProperties = $workbook->xpath('//main:workbookPr')[0] ?? null;
        $this->usesExcel1904DateSystem = (string) ($workbookProperties['date1904'] ?? '') === '1';
        $sharedStringsXml = $archive->getFromName('xl/sharedStrings.xml');
        $sharedStrings = [];
        if ($sharedStringsXml !== false) {
            $shared = simplexml_load_string($sharedStringsXml);
            if ($shared !== false) {
                foreach ($shared->si as $item) {
                    $sharedStrings[] = (string) collect($item->xpath('.//t'))->implode('');
                }
            }
        }

        $sheets = $workbook->xpath('//main:sheets/main:sheet') ?: [];
        $relationshipsById = collect($relationships->xpath('//rel:Relationship'))->keyBy(fn ($item): string => (string) $item['Id']);
        $headers = [];
        $parsedRows = [];
        $firstSheetName = null;

        foreach ($sheets as $sheet) {
            $sheetName = (string) ($sheet['name'] ?? (pathinfo($filename, PATHINFO_FILENAME) ?: 'XLSX'));
            $firstSheetName ??= $sheetName;
            $sheetRelationships = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($sheetRelationships['id'] ?? $sheet['r:id'] ?? '');
            $relationship = $relationshipsById->get($relationshipId);
            $target = ltrim(str_replace('\\', '/', (string) ($relationship['Target'] ?? '')), '/');
            $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            $worksheet = simplexml_load_string((string) $archive->getFromName($target));
            if ($worksheet === false) {
                continue;
            }

            $worksheet->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = $worksheet->xpath('//main:sheetData/main:row') ?: [];
            $sheetRows = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($row->c ?? [] as $cell) {
                    $reference = (string) $cell['r'];
                    $column = preg_replace('/\d+/', '', $reference);
                    $index = $this->columnIndex($column);
                    $value = (string) ((string) $cell['t'] === 'inlineStr' ? ($cell->is->t ?? '') : ($cell->v ?? ''));
                    if ((string) $cell['t'] === 's') {
                        $value = $sharedStrings[(int) $value] ?? $value;
                    }
                    $values[$index] = $value;
                }
                if ($values !== []) {
                    ksort($values);
                    $sheetRows[] = ['row_number' => (int) ($row['r'] ?? count($sheetRows) + 1), 'values' => array_values(array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values))];
                }
            }

            $headerRow = array_shift($sheetRows);
            $sheetHeaders = array_map(fn (mixed $header): string => trim((string) $header), $headerRow['values'] ?? []);
            if ($sheetHeaders === []) {
                continue;
            }
            $headers = array_values(array_unique([...$headers, ...array_filter($sheetHeaders, fn (string $header): bool => $header !== '')]));
            foreach ($sheetRows as $sheetRow) {
                $values = array_pad($sheetRow['values'], count($sheetHeaders), '');
                $rawData = array_combine($sheetHeaders, array_map(fn (mixed $value): string => trim((string) $value), array_slice($values, 0, count($sheetHeaders))));
                if ($rawData === false || count(array_filter($rawData, fn (string $value): bool => $value !== '')) === 0) {
                    continue;
                }
                $parsedRows[] = ['sheet_name' => $sheetName, 'row_number' => $sheetRow['row_number'], 'raw_data' => $rawData];
            }
        }
        $archive->close();

        if ($headers === [] || $parsedRows === []) {
            throw ValidationException::withMessages(['file' => 'A planilha está vazia.']);
        }

        return ['headers' => $headers, 'rows' => $parsedRows, 'sheet_name' => $firstSheetName ?? (pathinfo($filename, PATHINFO_FILENAME) ?: 'XLSX')];
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];

        return array_key_first(array_filter($counts, fn (int $count): bool => $count === max($counts))) ?: ',';
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split(strtoupper($column)) as $character) {
            $index = $index * 26 + ord($character) - 64;
        }

        return $index - 1;
    }

    /** @param array<string, string|null> $mapping */
    private function validateMapping(SpreadsheetImport $import, array $mapping): void
    {
        $headers = $import->headers;
        foreach (self::FIELDS as $field) {
            $header = $mapping[$field] ?? null;
            if ($header !== null && $header !== '' && ! in_array($header, $headers, true)) {
                throw ValidationException::withMessages(["mapping.{$field}" => 'A coluna selecionada não existe na planilha.']);
            }
        }

        foreach (['purchased_at', 'description', 'amount'] as $field) {
            if (blank($mapping[$field] ?? null)) {
                throw ValidationException::withMessages(["mapping.{$field}" => 'Mapeie esta coluna antes de continuar.']);
            }
        }
    }

    /** @param array<string, string> $raw @param array<string, string|null> $mapping @return array<string, mixed> */
    private function normalize(array $raw, array $mapping): array
    {
        $value = fn (string $field): string => trim((string) ($raw[$mapping[$field] ?? ''] ?? ''));
        $amount = $value('amount');
        $amountCents = $this->parseAmount($amount);
        $origin = $this->parseOrigin($value('origin'));
        $date = $this->parseDate($value('purchased_at'));

        return [
            'purchased_at' => $date,
            'description' => $value('description'),
            'card_name' => $value('card_name'),
            'amount' => $amount,
            'amount_cents' => $amountCents,
            'payer_name' => $value('payer'),
            'participant_name' => $value('participant'),
            'payment_method_name' => $value('payment_method'),
            'category_name' => $value('category'),
            'origin' => $origin,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, bool>  $batchFingerprints
     * @param  array{self_id: int, participants_by_id: array<int, int>, participants_by_name: array<string, int>, payment_methods_by_id: array<int, int>, payment_methods_by_name: array<string, int>, categories_by_id: array<int, int>, categories_by_name: array<string, int>, existing_fingerprints: array<string, bool>}  $lookups
     * @return list<string>
     */
    private function issues(array &$normalized, SpreadsheetImport $import, array $batchFingerprints, array $lookups): array
    {
        $issues = [];
        $normalized['payer_id'] = $this->resolveCatalogId($normalized['payer_id'] ?? null, $normalized['payer_name'] ?? '', $lookups['participants_by_id'], $lookups['participants_by_name'], $lookups['self_id']);
        $normalized['participant_id'] = $this->resolveCatalogId($normalized['participant_id'] ?? null, $normalized['participant_name'] ?? '', $lookups['participants_by_id'], $lookups['participants_by_name'], $lookups['self_id']);
        $normalized['payment_method_id'] = $this->resolveCatalogId($normalized['payment_method_id'] ?? null, $normalized['payment_method_name'] ?? '', $lookups['payment_methods_by_id'], $lookups['payment_methods_by_name']);
        $normalized['category_id'] = $this->resolveCatalogId($normalized['category_id'] ?? null, $normalized['category_name'] ?? '', $lookups['categories_by_id'], $lookups['categories_by_name']);

        if (blank($normalized['purchased_at'])) {
            $issues[] = 'invalid_date';
        }
        if (blank($normalized['description'])) {
            $issues[] = 'missing_description';
        }
        if (($normalized['amount_cents'] ?? null) === null || $normalized['amount_cents'] <= 0) {
            $issues[] = 'invalid_amount';
        }
        if (! blank($normalized['payer_name']) && $normalized['payer_id'] === null) {
            $issues[] = 'unknown_payer';
        }
        if (! blank($normalized['participant_name']) && $normalized['participant_id'] === null) {
            $issues[] = 'unknown_participant';
        }
        if (blank($normalized['payment_method_name'])) {
            $issues[] = 'missing_payment_method';
        } elseif ($normalized['payment_method_id'] === null) {
            $issues[] = 'unknown_payment_method';
        }
        if (! blank($normalized['category_name']) && $normalized['category_id'] === null) {
            $issues[] = 'unknown_category';
        }
        if ($normalized['origin'] === null) {
            $issues[] = 'invalid_origin';
        }

        if ($normalized['purchased_at'] !== null && $import->period_start !== null && $normalized['purchased_at'] < $import->period_start->toDateString()) {
            $issues[] = 'date_outside_period';
        }
        if ($normalized['purchased_at'] !== null && $import->period_end !== null && $normalized['purchased_at'] > $import->period_end->toDateString()) {
            $issues[] = 'date_outside_period';
        }

        $fingerprint = $this->fingerprint($normalized);
        $normalized['fingerprint'] = $fingerprint;
        if ($fingerprint !== null && (isset($batchFingerprints[$fingerprint]) || isset($lookups['existing_fingerprints'][$fingerprint]))) {
            $issues[] = 'possible_duplicate';
        }

        return array_values(array_unique($issues));
    }

    /** @param array<int, int> $ids @param array<string, int> $names */
    private function resolveCatalogId(mixed $id, string $name, array $ids, array $names, ?int $fallback = null): ?int
    {
        if ($id !== null && $id !== '') {
            return $ids[(int) $id] ?? null;
        }

        if ($name === '') {
            return $fallback;
        }

        return $names[mb_strtolower(trim($name))] ?? null;
    }

    private function parseDate(string $value): ?string
    {
        if (is_numeric($value) && (float) $value >= 1) {
            try {
                $epoch = $this->usesExcel1904DateSystem ? Carbon::create(1904, 1, 1)->subDay() : Carbon::create(1899, 12, 30);

                return $epoch->addDays((int) floor((float) $value))->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat('!'.$format, $value)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function parseAmount(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = preg_replace('/[^0-9,.-]/', '', trim((string) $value));
        if ($value === null || $value === '' || substr_count($value, '-') > 1) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            $value = $lastComma === strlen($value) - 4 ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        } elseif ($lastDot !== false && $lastDot !== strlen($value) - 3) {
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function parseOrigin(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            '', 'manual' => Purchase::ORIGIN_MANUAL,
            'recurrence', 'recorrência' => Purchase::ORIGIN_RECURRENCE,
            'installment', 'parcelamento' => Purchase::ORIGIN_INSTALLMENT,
            default => null,
        };
    }

    /** @param array<string, mixed> $normalized */
    private function fingerprint(array $normalized): ?string
    {
        if ($normalized['purchased_at'] === null || $normalized['description'] === '' || ! is_int($normalized['amount_cents']) || $normalized['payment_method_id'] === null) {
            return null;
        }

        return hash('sha256', implode('|', [$normalized['purchased_at'], mb_strtolower($normalized['description']), $normalized['amount_cents'], $normalized['payment_method_id']]));
    }

    private function fingerprintFromPurchase(Purchase $purchase): ?string
    {
        return $this->fingerprint([
            'purchased_at' => $purchase->purchased_at?->toDateString(),
            'description' => $purchase->description,
            'amount_cents' => $purchase->amount_cents,
            'payment_method_id' => $purchase->payment_method_id,
        ]);
    }

    /**
     * @return array{self_id: int, participants_by_id: array<int, int>, participants_by_name: array<string, int>, payment_methods_by_id: array<int, int>, payment_methods_by_name: array<string, int>, categories_by_id: array<int, int>, categories_by_name: array<string, int>, existing_fingerprints: array<string, bool>}
     */
    private function lookupContext(SpreadsheetImport $import): array
    {
        $participants = Participant::query()->where('active', true)->get(['id', 'name', 'is_default']);
        $paymentMethods = PaymentMethod::query()->where('active', true)->get(['id', 'name']);
        $categories = Category::query()->where('active', true)->get(['id', 'name']);
        $existingFingerprints = [];
        Purchase::query()->select(['purchased_at', 'description', 'amount_cents', 'payment_method_id'])
            ->when($import->period_start, fn ($query, Carbon $start) => $query->whereDate('purchased_at', '>=', $start))
            ->when($import->period_end, fn ($query, Carbon $end) => $query->whereDate('purchased_at', '<=', $end))
            ->cursor()
            ->each(function (Purchase $purchase) use (&$existingFingerprints): void {
                $fingerprint = $this->fingerprintFromPurchase($purchase);
                if ($fingerprint !== null) {
                    $existingFingerprints[$fingerprint] = true;
                }
            });

        return [
            'self_id' => (int) $participants->firstWhere('is_default', true)?->id,
            'participants_by_id' => $participants->mapWithKeys(fn (Participant $participant): array => [$participant->id => $participant->id])->all(),
            'participants_by_name' => $participants->mapWithKeys(fn (Participant $participant): array => [mb_strtolower(trim($participant->name)) => $participant->id])->all(),
            'payment_methods_by_id' => $paymentMethods->mapWithKeys(fn (PaymentMethod $method): array => [$method->id => $method->id])->all(),
            'payment_methods_by_name' => $paymentMethods->mapWithKeys(fn (PaymentMethod $method): array => [mb_strtolower(trim($method->name)) => $method->id])->all(),
            'categories_by_id' => $categories->mapWithKeys(fn (Category $category): array => [$category->id => $category->id])->all(),
            'categories_by_name' => $categories->mapWithKeys(fn (Category $category): array => [mb_strtolower(trim($category->name)) => $category->id])->all(),
            'existing_fingerprints' => $existingFingerprints,
        ];
    }
}
