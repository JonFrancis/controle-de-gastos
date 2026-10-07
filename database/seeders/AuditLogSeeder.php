<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use Illuminate\Database\Seeder;

class AuditLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AuditLog::factory()->create(['action' => AuditLog::ACTION_IMPORT, 'metadata' => ['source' => 'seed', 'records' => 1]]);
    }
}
