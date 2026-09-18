<?php

namespace Database\Factories;

use App\Models\GstRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GstRate>
 */
class GstRateFactory extends Factory
{
    protected $model = GstRate::class;

    public function definition(): array
    {
        return [
            'rate' => '18.00',
            'label' => '18% GST',
            'is_active' => true,
        ];
    }
}
