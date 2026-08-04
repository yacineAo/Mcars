<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Base behaviour for every backed enum in the system.
 *
 * Labels resolve through translation files (lang/{ar,fr,en}/enums.php) keyed by
 * the enum's snake_case short name, so an enum added in one phase is translatable
 * in all three locales without touching Filament.
 *
 * Enums implement Filament's HasLabel/HasColor/HasIcon by using this trait plus
 * declaring the interfaces; colours and icons are overridden per enum.
 *
 * See docs/07-enums.md for the full catalogue and the naming rules — in
 * particular: never rename a value after go-live, it is persisted in history.
 */
trait HasEnumMeta
{
    public function getLabel(): string
    {
        $key = 'enums.'.static::translationKey().'.'.$this->value;

        return __($key) === $key
            ? Str::headline($this->value)   // sane fallback before translations land
            : __($key);
    }

    /** @return string|array<string>|null */
    public function getColor(): string|array|null
    {
        return null;
    }

    public function getIcon(): ?string
    {
        return null;
    }

    /** @return array<string, string> value => label, for Filament select options. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function is(self ...$cases): bool
    {
        return in_array($this, $cases, strict: true);
    }

    public function isNot(self ...$cases): bool
    {
        return ! $this->is(...$cases);
    }

    /** Snake_case short class name, e.g. CarStatus => car_status. */
    protected static function translationKey(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
