<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\CustomerDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerDocumentFactory extends Factory
{
    protected $model = CustomerDocument::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => fake()->randomElement(CustomerDocumentType::cases()),
            'number' => strtoupper(fake()->bothify('DOC-######')),
            'issue_date' => now()->subYear(),
            'expiry_date' => fake()->dateTimeBetween('+1 month', '+2 years')->format('Y-m-d'),
        ];
    }
}
