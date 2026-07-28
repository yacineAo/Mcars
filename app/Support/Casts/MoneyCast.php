<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a decimal(18,2) column to a Money object and back.
 *
 * Usage — the currency column is optional and defaults to DZD:
 *
 *   protected function casts(): array
 *   {
 *       return ['amount' => MoneyCast::class.':currency'];
 *   }
 *
 * @implements CastsAttributes<Money, Money|string|int|float|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly ?string $currencyColumn = null,
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::of((string) $value, $this->currencyFor($attributes));
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $money = $value instanceof Money
            ? $value
            : Money::of($value, $this->currencyFor($attributes));

        if ($this->currencyColumn !== null
            && ($attributes[$this->currencyColumn] ?? null) !== null
            && $attributes[$this->currencyColumn] !== $money->currency
        ) {
            throw new InvalidArgumentException(sprintf(
                'Refusing to store %s into [%s] on a row whose %s is %s.',
                $money->currency,
                $key,
                $this->currencyColumn,
                $attributes[$this->currencyColumn],
            ));
        }

        return [$key => $money->toDecimal()];
    }

    /** @param array<string, mixed> $attributes */
    private function currencyFor(array $attributes): string
    {
        if ($this->currencyColumn === null) {
            return 'DZD';
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : 'DZD';
    }
}
