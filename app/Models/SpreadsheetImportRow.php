<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['spreadsheet_import_id', 'sheet_name', 'row_number', 'raw_data', 'mapped_data', 'issues', 'duplicate_fingerprint', 'status', 'purchase_id', 'reviewed_at'])]
class SpreadsheetImportRow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'mapped_data' => 'array',
            'issues' => 'array',
            'row_number' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function spreadsheetImport(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetImport::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
