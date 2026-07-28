<?php

declare(strict_types=1);

use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\DB;

/*
 | Lives in Unit, not Feature, deliberately.
 |
 | RefreshDatabase wraps each Feature test in a transaction, so
 | DB::transactionLevel() is always >= 1 there and this guard would pass
 | vacuously — the test would go green while proving nothing. Unit tests boot the
 | application without that wrapper, which is the only place the zero-level case
 | is reachable.
 |
 | The guard throws before issuing any query, so no database is required.
 */

it('refuses to allocate a document number outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    // Allocating outside a transaction means a failed document insert leaves the
    // number consumed and a permanent gap in the sequence — not acceptable for a
    // legally-numbered contract.
    app(SequenceGenerator::class)->next('contract');
})->throws(RuntimeException::class, 'must run inside a database transaction');
