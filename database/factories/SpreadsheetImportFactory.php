<?php

namespace Database\Factories;

use App\Models\SpreadsheetImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpreadsheetImport>
 */
class SpreadsheetImportFactory extends Factory
{
    protected $model = SpreadsheetImport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['original_filename' => 'historico.xlsx', 'stored_path' => 'imports/'.fake()->uuid().'.xlsx', 'file_hash' => hash('sha256', fake()->uuid()), 'status' => 'mapping', 'headers' => ['Data', 'Descrição', 'Valor'], 'column_mapping' => null, 'period_start' => null, 'period_end' => null, 'confirmed_at' => null];
    }
}
