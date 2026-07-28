<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignatureMethod;
use App\Enums\SignerRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContractSignature extends Model
{
    protected $fillable = [
        'contract_id',
        'signer_role',
        'signer_type',
        'signer_id',
        'signer_name_snapshot',
        'method',
        'otp_code_hash',
        'otp_sent_to',
        'otp_sent_at',
        'otp_verified_at',
        'otp_attempts',
        'document_hash',
        'signed_at',
        'ip_address',
        'user_agent',
        'geolocation',
    ];

    protected function casts(): array
    {
        return [
            'signer_role' => SignerRole::class,
            'method' => SignatureMethod::class,
            'otp_sent_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'signed_at' => 'datetime',
            'otp_attempts' => 'integer',
            'geolocation' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function signer(): MorphTo
    {
        return $this->morphTo();
    }
}
