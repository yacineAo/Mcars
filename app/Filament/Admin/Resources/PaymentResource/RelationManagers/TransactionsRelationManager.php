<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentResource\RelationManagers;

use App\Filament\Admin\RelationManagers\LedgerPostingsRelationManager;

/**
 * The payment's ledger postings (E10–E14) and anything that reverses them,
 * strictly read-only (ADR-003). A reversed payment must show its reversal here,
 * or it would look settled.
 */
class TransactionsRelationManager extends LedgerPostingsRelationManager {}
