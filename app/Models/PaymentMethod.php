<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'type', 'closing_day', 'active'])]
class PaymentMethod extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const TYPE_PIX = 'pix';

    public const TYPE_CASH = 'cash';

    public const TYPE_OTHER = 'other';

    public static function types(): array
    {
        return [self::TYPE_CREDIT, self::TYPE_DEBIT, self::TYPE_PIX, self::TYPE_CASH, self::TYPE_OTHER];
    }

    protected static function booted(): void
    {
        static::created(function (self $paymentMethod): void {
            if ($paymentMethod->type === self::TYPE_CREDIT && $paymentMethod->closing_day !== null) {
                $paymentMethod->invoiceSettings()->create([
                    'closing_day' => $paymentMethod->closing_day,
                    'effective_from' => $paymentMethod->created_at?->toDateString() ?? today()->toDateString(),
                ]);
            }
        });
    }

    public function invoiceSettings(): HasMany
    {
        return $this->hasMany(PaymentMethodInvoiceSetting::class)->orderByDesc('effective_from');
    }

    public function latestInvoiceSetting(): HasOne
    {
        return $this->hasOne(PaymentMethodInvoiceSetting::class)->latestOfMany('effective_from');
    }

    protected function casts(): array
    {
        return ['closing_day' => 'integer', 'active' => 'boolean'];
    }
}
