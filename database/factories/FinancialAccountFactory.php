<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FinancialAccountType;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    protected $model = FinancialAccount::class;

    public function definition(): array
    {
        return [
            'ledger_account_id' => ChartOfAccount::factory()->cashEquivalent(),
            'name' => $this->faker->company,
            'type' => FinancialAccountType::Bank,
            'opened_on' => now()->subYear(),
            'is_active' => true,
        ];
    }

    public function cashBox(): static
    {
        return $this->state(['type' => FinancialAccountType::CashBox]);
    }
}
