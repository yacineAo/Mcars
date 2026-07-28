<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->numerify('####'),
            'name' => $this->faker->word,
            'type' => AccountType::Asset,
            'normal_balance' => NormalBalance::Debit,
            'is_postable' => true,
            'is_active' => true,
        ];
    }

    public function cashEquivalent(): static
    {
        return $this->state(['is_cash_equivalent' => true]);
    }

    public function revenue(): static
    {
        return $this->state([
            'type' => AccountType::Revenue,
            'normal_balance' => NormalBalance::Credit,
        ]);
    }

    public function expense(): static
    {
        return $this->state([
            'type' => AccountType::Expense,
            'normal_balance' => NormalBalance::Debit,
        ]);
    }
}
