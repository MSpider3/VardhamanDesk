<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => '1000.00',
            'payment_date' => now()->toDateString(),
            'method' => PaymentMethod::BANK_TRANSFER,
            'reference_note' => 'UTR'.fake()->numerify('##########'),
            'recorded_by' => User::factory()->sales(),
        ];
    }
}
