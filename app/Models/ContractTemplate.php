<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `locale` is declared here because static analysis reads column types from the
 * schema, not from casts() — without it `$template->locale` looks like a string.
 *
 * @property Locale $locale
 */
class ContractTemplate extends Model
{
    use BelongsToBranch;

    /** The version every template starts at; bumped from the edit page on a body change. */
    public const INITIAL_TERMS_VERSION = '1.0';

    protected $fillable = [
        'branch_id',
        'name',
        'locale',
        'body',
        'terms_version',
        'is_active',
        'is_default',
    ];

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'contract_template_id');
    }

    /**
     * Whether any contract was ever rendered from this template — the guard on
     * deleting it and on changing its locale.
     *
     * `withExists('contracts')` on a list query pre-computes this; the attribute is
     * honoured when present so a table of templates does not fire one EXISTS per row.
     */
    public function hasRenderedContracts(): bool
    {
        if (array_key_exists('contracts_exists', $this->attributes)) {
            return (bool) $this->attributes['contracts_exists'];
        }

        return $this->contracts()->exists();
    }

    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
