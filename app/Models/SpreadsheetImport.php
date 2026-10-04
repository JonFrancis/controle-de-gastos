<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['original_filename', 'stored_path', 'file_hash', 'status', 'headers', 'sheet_headers', 'uses_excel_1904_date_system', 'column_mapping', 'period_start', 'period_end', 'confirmed_at'])]
class SpreadsheetImport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'sheet_headers' => 'array',
            'uses_excel_1904_date_system' => 'boolean',
            'column_mapping' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SpreadsheetImportRow::class);
    }
}
