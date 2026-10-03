<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchased_at', 'description', 'card_name', 'amount_cents', 'allocation_mode', 'payer_id', 'participant_id', 'payment_method_id', 'category_id', 'archived_at'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'amount_cents' => 'integer', 'archived_at' => 'datetime'];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'payer_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseAllocation::class);
    }
}
