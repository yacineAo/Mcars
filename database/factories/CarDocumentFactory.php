<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarDocumentType;
use App\Models\Car;
use App\Models\CarDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarDocument>
 */
class CarDocumentFactory extends Factory
{
    protected $model = CarDocument::class;

    public function definition(): array
    {
        return [
            'car_id' => Car::factory(),
            'type' => fake()->randomElement(CarDocumentType::cases()),
            'number' => strtoupper(fake()->bothify('DOC-######')),
            'issuer' => fake()->company(),
            'issue_date' => now()->subMonth(),
            'expiry_date' => fake()->dateTimeBetween('+1 month', '+2 years')->format('Y-m-d'),
            'reminder_days_before' => 30,
        ];
    }
}
