<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates gap-free document numbers under a row lock.
 *
 * MUST be called inside the same database transaction as the insert of the
 * document it numbers. That is the whole point: if the document insert rolls
 * back, the number rolls back with it and no gap appears. Calling this outside a
 * transaction throws rather than silently burning numbers.
 *
 *   DB::transaction(function () use ($branch) {
 *       $number = app(SequenceGenerator::class)->next('contract', $branch->id, $branch->code);
 *       Contract::create(['contract_number' => $number, ...]);
 *   });
 *
 * Produces: CTR-MAIN-2026-000123
 */
final class SequenceGenerator
{
    /** Default prefix per key. Overridable per branch via the sequences row. */
    private const array PREFIXES = [
        'contract' => 'CTR',
        'booking' => 'BK',
        'transaction' => 'TRX',
        'payment' => 'PAY',
        'expense' => 'EXP',
        'invoice' => 'INV',
    ];

    public function next(
        string $key,
        ?int $branchId = null,
        ?string $branchCode = null,
        ?int $year = null,
    ): string {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                "SequenceGenerator::next('{$key}') must run inside a database transaction, ".
                'so that a rolled-back document does not leave a gap in the numbering.',
            );
        }

        $year ??= (int) now()->format('Y');
        $prefix = self::PREFIXES[$key] ?? strtoupper(substr($key, 0, 3));

        $row = DB::table('sequences')
            ->where('key', $key)
            ->where('branch_id', $branchId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // Two concurrent first-ever allocations would both miss the row above.
            // The unique index on (key, branch_id, year) makes one of them lose;
            // insertOrIgnore absorbs that, then we re-read under the lock.
            DB::table('sequences')->insertOrIgnore([
                'key' => $key,
                'branch_id' => $branchId,
                'year' => $year,
                'prefix' => $prefix,
                'padding' => 6,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('sequences')
                ->where('key', $key)
                ->where('branch_id', $branchId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();
        }

        if ($row === null) {
            throw new RuntimeException("Could not allocate a sequence for [{$key}].");
        }

        $number = (int) $row->next_number;

        DB::table('sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $this->format($row->prefix, $branchCode, $year, $number, (int) $row->padding);
    }

    /** Read the next number without consuming it. For previews only. */
    public function peek(string $key, ?int $branchId = null, ?int $year = null): int
    {
        $year ??= (int) now()->format('Y');

        $row = DB::table('sequences')
            ->where('key', $key)
            ->where('branch_id', $branchId)
            ->where('year', $year)
            ->first();

        return $row === null ? 1 : (int) $row->next_number;
    }

    private function format(
        string $prefix,
        ?string $branchCode,
        int $year,
        int $number,
        int $padding,
    ): string {
        $segments = array_filter([
            $prefix,
            $branchCode !== null ? strtoupper($branchCode) : null,
            (string) $year,
            str_pad((string) $number, $padding, '0', STR_PAD_LEFT),
        ]);

        return implode('-', $segments);
    }
}
