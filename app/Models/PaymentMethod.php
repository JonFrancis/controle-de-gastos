<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return ['closing_day' => 'integer', 'active' => 'boolean'];
    }
}
