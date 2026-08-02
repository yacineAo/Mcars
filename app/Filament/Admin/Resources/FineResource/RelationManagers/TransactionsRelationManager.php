<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\RelationManagers;

use App\Filament\Admin\RelationManagers\LedgerPostingsRelationManager;

/**
 * The fine's ledger history — E49 (receivable) or E50 (absorbed expense) —
 * strictly read-only (ADR-003) and gated on reports.view_financials: the
 * receptionist who decided who pays does not audit the posting.
 */
class TransactionsRelationManager extends LedgerPostingsRelationManager {}
