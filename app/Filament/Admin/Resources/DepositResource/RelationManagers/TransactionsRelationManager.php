<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DepositResource\RelationManagers;

use App\Filament\Admin\RelationManagers\LedgerPostingsRelationManager;

/**
 * The deposit's ledger postings (E22–E31), strictly read-only (ADR-003).
 */
class TransactionsRelationManager extends LedgerPostingsRelationManager {}
