<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\Pages;

use App\Filament\Admin\Resources\ContractResource;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Services\Booking\ContractService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * The contract is not typed in — it is *generated* from the booking and the
     * template. ContractService builds the snapshot (the frozen document, ADR-005),
     * numbers the contract from the per-branch sequence and refuses a booking that
     * already has a contract; the form here only picks the source booking and the
     * insurance options on top.
     *
     * Filament wraps this call in a database transaction, so the generated contract
     * and the insurance options commit together.
     */
    protected function handleRecordCreation(array $data): Contract
    {
        try {
            $booking = Booking::findOrFail($data['booking_id']);
            $template = isset($data['contract_template_id'])
                ? ContractTemplate::findOrFail($data['contract_template_id'])
                : null;

            $contract = app(ContractService::class)->generate($booking, $template);

            $contract->insurance_type = $data['insurance_type'] ?? null;
            $contract->franchise_amount = $data['franchise_amount'] ?? null;
            $contract->save();

            return $contract;
        } catch (RuntimeException $e) {
            // The picker only offers bookings without a contract, but a colleague may
            // have generated one between the form opening and the submit. Surface the
            // service's refusal as a field error rather than a 500.
            throw ValidationException::withMessages([
                'data.booking_id' => $e->getMessage(),
            ]);
        }
    }
}
