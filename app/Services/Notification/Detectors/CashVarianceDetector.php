<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\CashSessionStatus;
use App\Models\AlertRule;
use App\Models\CashSession;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cash sessions closed with a counted amount that did not match the ledger.
 *
 * CashRegisterService::closeSession() already does the arithmetic and parks the
 * session in Disputed when |variance| >= 0.01, posting the over/short to the
 * ledger. This detector therefore reads that decision rather than recomputing it —
 * two places deciding what counts as a variance would eventually disagree.
 */
final class CashVarianceDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::CashVariance;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = CashSession::query()
            ->with(['financialAccount', 'closedBy'])
            ->where('status', CashSessionStatus::Disputed->value)
            // Unreconciled only: once someone has signed it off, it is handled.
            ->whereNull('reconciled_at');

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $session) {
            /** @var FinancialAccount|null $account */
            $account = $session->financialAccount;
            /** @var User|null $closedBy */
            $closedBy = $session->closedBy;
            /** @var CarbonInterface|null $closedAt */
            $closedAt = $session->closed_at;

            yield new AlertSubject(
                subject: $session,
                branchId: $session->branch_id,
                payload: [
                    'account' => $account->name ?? '—',
                    'closed_at' => $closedAt?->translatedFormat('d/m/Y H:i') ?? '—',
                    'closed_by' => $closedBy->name ?? '—',
                    'counted' => $session->counted_amount,
                ],
            );
        }
    }
}
