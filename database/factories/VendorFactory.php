<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(['garage', 'insurance', 'parts', 'fuel_station', 'towing', 'other']),
            'contact_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }
}
