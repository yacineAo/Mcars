<?php

declare(strict_types=1);

use App\Support\Money;

it('parses decimal strings without float drift', function () {
    expect(Money::of('1234.56')->minor)->toBe(123456)
        ->and(Money::of('0.01')->minor)->toBe(1)
        ->and(Money::of('1000')->minor)->toBe(100000)
        ->and(Money::of('-45.50')->minor)->toBe(-4550);
});

it('survives the arithmetic that breaks floats', function () {
    // 0.1 + 0.2 !== 0.3 in binary floating point. In minor units it is exact.
    expect(money('0.1')->plus(money('0.2')))->toEqualMoney('0.30');

    // Ten thousand one-centime additions must land exactly on 100.00.
    $total = Money::zero();
    for ($i = 0; $i < 10_000; $i++) {
        $total = $total->plus(money('0.01'));
    }

    expect($total)->toEqualMoney('100.00');
});

it('rounds half up rather than truncating, so centimes are not lost', function () {
    expect(Money::of('0.005'))->toEqualMoney('0.01')
        ->and(Money::of('1.994'))->toEqualMoney('1.99')
        ->and(Money::of('1.995'))->toEqualMoney('2.00');
});

it('adds and subtracts', function () {
    expect(money('100.00')->plus(money('25.50')))->toEqualMoney('125.50')
        ->and(money('100.00')->minus(money('125.50')))->toEqualMoney('-25.50');
});

it('multiplies by a quantity of rental days', function () {
    expect(money('3500.00')->times(7))->toEqualMoney('24500.00');
});

it('applies a percentage rate', function () {
    expect(money('10000.00')->times(0.19))->toEqualMoney('1900.00');
});

it('allocates an instalment plan without losing a centime', function () {
    // 100.00 over 3 instalments must total exactly 100.00, not 99.99 (REQ-07).
    $parts = money('100.00')->allocate(3);

    expect($parts)->toHaveCount(3)
        ->and($parts[0])->toEqualMoney('33.34')
        ->and($parts[1])->toEqualMoney('33.33')
        ->and($parts[2])->toEqualMoney('33.33');

    $sum = array_reduce($parts, fn (Money $c, Money $p) => $c->plus($p), Money::zero());

    expect($sum)->toEqualMoney('100.00');
});

it('allocates negative amounts without losing a centime', function () {
    $parts = money('-100.00')->allocate(3);
    $sum = array_reduce($parts, fn (Money $c, Money $p) => $c->plus($p), Money::zero());

    expect($sum)->toEqualMoney('-100.00');
});

it('compares amounts', function () {
    expect(money('100.00')->greaterThan(money('99.99')))->toBeTrue()
        ->and(money('100.00')->lessThan(money('100.01')))->toBeTrue()
        ->and(money('100.00')->equals(money('100.00')))->toBeTrue()
        ->and(Money::zero())->toBeZeroMoney();
});

it('renders a database-safe decimal string', function () {
    expect(money('1234.5')->toDecimal())->toBe('1234.50')
        ->and(money('-0.05')->toDecimal())->toBe('-0.05')
        ->and(Money::zero()->toDecimal())->toBe('0.00');
});

it('refuses to mix currencies', function () {
    Money::of('100', 'DZD')->plus(Money::of('100', 'EUR'));
})->throws(InvalidArgumentException::class, 'Currency mismatch');

it('rejects malformed amounts', function () {
    Money::of('twelve');
})->throws(InvalidArgumentException::class);

it('refuses division by zero', function () {
    money('100.00')->dividedBy(0);
})->throws(InvalidArgumentException::class);
