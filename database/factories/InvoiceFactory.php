<?php

namespace Database\Factories;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'invoice_number' => null,
            'financial_year' => null,
            'sequence_number' => null,
            'place_of_supply' => IndianState::RAJASTHAN->value,
            'status' => InvoiceStatus::DRAFT,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '0.00',
            'cgst_amount' => '0.00',
            'sgst_amount' => '0.00',
            'igst_amount' => '0.00',
            'total' => '0.00',
            'created_by' => User::factory()->sales(),
        ];
    }
}
