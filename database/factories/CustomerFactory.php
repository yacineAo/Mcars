<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code' => null,
            'type' => 'individual',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '0'.fake()->numerify('#########'),
            'national_id' => fake()->numerify('##############'),
            'driving_license_number' => strtoupper(fake()->bothify('LIC-#######')),
            'is_active' => true,
        ];
    }
}
