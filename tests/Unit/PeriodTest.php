<?php

declare(strict_types=1);

use App\Support\Period;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-28 14:30:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('builds a single day', function () {
    $period = Period::day();

    expect($period->start->toDateTimeString())->toBe('2026-07-28 00:00:00')
        ->and($period->end->toDateTimeString())->toBe('2026-07-29 00:00:00');
});

it('builds a calendar month', function () {
    $period = Period::month();

    expect($period->start->toDateString())->toBe('2026-07-01')
        ->and($period->end->toDateString())->toBe('2026-08-01');
});

it('builds a trailing window for charts', function () {
    $period = Period::lastMonths(12);

    expect($period->start->toDateString())->toBe('2025-08-01')
        ->and($period->end->toDateString())->toBe('2026-08-01');
});

it('treats the end as exclusive, so an 11:00 return and an 11:00 pickup do not collide', function () {
    $morning = Period::of('2026-07-28 09:00', '2026-07-28 11:00');

    expect($morning->contains('2026-07-28 10:59'))->toBeTrue()
        ->and($morning->contains('2026-07-28 11:00'))->toBeFalse();

    $afternoon = Period::of('2026-07-28 11:00', '2026-07-28 15:00');

    expect($morning->overlaps($afternoon))->toBeFalse();
});

it('detects genuine overlap', function () {
    $a = Period::of('2026-07-01', '2026-07-10');
    $b = Period::of('2026-07-09', '2026-07-15');

    expect($a->overlaps($b))->toBeTrue()
        ->and($b->overlaps($a))->toBeTrue();
});

it('counts billable days with a one-day minimum', function () {
    expect(Period::of('2026-07-01 10:00', '2026-07-04 10:00')->billableDays())->toBe(3)
        // Any started day is chargeable — three hours is still a day's rental.
        ->and(Period::of('2026-07-01 10:00', '2026-07-01 13:00')->billableDays())->toBe(1);
});

it('produces an inclusive range for date columns', function () {
    [$from, $to] = Period::month()->toDateRange();

    expect($from->toDateString())->toBe('2026-07-01')
        ->and($to->toDateString())->toBe('2026-07-31');
});

it('computes the preceding period of equal length', function () {
    $previous = Period::month()->previous();

    expect($previous->start->toDateString())->toBe('2026-06-01')
        ->and($previous->end->toDateString())->toBe('2026-07-01');
});

it('rejects an end before its start', function () {
    Period::of('2026-07-10', '2026-07-01');
})->throws(InvalidArgumentException::class);
