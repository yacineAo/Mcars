<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Enums\SignerRole;
use App\Models\Contract;
use App\Models\ContractSignature;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SignatureService
{
    public function requestOtp(Contract $contract, string $signerRole, string $phone): void
    {
        $code = (string) random_int(100000, 999999);
        $role = SignerRole::tryFrom($signerRole) ?? SignerRole::Customer;

        ContractSignature::create([
            'contract_id' => $contract->id,
            'signer_role' => $role,
            'signer_name_snapshot' => $this->getSignerName($contract, $role),
            'method' => SignatureMethod::Otp,
            'otp_code_hash' => Hash::make($code),
            'otp_sent_to' => $phone,
            'otp_sent_at' => now(),
            'otp_attempts' => 0,
        ]);
    }

    public function verifyOtp(Contract $contract, string $signerRole, string $code): ContractSignature
    {
        $signature = ContractSignature::query()
            ->where('contract_id', $contract->id)
            ->where('signer_role', $signerRole)
            ->whereNull('signed_at')
            ->latest()
            ->firstOrFail();

        if ($signature->otp_attempts >= 5) {
            throw new RuntimeException('Too many failed OTP attempts. Request a new code.');
        }

        if (! Hash::check($code, $signature->otp_code_hash)) {
            $signature->increment('otp_attempts');
            throw new RuntimeException('Invalid verification code.');
        }

        $signature->update([
            'otp_verified_at' => now(),
            'signed_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->finalise($contract);

        return $signature->fresh();
    }

    public function captureDrawn(Contract $contract, string $signerRole, string $pngDataUri): ContractSignature
    {
        $role = SignerRole::tryFrom($signerRole) ?? SignerRole::Customer;

        $signature = ContractSignature::create([
            'contract_id' => $contract->id,
            'signer_role' => $role,
            'signer_name_snapshot' => $this->getSignerName($contract, $role),
            'method' => SignatureMethod::Drawn,
            'signed_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->finalise($contract);

        return $signature;
    }

    public function finalise(Contract $contract): void
    {
        $pendingSignatures = $contract->signatures()->whereNull('signed_at')->count();

        if ($pendingSignatures === 0) {
            $contract->update([
                'status' => ContractStatus::Signed,
                'document_hash' => $contract->document_hash,
                'signed_at' => now(),
            ]);
        }
    }

    private function getSignerName(Contract $contract, SignerRole $role): string
    {
        return match ($role) {
            SignerRole::Customer => $contract->customer !== null
                ? trim($contract->customer->first_name.' '.$contract->customer->last_name)
                : 'Customer',
            SignerRole::CompanyRepresentative => 'Company Representative',
            default => $role->getLabel(),
        };
    }
}
