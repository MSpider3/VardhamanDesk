<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'source' => fake()->randomElement(LeadSource::cases()),
            'assigned_to' => User::factory(),
            'status' => LeadStatus::NEW,
            'next_follow_up_date' => fake()->optional(0.7)->dateTimeBetween('-10 days', '+10 days')?->format('Y-m-d'),
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'assigned_to' => $user->id,
        ]);
    }

    public function status(LeadStatus|string $status): static
    {
        return $this->state(fn () => [
            'status' => $status instanceof LeadStatus ? $status : LeadStatus::from($status),
        ]);
    }

    public function source(LeadSource|string $source): static
    {
        return $this->state(fn () => [
            'source' => $source instanceof LeadSource ? $source : LeadSource::from($source),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'next_follow_up_date' => Carbon::yesterday()->subDays(2)->format('Y-m-d'),
        ]);
    }

    public function today(): static
    {
        return $this->state(fn () => [
            'next_follow_up_date' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn () => [
            'next_follow_up_date' => Carbon::tomorrow()->addDays(2)->format('Y-m-d'),
        ]);
    }
}
