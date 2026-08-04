<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'slug' => $this->faker->unique()->slug,
            'ledger_account_id' => ChartOfAccount::factory()->expense(),
            'is_active' => true,
        ];
    }

    public function carRelated(): static
    {
        return $this->state(['is_car_related' => true]);
    }
}
