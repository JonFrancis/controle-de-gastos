<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_method_id', 'closing_day', 'due_day', 'effective_from'])]
class PaymentMethodInvoiceSetting extends Model
{
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    protected function casts(): array
    {
        return [
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'effective_from' => 'date',
        ];
    }
}
