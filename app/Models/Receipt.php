<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['participant_id', 'received_at', 'amount_cents', 'note', 'is_manually_adjusted', 'archived_at'])]
class Receipt extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'date', 'amount_cents' => 'integer', 'is_manually_adjusted' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class)->whereNull('superseded_at');
    }

    public function applicationHistory(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }
}
