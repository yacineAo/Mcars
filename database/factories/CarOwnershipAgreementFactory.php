<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarOwnershipAgreement>
 */
class CarOwnershipAgreementFactory extends Factory
{
    protected $model = CarOwnershipAgreement::class;

    public function definition(): array
    {
        return [
            'car_id' => Car::factory(),
            'car_owner_id' => CarOwner::factory(),
            'model' => AgreementModel::FixedMonthly,
            'status' => AgreementStatus::Active,
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'monthly_rent_amount' => fake()->randomFloat(2, 20000, 100000),
            'payment_day_of_month' => 5,
            'grace_days' => 7,
        ];
    }
}
