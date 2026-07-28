<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->sequences = app(SequenceGenerator::class);
});

it('allocates consecutive numbers', function () {
    $numbers = DB::transaction(fn () => [
        $this->sequences->next('contract', year: 2026),
        $this->sequences->next('contract', year: 2026),
        $this->sequences->next('contract', year: 2026),
    ]);

    expect($numbers)->toBe([
        'CTR-2026-000001',
        'CTR-2026-000002',
        'CTR-2026-000003',
    ]);
});

it('includes the branch code so per-branch numbering cannot collide', function () {
    $main = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_default' => true]);
    $oran = Branch::create(['name' => 'Oran', 'code' => 'ORAN']);

    [$a, $b] = DB::transaction(fn () => [
        $this->sequences->next('contract', $main->id, $main->code, 2026),
        $this->sequences->next('contract', $oran->id, $oran->code, 2026),
    ]);

    // Both are sequence #1 for their own branch, but the codes keep them distinct.
    expect($a)->toBe('CTR-MAIN-2026-000001')
        ->and($b)->toBe('CTR-ORAN-2026-000001');
});

it('keeps a separate counter per key, branch and year', function () {
    DB::transaction(function () {
        $this->sequences->next('contract', year: 2026);
        $this->sequences->next('contract', year: 2026);
    });

    $booking = DB::transaction(fn () => $this->sequences->next('booking', year: 2026));
    $nextYear = DB::transaction(fn () => $this->sequences->next('contract', year: 2027));

    expect($booking)->toBe('BK-2026-000001')
        ->and($nextYear)->toBe('CTR-2027-000001');
});

// The "refuses to allocate outside a transaction" guard is exercised in
// tests/Unit/SequenceGuardTest.php: RefreshDatabase wraps every Feature test in
// a transaction, so transactionLevel() is always >= 1 here and the guard can
// never fire.

it('does not consume a number when the surrounding transaction rolls back', function () {
    try {
        DB::transaction(function () {
            $this->sequences->next('contract', year: 2026);
            throw new RuntimeException('document insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    $number = DB::transaction(fn () => $this->sequences->next('contract', year: 2026));

    expect($number)->toBe('CTR-2026-000001');
});

it('peeks without consuming', function () {
    DB::transaction(fn () => $this->sequences->next('contract', year: 2026));

    expect($this->sequences->peek('contract', null, 2026))->toBe(2)
        ->and($this->sequences->peek('contract', null, 2026))->toBe(2);
});
