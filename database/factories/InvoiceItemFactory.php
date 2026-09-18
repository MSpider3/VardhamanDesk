<?php

namespace Database\Factories;

use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(3),
            'sac_code' => '998313',
            'quantity' => '1.00',
            'rate' => '1000.00',
            'amount' => '1000.00',
            'gst_rate_id' => GstRate::factory(),
            'cgst_amount' => '90.00',
            'sgst_amount' => '90.00',
            'igst_amount' => '0.00',
        ];
    }
}
