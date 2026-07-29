<?php

declare(strict_types=1);

use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns false when no backup file exists', function () {
    $service = app(BackupService::class);

    $result = $service->verifyLatest();

    expect($result)->toBeFalse();
});
