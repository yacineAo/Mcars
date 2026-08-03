<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Wilaya;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $city = fake()->randomElement([
            'Alger', 'Oran', 'Constantine', 'Annaba', 'Blida', 'Sétif', 'Batna',
        ]);

        return [
            'name' => "Agence {$city}",
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            'address' => fake()->streetAddress(),
            'city' => $city,
            // The wilaya column is constrained to Wilaya enum values (Round 35);
            // the display city is free text, the wilaya is not.
            'wilaya' => fake()->randomElement(Wilaya::values()),
            'phone' => '0'.fake()->numerify('#########'),
            'email' => fake()->unique()->companyEmail(),
            'timezone' => 'Africa/Algiers',
            'is_active' => true,
            'is_default' => false,
        ];
    }

    /**
     * The single default branch. The partial unique index on the table means
     * only one row may carry this at a time.
     */
    public function default(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
