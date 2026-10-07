<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['action' => AuditLog::ACTION_CREATE, 'auditable_type' => null, 'auditable_id' => null, 'old_values' => null, 'new_values' => ['description' => fake()->sentence(3)], 'metadata' => []];
    }
}
