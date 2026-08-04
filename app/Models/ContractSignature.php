<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignatureMethod;
use App\Enums\SignerRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $signed_by_id
 * @property Carbon|null $signed_at
 * @property-read User|null $signedBy
 */
class ContractSignature extends Model
{
    protected $fillable = [
        'contract_id',
        'signed_by_id',
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

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The staff member who witnessed the signature.
     *
     * Null for OTP signatures — the customer signs remotely, nobody at the desk vouches.
     *
     * @return BelongsTo<User, $this>
     */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_id');
    }

    /** @return MorphTo<Model, $this> */
    public function signer(): MorphTo
    {
        return $this->morphTo();
    }
}
