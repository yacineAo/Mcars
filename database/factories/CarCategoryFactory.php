<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CarCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarCategoryFactory extends Factory
{
    protected $model = CarCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Economy', 'Compact', 'SUV', 'Luxury', 'Utility', 'Van']),
            'slug' => fn (array $attrs) => strtolower($attrs['name']),
            'description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
