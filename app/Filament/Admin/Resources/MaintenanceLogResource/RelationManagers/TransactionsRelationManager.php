<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MaintenanceLogResource\RelationManagers;

use App\Filament\Admin\RelationManagers\LedgerPostingsRelationManager;

/**
 * The E41 posting behind a completed service (Dr 5040, Cr the paying account
 * or 2210 on credit) — strictly read-only (ADR-003).
 */
class TransactionsRelationManager extends LedgerPostingsRelationManager {}
