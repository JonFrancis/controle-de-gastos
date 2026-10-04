<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->get('/analysis?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('origins.recurrence.count', 1));
        $this->get('/?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('pendingReview', 1));
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
}
