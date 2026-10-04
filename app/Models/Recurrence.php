<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['start_date', 'end_date', 'day_of_month', 'description', 'card_name', 'amount_cents', 'payer_id', 'participant_id', 'payment_method_id', 'category_id', 'active', 'archived_at'])]
class Recurrence extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'day_of_month' => 'integer', 'amount_cents' => 'integer', 'active' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true)->whereNull('archived_at');
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
        return $this->hasMany(RecurrenceOccurrence::class);
    }
}
