<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OwnerInstallmentResource\RelationManagers;

use App\Filament\Admin\RelationManagers\LedgerPostingsRelationManager;

/**
 * The instalment's ledger postings (E32 accrual, E34/E35 payments, E36
 * waiver), strictly read-only (ADR-003).
 */
class TransactionsRelationManager extends LedgerPostingsRelationManager {}
