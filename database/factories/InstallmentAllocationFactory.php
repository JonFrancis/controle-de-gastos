<?php

namespace Database\Factories;

use App\Models\InstallmentAllocation;
use App\Models\InstallmentOccurrence;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentAllocation>
 */
class InstallmentAllocationFactory extends Factory
{
    protected $model = InstallmentAllocation::class;

    /**
     * Define the model's default state.
     *
     * The occurrence and participant are required domain context, so tests
     * should provide them with forOccurrence() and forParticipant().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installment_occurrence_id' => null,
            'participant_id' => null,
            'category_id' => null,
            'amount_cents' => 0,
            'percentage_basis_points' => null,
        ];
    }

    public function forOccurrence(InstallmentOccurrence $occurrence): static
    {
        return $this->state(['installment_occurrence_id' => $occurrence->getKey()]);
    }

    public function forParticipant(?Participant $participant): static
    {
        return $this->state(['participant_id' => $participant?->getKey()]);
    }
}
