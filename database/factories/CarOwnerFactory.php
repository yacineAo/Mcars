<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CarOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarOwner>
 */
class CarOwnerFactory extends Factory
{
    protected $model = CarOwner::class;

    public function definition(): array
    {
        return [
            'type' => 'individual',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '0'.fake()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
