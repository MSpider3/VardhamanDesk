<?php

namespace Database\Factories;

use App\Enums\IndianState;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'lead_id' => null,
            'assigned_to' => User::factory()->sales(),
            'created_by' => User::factory()->sales(),
            'name' => fake()->name(),
            'company' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'billing_address' => fake()->address(),
            'state' => IndianState::RAJASTHAN->value,
            'gstin' => '08ABCDE1234F1Z5',
        ];
    }
}
