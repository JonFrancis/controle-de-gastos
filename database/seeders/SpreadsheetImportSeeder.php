<?php

namespace Database\Seeders;

use App\Models\SpreadsheetImport;
use App\Models\SpreadsheetImportRow;
use Illuminate\Database\Seeder;

class SpreadsheetImportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $import = SpreadsheetImport::factory()->create();
        SpreadsheetImportRow::factory()->for($import, 'spreadsheetImport')->create();
    }
}
