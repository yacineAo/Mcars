<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A closed date/time range used by every reporting and availability query.
 *
 * Exists so "this month" means the same thing in a dashboard widget, a PDF export
 * and a per-car profitability query. Report figures that disagree between screens
 * usually disagree because each screen built its own boundaries.
 *
 * Boundaries are inclusive of start and EXCLUSIVE of end ([start, end)), matching
 * the tstzrange semantics used by the bookings EXCLUDE constraint (ADR-002), so
 * an 11:00 return and an 11:00 pickup do not collide.
 */
final class Period implements JsonSerializable
{
    public const string CUSTOM = 'custom';

    public const string DAY = 'day';

    public const string WEEK = 'week';

    public const string MONTH = 'month';

    public const string YEAR = 'year';

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        /**
         * Calendar unit this period represents, when it is one.
         *
         * Needed because "the previous month" is not "minus 31 days": July has
         * 31 days and June has 30, so duration arithmetic off a July boundary
         * lands on 31 May. Month-over-month KPIs would silently compare the
         * wrong window.
         */
        public readonly string $granularity = self::CUSTOM,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('Period end cannot precede its start.');
        }
    }

    public static function of(mixed $start, mixed $end): self
    {
        return new self(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    public static function day(mixed $date = 'today'): self
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return new self($day, $day->addDay(), self::DAY);
    }

    public static function week(mixed $date = 'today'): self
    {
        $start = CarbonImmutable::parse($date)->startOfWeek();

        return new self($start, $start->addWeek(), self::WEEK);
    }

    public static function month(mixed $date = 'today'): self
    {
        $start = CarbonImmutable::parse($date)->startOfMonth();

        return new self($start, $start->addMonth(), self::MONTH);
    }

    public static function year(mixed $date = 'today'): self
    {
        $start = CarbonImmutable::parse($date)->startOfYear();

        return new self($start, $start->addYear(), self::YEAR);
    }

    /** Trailing N months ending at the end of the current month — the chart default. */
    public static function lastMonths(int $months, mixed $endingAt = 'today'): self
    {
        $end = CarbonImmutable::parse($endingAt)->startOfMonth()->addMonth();

        return new self($end->subMonths($months), $end);
    }

    public function contains(mixed $moment): bool
    {
        $moment = CarbonImmutable::parse($moment);

        return $moment >= $this->start && $moment < $this->end;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** Whole or fractional days spanned. */
    public function days(): float
    {
        return $this->start->diffInDays($this->end, absolute: true);
    }

    /** Billable days: any started day counts, with a minimum of one. */
    public function billableDays(): int
    {
        return max(1, (int) ceil($this->days()));
    }

    public function eachDay(): CarbonPeriod
    {
        return CarbonPeriod::create($this->start, '1 day', $this->end->subSecond());
    }

    public function eachMonth(): CarbonPeriod
    {
        return CarbonPeriod::create($this->start->startOfMonth(), '1 month', $this->end->subSecond());
    }

    /** Inclusive end, for `whereBetween` on a `date` column such as occurred_on. */
    public function endInclusiveDate(): CarbonImmutable
    {
        return $this->end->subDay()->endOfDay();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} for whereBetween(). */
    public function toDateRange(): array
    {
        return [$this->start->startOfDay(), $this->endInclusiveDate()];
    }

    /**
     * The equivalent preceding window, for month-over-month style comparisons.
     *
     * Calendar-aware: the month before July 2026 is June 2026, not "31 days
     * earlier". Custom ranges fall back to subtracting their own duration.
     */
    public function previous(): self
    {
        return match ($this->granularity) {
            self::DAY => self::day($this->start->subDay()),
            self::WEEK => self::week($this->start->subWeek()),
            self::MONTH => self::month($this->start->subMonthNoOverflow()),
            self::YEAR => self::year($this->start->subYear()),
            default => new self(
                $this->start->subSeconds((int) $this->start->diffInSeconds($this->end)),
                $this->start,
            ),
        };
    }

    /** Stable key for cache tags. Callers must add the branch context themselves. */
    public function cacheKey(): string
    {
        return $this->start->format('YmdHis').'-'.$this->end->format('YmdHis');
    }

    /** @return array{start: string, end: string, granularity: string} */
    public function jsonSerialize(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'granularity' => $this->granularity,
        ];
    }
}
