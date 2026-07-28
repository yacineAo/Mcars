<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarStatus;
use App\Enums\OwnershipType;
use App\Models\Car;
use App\Models\CarCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarFactory extends Factory
{
    protected $model = Car::class;

    public function definition(): array
    {
        return [
            'car_category_id' => CarCategory::factory(),
            'ownership_type' => OwnershipType::CompanyOwned,
            'brand' => fake()->randomElement(['Renault', 'Peugeot', 'Dacia', 'Toyota', 'Hyundai']),
            'model' => fake()->randomElement(['Clio', '208', 'Logan', 'Corolla', 'i10']),
            'year' => fake()->year(),
            'chassis_number' => strtoupper(fake()->bothify('VS??????????????')),
            'registration_number' => strtoupper(fake()->bothify('###-??-###')),
            'status' => CarStatus::Available,
            'daily_rate' => fake()->randomFloat(2, 3000, 15000),
            'odometer' => fake()->numberBetween(1000, 100000),
            'is_active' => true,
        ];
    }
}
