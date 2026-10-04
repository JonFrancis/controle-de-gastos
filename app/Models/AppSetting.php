<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['monthly_salary_cents', 'automatic_backup_enabled', 'backup_path', 'last_backup_path', 'last_backup_at'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'monthly_salary_cents' => 'integer',
            'automatic_backup_enabled' => 'boolean',
            'last_backup_at' => 'datetime',
        ];
    }
}
