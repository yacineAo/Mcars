<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayrollRunResource\Pages;

use App\Filament\Admin\Resources\PayrollRunResource;
use App\Models\Branch;
use App\Models\PayrollRun;
use App\Services\Payment\PayrollService;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreatePayrollRun extends CreateRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function handleRecordCreation(array $data): PayrollRun
    {
        // A run is generated for the period, not typed: the service gathers
        // every active employee's base salary, their unrecovered advances and
        // their unpaid commissions into items — the screen only names the
        // month. The branch is the user's own (the same resolution
        // BelongsToBranch would apply on save); the form has no branch field
        // because a run is never generated for someone else's branch.
        try {
            return app(PayrollService::class)->generate(
                $this->resolveBranchId(),
                $data['period_month'],
            );
        } catch (DomainException $e) {
            // A second run for the same branch and month surfaces as a field
            // error, not a 500.
            throw ValidationException::withMessages([
                'data.period_month' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PayrollRunResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function resolveBranchId(): int
    {
        $user = Auth::user();

        if ($user !== null && $user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        return (int) Branch::defaultId();
    }
}
