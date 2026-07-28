<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value object.
 *
 * Stored and computed as an integer number of MINOR units (centimes for DZD).
 * Floats never enter the arithmetic: 0.1 + 0.2 !== 0.3 in binary floating point,
 * and a ledger that is the single source of truth for cash, profit and per-car
 * profitability (REQ-08) cannot afford that drift.
 *
 * Database columns are decimal(18,2) — see docs/01-database-schema.md. Use
 * MoneyCast to move between the column and this object.
 */
final class Money implements JsonSerializable, Stringable
{
    public const int SCALE = 2;

    private const int FACTOR = 100;

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency = 'DZD'): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'DZD'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Build from a decimal representation: '1234.56', '1234', 1234, or -0.05.
     *
     * Floats are accepted but immediately rendered to a fixed-precision string,
     * because that is the only safe thing to do with one. Prefer passing strings
     * — they come out of the database and out of forms as strings anyway.
     */
    public static function of(string|int|float $amount, string $currency = 'DZD'): self
    {
        $value = is_float($amount)
            ? number_format($amount, self::SCALE, '.', '')
            : (string) $amount;

        $value = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a valid decimal amount: [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        // Round half-up on the first discarded digit rather than truncating,
        // so 0.005 becomes 0.01 and money is not quietly lost.
        $roundUp = strlen($fraction) > self::SCALE
            && (int) $fraction[self::SCALE] >= 5;

        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        $minor = (int) $whole * self::FACTOR + (int) $fraction + ($roundUp ? 1 : 0);

        return new self($negative ? -$minor : $minor, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by a scalar (e.g. days, quantity, tax rate), rounding half-up. */
    public function times(int|float|string $multiplier): self
    {
        $product = (float) $this->minor * (float) $multiplier;

        return new self((int) round($product, 0, PHP_ROUND_HALF_UP), $this->currency);
    }

    public function dividedBy(int|float|string $divisor): self
    {
        if ((float) $divisor === 0.0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(
            (int) round($this->minor / (float) $divisor, 0, PHP_ROUND_HALF_UP),
            $this->currency,
        );
    }

    /**
     * Split into $n parts with no centime lost — the remainder is distributed one
     * unit at a time across the earliest parts.
     *
     * Needed for instalment plans (REQ-07): 100.00 over 3 instalments is
     * 33.34 + 33.33 + 33.33, never 3 x 33.33 with 0.01 unaccounted for.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate into fewer than one part.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor - ($base * $parts);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < abs($remainder) ? ($remainder <=> 0) : 0;
            $result[] = new self($base + $extra, $this->currency);
        }

        return $result;
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /** -1, 0 or 1. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /** Fixed-point decimal string, safe to write to a decimal(18,2) column. */
    public function toDecimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, self::FACTOR), $abs % self::FACTOR);
    }

    /** Human display, e.g. "12 500,00 DA". */
    public function format(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $formatted = number_format(
            (float) $this->toDecimal(),
            self::SCALE,
            decimal_separator: ',',
            thousands_separator: "\u{202F}", // narrow no-break space
        );

        return $this->currency === 'DZD'
            ? "{$formatted} DA"
            : "{$formatted} {$this->currency}";
    }

    /** @return array{amount: string, minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDecimal(),
            'minor' => $this->minor,
            'currency' => $this->currency,
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: [{$this->currency}] vs [{$other->currency}].",
            );
        }
    }
}
