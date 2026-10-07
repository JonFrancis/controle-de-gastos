<?php

namespace Database\Factories;

use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpreadsheetImportRow>
 */
class SpreadsheetImportRowFactory extends Factory
{
    protected $model = SpreadsheetImportRow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['spreadsheet_import_id' => SpreadsheetImport::factory(), 'sheet_name' => 'Planilha1', 'row_number' => 2, 'raw_data' => ['Data' => '12/10/2026', 'Descrição' => 'Compra', 'Valor' => '10,00'], 'mapped_data' => null, 'issues' => [], 'duplicate_fingerprint' => null, 'status' => 'pending_review', 'purchase_id' => null, 'reviewed_at' => null];
    }
}
