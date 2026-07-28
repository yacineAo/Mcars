<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

use App\Support\Money;

/** Compare money by value, with a readable failure message. */
expect()->extend('toEqualMoney', function (Money|string $expected) {
    /** @var Money $value */
    $value = $this->value;

    $expected = $expected instanceof Money ? $expected : Money::of($expected);

    expect($value->toDecimal())->toBe($expected->toDecimal());

    return $this;
});

expect()->extend('toBeZeroMoney', function () {
    expect($this->value->isZero())->toBeTrue(
        "Expected zero, got {$this->value->toDecimal()}.",
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function money(string|int|float $amount, string $currency = 'DZD'): Money
{
    return Money::of($amount, $currency);
}
