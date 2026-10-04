<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use App\Services\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpreadsheetImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_spreadsheet_is_uploaded_and_previewed_before_mapping(): void
    {
        Storage::fake('local');

        $response = $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('historico.csv', "Data;Descrição;Valor\n12/10/2026;Mercado;123,45\n", 'text/csv'),
        ]);

        $import = SpreadsheetImport::query()->firstOrFail();

        $response->assertRedirect(route('imports.mapping', $import));
        $this->assertDatabaseHas('spreadsheet_imports', [
            'id' => $import->id,
            'original_filename' => 'historico.csv',
            'status' => 'mapping',
        ]);
        $this->assertDatabaseHas('spreadsheet_import_rows', [
            'spreadsheet_import_id' => $import->id,
            'sheet_name' => 'historico',
            'row_number' => 2,
        ]);

        $this->get(route('imports.mapping', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Mapping')
                ->where('import.original_filename', 'historico.csv')
                ->where('headers', ['Data', 'Descrição', 'Valor'])
                ->has('rows', 1));
    }

    public function test_xlsx_import_reads_all_worksheets_preserves_provenance_and_normalizes_serial_dates(): void
    {
        Storage::fake('local');
        [$self, $maria, $pix, $category] = $this->catalogs();

        $xlsx = $this->xlsxWithSheets([
            'Outubro' => [
                ['Data', 'Descrição', 'Valor', 'Pagador', 'Participante', 'Forma', 'Categoria', 'Origem'],
                ['46307', 'Mercado', '123,45', 'Eu', 'Maria', 'Pix', 'Casa', 'Manual'],
            ],
            'Novembro' => [
                ['Data', 'Descrição', 'Valor', 'Pagador', 'Participante', 'Forma', 'Categoria', 'Origem'],
                ['01/11/2026', 'Farmácia', '50,00', 'Eu', 'Eu', 'Pix', 'Casa', 'Manual'],
            ],
        ]);
        $response = $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('historico.xlsx', $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();
        $rows = $import->rows()->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Outubro', $rows[0]->sheet_name);
        $this->assertSame(2, $rows[0]->row_number);
        $this->assertSame('Novembro', $rows[1]->sheet_name);
        $this->assertSame(2, $rows[1]->row_number);
        $this->assertContains('Descrição', $import->headers);

        $this->post(route('imports.map', $import), [
            'mapping' => [
                'purchased_at' => 'Data',
                'description' => 'Descrição',
                'amount' => 'Valor',
                'payer' => 'Pagador',
                'participant' => 'Participante',
                'payment_method' => 'Forma',
                'category' => 'Categoria',
                'origin' => 'Origem',
            ],
        ])->assertRedirect(route('imports.review', $import));

        $this->assertSame('2026-10-12', $rows[0]->fresh()->mapped_data['purchased_at']);
        $this->assertSame($self->id, $rows[0]->fresh()->mapped_data['payer_id']);
        $this->assertSame($maria->id, $rows[0]->fresh()->mapped_data['participant_id']);
        $this->assertSame($pix->id, $rows[0]->fresh()->mapped_data['payment_method_id']);
        $this->assertSame($category->id, $rows[0]->fresh()->mapped_data['category_id']);
    }

    public function test_xlsx_1904_epoch_maps_boundary_serials_to_the_correct_dates(): void
    {
        Storage::fake('local');
        $this->catalogs();
        $xlsx = $this->xlsxWithSheets([
            'Janeiro' => [
                ['Data', 'Descrição', 'Valor', 'Forma'],
                ['0', 'Primeiro dia', '10,00', 'Pix'],
                ['1', 'Segundo dia', '20,00', 'Pix'],
            ],
        ], true);
        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('1904.xlsx', $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();
        $this->post(route('imports.map', $import), ['mapping' => [
            'purchased_at' => 'Data',
            'description' => 'Descrição',
            'amount' => 'Valor',
            'payment_method' => 'Forma',
        ]])->assertRedirect(route('imports.review', $import));

        $rows = $import->rows()->orderBy('row_number')->get();
        $this->assertSame('1904-01-01', $rows[0]->mapped_data['purchased_at']);
        $this->assertSame('1904-01-02', $rows[1]->mapped_data['purchased_at']);
    }

    public function test_multi_sheet_mapping_rejects_required_headers_missing_from_a_sheet(): void
    {
        Storage::fake('local');
        $this->catalogs();
        $xlsx = $this->xlsxWithSheets([
            'Completa' => [
                ['Data', 'Descrição', 'Valor'],
                ['12/10/2026', 'Compra completa', '10,00'],
            ],
            'Incompleta' => [
                ['Data', 'Descrição'],
                ['13/10/2026', 'Compra sem valor'],
            ],
        ]);

        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('abas.xlsx', $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();

        $this->from(route('imports.mapping', $import))->post(route('imports.map', $import), ['mapping' => [
            'purchased_at' => 'Data',
            'description' => 'Descrição',
            'amount' => 'Valor',
        ]])->assertRedirect(route('imports.mapping', $import))->assertSessionHasErrors('mapping.amount');
    }

    public function test_mapping_detects_unknown_values_dates_and_duplicates_without_importing_them(): void
    {
        Storage::fake('local');
        [$self, $maria, $pix, $category] = $this->catalogs();
        Purchase::create([
            'purchased_at' => '2026-10-12',
            'description' => 'Mercado',
            'amount_cents' => 12345,
            'payer_id' => $self->id,
            'participant_id' => $self->id,
            'payment_method_id' => $pix->id,
            'category_id' => $category->id,
        ]);

        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('historico.csv', implode("\n", [
                'Data;Descrição;Valor;Pagador;Participante;Forma;Categoria;Origem',
                '12/10/2026;Mercado;123,45;Eu;Eu;Pix;Casa;Manual',
                '31/12/2026;Presente;10,00;Eu;Pessoa desconhecida;Pix;;Recorrência',
            ]), 'text/csv'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();

        $this->post(route('imports.map', $import), [
            'mapping' => [
                'purchased_at' => 'Data',
                'description' => 'Descrição',
                'amount' => 'Valor',
                'payer' => 'Pagador',
                'participant' => 'Participante',
                'payment_method' => 'Forma',
                'category' => 'Categoria',
                'origin' => 'Origem',
            ],
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ])->assertRedirect(route('imports.review', $import));

        $rows = SpreadsheetImportRow::query()->orderBy('row_number')->get();

        $this->assertContains('possible_duplicate', $rows[0]->issues);
        $this->assertContains('date_outside_period', $rows[1]->issues);
        $this->assertContains('unknown_participant', $rows[1]->issues);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_confirmation_imports_only_approved_rows_and_keeps_provenance_and_origin(): void
    {
        Storage::fake('local');
        [$self, $maria, $pix, $category] = $this->catalogs();

        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('historico.csv', implode("\n", [
                'Data,Descrição,Valor,Pagador,Participante,Forma,Categoria,Origem',
                '12/10/2026,Assinatura,50.00,Eu,Maria,Pix,Casa,Recorrência',
                '13/10/2026,Sem forma,20.00,Eu,Eu,,Casa,Manual',
            ]), 'text/csv'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();

        $this->post(route('imports.map', $import), [
            'mapping' => [
                'purchased_at' => 'Data',
                'description' => 'Descrição',
                'amount' => 'Valor',
                'payer' => 'Pagador',
                'participant' => 'Participante',
                'payment_method' => 'Forma',
                'category' => 'Categoria',
                'origin' => 'Origem',
            ],
        ]);
        $approved = $import->rows()->where('row_number', 2)->firstOrFail();
        $pending = $import->rows()->where('row_number', 3)->firstOrFail();

        $this->patch(route('imports.rows.update', [$import, $approved]), ['action' => 'approved'])
            ->assertRedirect(route('imports.review', $import));
        $this->post(route('imports.confirm', $import))
            ->assertRedirect(route('imports.review', $import));

        $purchase = Purchase::query()->where('description', 'Assinatura')->firstOrFail();
        $this->assertSame('recurrence', $purchase->origin);
        $this->assertSame($maria->id, $purchase->participant_id);
        $this->assertDatabaseHas('spreadsheet_import_rows', [
            'id' => $approved->id,
            'status' => 'imported',
            'purchase_id' => $purchase->id,
            'sheet_name' => 'historico',
            'row_number' => 2,
        ]);
        $this->assertSame('pending_review', $pending->fresh()->status);
        $this->assertDatabaseCount('purchases', 1);
        $csv = $this->get('/exports/csv/purchases?mode=history');
        $csv->assertDownload('purchases-historico-completo.csv');
        $this->assertStringContainsString('historico.csv', $csv->streamedContent());
        $this->assertStringContainsString('historico', $csv->streamedContent());
        $this->assertStringContainsString(';2;', $csv->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'import',
        ]);
        $this->get('/history?action=import')
            ->assertInertia(fn (Assert $page) => $page
                ->component('History/Index')
                ->has('logs', 1)
                ->where('logs.0.metadata.source', 'historico.csv')
                ->where('logs.0.metadata.records', 1));
        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('origins.recurrence.count', 1));
        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('pendingReview', 1));
    }

    public function test_mapping_uses_cached_catalogs_and_existing_purchase_fingerprints(): void
    {
        Storage::fake('local');
        $this->catalogs();
        $rows = collect(range(1, 4))->map(fn (int $number): string => "12/10/2026;Compra {$number};10,00;Eu;Eu;Pix;Casa;Manual")->implode("\n");

        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('historico.csv', "Data;Descrição;Valor;Pagador;Participante;Forma;Categoria;Origem\n{$rows}\n", 'text/csv'),
        ]);
        $import = SpreadsheetImport::query()->firstOrFail();
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        app(SpreadsheetImportService::class)->map($import, [
            'purchased_at' => 'Data',
            'description' => 'Descrição',
            'amount' => 'Valor',
            'payer' => 'Pagador',
            'participant' => 'Participante',
            'payment_method' => 'Forma',
            'category' => 'Categoria',
            'origin' => 'Origem',
        ], null, null);

        $selects = collect(DB::connection()->getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'));
        DB::connection()->disableQueryLog();

        $this->assertLessThanOrEqual(10, $selects->count());
        $this->assertDatabaseCount('spreadsheet_import_rows', 4);
    }

    /** @return array{Participant, Participant, PaymentMethod, Category} */
    private function catalogs(): array
    {
        $self = Participant::query()->where('is_default', true)->firstOrFail();
        $maria = Participant::create(['name' => 'Maria', 'active' => true, 'is_default' => false]);
        $pix = PaymentMethod::create(['name' => 'Pix', 'type' => PaymentMethod::TYPE_PIX, 'active' => true]);
        $category = Category::create(['name' => 'Casa', 'active' => true]);

        return [$self, $maria, $pix, $category];
    }

    /** @param array<string, list<list<string>>> $sheets */
    private function xlsxWithSheets(array $sheets, bool $date1904 = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet-test-');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $sheetNodes = [];
        $relationshipNodes = [];
        foreach (array_values($sheets) as $index => $rows) {
            $sheetNumber = $index + 1;
            $sheetName = array_keys($sheets)[$index];
            $sheetNodes[] = '<sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
            $relationshipNodes[] = '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';
            $sheetXml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $rowIndex => $row) {
                $sheetXml .= '<row r="'.($rowIndex + 1).'">';
                foreach ($row as $columnIndex => $value) {
                    $column = chr(65 + $columnIndex);
                    $sheetXml .= '<c r="'.$column.($rowIndex + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
                $sheetXml .= '</row>';
            }
            $archive->addFromString('xl/worksheets/sheet'.$sheetNumber.'.xml', $sheetXml.'</sheetData></worksheet>');
        }
        $workbookProperties = $date1904 ? '<workbookPr date1904="1"/>' : '';
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$workbookProperties.'<sheets>'.implode('', $sheetNodes).'</sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationshipNodes).'</Relationships>');
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
        $archive->close();
        $contents = file_get_contents($path);
        unlink($path);

        return $contents ?: '';
    }
}
