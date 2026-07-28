<?php

declare(strict_types=1);

use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('runs on postgresql', function () {
    // Guard against the suite silently falling back to SQLite, which cannot
    // express the EXCLUDE constraint (Phase 5) or the ledger trigger (Phase 4).
    expect(DB::getDriverName())->toBe('pgsql');
});

it('has the extensions later phases depend on', function () {
    $installed = collect(DB::select('SELECT extname FROM pg_extension'))
        ->pluck('extname')
        ->all();

    // btree_gist is what makes the Phase 5 double-booking constraint possible.
    expect($installed)->toContain('btree_gist')
        ->and($installed)->toContain('pg_trgm');
});

it('runs in the Algiers timezone so accounting dates are not off by one', function () {
    expect(config('app.timezone'))->toBe('Africa/Algiers');
});

it('allows only one default branch', function () {
    Branch::create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_default' => true]);

    expect(fn () => Branch::create(['name' => 'Oran', 'code' => 'ORAN', 'is_default' => true]))
        ->toThrow(QueryException::class);
});

it('allows many non-default branches', function () {
    Branch::create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_default' => true]);
    Branch::create(['name' => 'Oran', 'code' => 'ORAN']);
    Branch::create(['name' => 'Constantine', 'code' => 'CONS']);

    expect(Branch::count())->toBe(3)
        ->and(Branch::default()->code)->toBe('MAIN');
});

it('upper-cases branch codes so document numbering stays consistent', function () {
    $branch = Branch::create(['name' => 'Oran', 'code' => 'oran']);

    expect($branch->code)->toBe('ORAN');
});
