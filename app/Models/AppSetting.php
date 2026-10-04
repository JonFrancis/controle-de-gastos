<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['monthly_salary_cents'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return ['monthly_salary_cents' => 'integer'];
    }
}
