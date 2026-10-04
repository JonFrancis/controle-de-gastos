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

    public function upload(UploadedFile $file): SpreadsheetImport
    {
        return $this->create($file);
    }

    /** @return array{headers: list<string>, rows: list<array<string, string>>, sheet_name: string} */
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

        foreach ($parsed['rows'] as $rowNumber => $row) {
            $import->rows()->create([
                'sheet_name' => $parsed['sheet_name'],
                'row_number' => $rowNumber + 2,
                'raw_data' => $row,
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
        foreach ($import->rows()->orderBy('row_number')->get() as $row) {
            $normalized = $this->normalize($row->raw_data, $mapping);
            $issues = $this->issues($normalized, $import, $batchFingerprints);
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
        $issues = $this->issues($mapped, $row->spreadsheetImport, []);
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

            return $approvedRows->count();
        });
    }

    /** @return array{headers: list<string>, rows: list<array<string, string>>, sheet_name: string} */
    private function readFile(UploadedFile $file): array
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->readXlsx($file->getRealPath(), $file->getClientOriginalName())
            : $this->readDelimited($file->getRealPath(), $file->getClientOriginalName());
    }

    /** @return array{headers: list<string>, rows: list<array<string, string>>, sheet_name: string} */
    private function readDelimited(string $path, string $filename): array
    {
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
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count(array_filter($values, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $values = array_pad($values, count($headers), '');
            $rows[] = array_combine($headers, array_map(fn (mixed $value): string => trim((string) $value), array_slice($values, 0, count($headers))));
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'sheet_name' => pathinfo($filename, PATHINFO_FILENAME) ?: 'CSV'];
    }

    /** @return array{headers: list<string>, rows: list<array<string, string>>, sheet_name: string} */
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
        $sheet = $workbook->xpath('//main:sheets/main:sheet')[0] ?? null;
        $relationshipId = (string) ($sheet['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id'] ?? '');
        $relationship = collect($relationships->xpath('//rel:Relationship'))->first(fn ($item) => (string) $item['Id'] === $relationshipId);
        $target = ltrim(str_replace('\\', '/', (string) ($relationship['Target'] ?? '')), '/');
        $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        $worksheet = simplexml_load_string((string) $archive->getFromName($target));
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

        $worksheet?->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = $worksheet?->xpath('//main:sheetData/main:row') ?: [];
        $parsedRows = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $reference = (string) $cell['r'];
                $column = preg_replace('/\d+/', '', $reference);
                $index = $this->columnIndex($column);
                $value = (string) ($cell->v ?? $cell->is->t ?? '');
                if ((string) $cell['t'] === 's') {
                    $value = $sharedStrings[(int) $value] ?? $value;
                }
                $values[$index] = $value;
            }
            if ($values !== []) {
                ksort($values);
                $parsedRows[] = array_values(array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values));
            }
        }
        $archive->close();

        $headers = array_map(fn (mixed $header): string => trim((string) $header), array_shift($parsedRows) ?: []);
        $mappedRows = array_map(function (array $values) use ($headers): array {
            $values = array_pad($values, count($headers), '');

            return array_combine($headers, array_map(fn (mixed $value): string => trim((string) $value), array_slice($values, 0, count($headers))));
        }, $parsedRows);

        return ['headers' => $headers, 'rows' => $mappedRows, 'sheet_name' => (string) ($sheet['name'] ?? pathinfo($filename, PATHINFO_FILENAME))];
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

    /** @param array<string, mixed> $normalized @param array<string, bool> $batchFingerprints @return list<string> */
    private function issues(array &$normalized, SpreadsheetImport $import, array $batchFingerprints): array
    {
        $issues = [];
        $selfId = Participant::query()->where('is_default', true)->value('id');
        $normalized['payer_id'] = $this->validCatalogId(Participant::class, $normalized['payer_id'] ?? null) ?? $this->findId(Participant::class, $normalized['payer_name'] ?? '', $selfId);
        $normalized['participant_id'] = $this->validCatalogId(Participant::class, $normalized['participant_id'] ?? null) ?? $this->findId(Participant::class, $normalized['participant_name'] ?? '', $selfId);
        $normalized['payment_method_id'] = $this->validCatalogId(PaymentMethod::class, $normalized['payment_method_id'] ?? null) ?? $this->findId(PaymentMethod::class, $normalized['payment_method_name'] ?? '');
        $normalized['category_id'] = $this->validCatalogId(Category::class, $normalized['category_id'] ?? null) ?? $this->findId(Category::class, $normalized['category_name'] ?? '', null, true);

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
        if ($fingerprint !== null && (isset($batchFingerprints[$fingerprint]) || $this->matchesExistingPurchase($normalized))) {
            $issues[] = 'possible_duplicate';
        }

        return array_values(array_unique($issues));
    }

    private function validCatalogId(string $model, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $model::query()->where('active', true)->whereKey((int) $id)->value('id');
    }

    private function findId(string $model, string $name, ?int $fallback = null, bool $nullable = false): ?int
    {
        if ($name === '') {
            return $nullable ? null : $fallback;
        }

        return $model::query()->where('active', true)->get(['id', 'name'])->first(fn ($item): bool => mb_strtolower(trim($item->name)) === mb_strtolower(trim($name)))?->id;
    }

    private function parseDate(string $value): ?string
    {
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

    /** @param array<string, mixed> $normalized */
    private function matchesExistingPurchase(array $normalized): bool
    {
        return Purchase::query()
            ->whereDate('purchased_at', $normalized['purchased_at'])
            ->where('amount_cents', $normalized['amount_cents'])
            ->where('payment_method_id', $normalized['payment_method_id'])
            ->whereRaw('lower(description) = ?', [mb_strtolower($normalized['description'])])
            ->exists();
    }
}
