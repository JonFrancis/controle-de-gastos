<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['installment_id', 'installment_number', 'purchased_at', 'description', 'card_name', 'amount_cents', 'payer_id', 'participant_id', 'payment_method_id', 'category_id', 'is_adjusted', 'archived_at'])]
class InstallmentOccurrence extends Model
{
    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'installment_number' => 'integer', 'amount_cents' => 'integer', 'is_adjusted' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
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
        return $this->hasMany(InstallmentAllocation::class);
    }
}
