<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadNote>
 */
class LeadNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'created_by' => User::factory(),
            'note' => fake()->paragraph(),
            'follow_up_date' => fake()->optional(0.6)->dateTimeBetween('-10 days', '+10 days')?->format('Y-m-d'),
        ];
    }
}
