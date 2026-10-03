<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active', 'is_default'])]
class Participant extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_default' => 'boolean'];
    }
}
