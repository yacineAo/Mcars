<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A document that can be posted to the ledger, and knows whether it has been.
 *
 * `transactions.source_type` / `source_id` record which business document produced
 * a row. That pairing is indexed, so "has this payment already been posted?" is a
 * cheap lookup — which is what lets a UI action be safely idempotent. Without it a
 * double-clicked button posts the same payment twice, and the only way to correct
 * it is a reversal.
 *
 * `source_type` is a short snake_case tag rather than a class name, matching what
 * ExpensePoster already writes. Tags are persisted in history, so renaming a model
 * means keeping the old tag.
 *
 * @phpstan-require-extends Model
 */
trait HasLedgerPostings
{
    /** @return HasMany<Transaction, $this> */
    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_id')
            ->where('source_type', static::ledgerSourceType());
    }

    public function isPostedToLedger(): bool
    {
        return $this->ledgerTransactions()->exists();
    }

    /** Payment => payment, OwnerInstallment => owner_installment. */
    public static function ledgerSourceType(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
