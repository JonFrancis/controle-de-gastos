<?php

namespace App\Models;

use Database\Factories\InstallmentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['installment_occurrence_id', 'participant_id', 'category_id', 'amount_cents', 'percentage_basis_points'])]
class InstallmentAllocation extends Model
{
    /** @use HasFactory<InstallmentAllocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'percentage_basis_points' => 'integer'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(InstallmentOccurrence::class, 'installment_occurrence_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
