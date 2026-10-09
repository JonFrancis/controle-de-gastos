<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['start_date', 'description', 'card_name', 'total_cents', 'installment_count', 'allocation_mode', 'payer_id', 'participant_id', 'payment_method_id', 'category_id', 'archived_at'])]
class Installment extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'date', 'total_cents' => 'integer', 'installment_count' => 'integer', 'archived_at' => 'datetime'];
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

    public function occurrences(): HasMany
    {
        return $this->hasMany(InstallmentOccurrence::class);
    }
}
