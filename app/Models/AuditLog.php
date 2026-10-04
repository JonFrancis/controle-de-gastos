<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'metadata'])]
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_RESTORE = 'restore';

    public const ACTION_IMPORT = 'import';

    /** @return list<string> */
    public static function actions(): array
    {
        return [
            self::ACTION_CREATE,
            self::ACTION_UPDATE,
            self::ACTION_ARCHIVE,
            self::ACTION_RESTORE,
            self::ACTION_IMPORT,
        ];
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
