<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerGender;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'code',
        'type',
        'first_name',
        'last_name',
        'date_of_birth',
        'place_of_birth',
        'nationality',
        'gender',
        'national_id',
        'company_name',
        'trade_register',
        'article_number',
        'driving_license_number',
        'license_category',
        'license_issue_date',
        'license_expiry_date',
        'license_issued_at',
        'phone',
        'phone_secondary',
        'whatsapp',
        'email',
        'address',
        'city',
        'wilaya',
        'country',
        'rating',
        'is_blacklisted',
        'blacklist_reason',
        'blacklisted_at',
        'source',
        'notes',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            if (! $customer->code) {
                $raw = $customer->getAttributes();
                $type = $raw['type'] ?? 'individual';
                $prefix = $type === 'company' ? 'COM' : 'IND';
                $customer->code = $prefix.'-'.strtoupper(Str::random(8));
            }
        });
    }

    public function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'gender' => CustomerGender::class,
            'source' => CustomerSource::class,
            'date_of_birth' => 'date',
            'license_issue_date' => 'date',
            'license_expiry_date' => 'date',
            'blacklisted_at' => 'datetime',
            'rating' => 'integer',
            'is_blacklisted' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CustomerDocument> */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Payment> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Deposit> */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /** @return HasMany<Fine> */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    /** @return HasMany<PaymentSchedule> */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /** @return HasMany<Contract> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
