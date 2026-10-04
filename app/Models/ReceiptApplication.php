<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'source_type', 'source_id', 'purchase_allocation_id', 'amount_cents', 'source', 'superseded_at'])]
class ReceiptApplication extends Model
{
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'superseded_at' => 'datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function purchaseAllocation(): BelongsTo
    {
        return $this->belongsTo(PurchaseAllocation::class);
    }
}
