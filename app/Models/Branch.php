<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A physical location: its own fleet, staff and cash register.
 *
 * hasMany relations to Car, Booking, Transaction, CashSession and the rest are
 * added by the phases that create those models, keeping this class free of
 * references to tables that do not exist yet.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 * @property bool $is_default
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'address', 'city', 'wilaya', 'phone', 'email',
        'manager_user_id', 'timezone', 'is_active', 'is_default', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param Builder<Branch> $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    /**
     * Cached id of the default branch — read on nearly every insert via
     * BelongsToBranch, so it must not cost a query each time.
     */
    public static function defaultId(): ?int
    {
        return once(function (): ?int {
            $id = static::query()->where('is_default', true)->value('id');

            return $id === null ? null : (int) $id;
        });
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    /**
     * Whether any document number has ever been issued for this branch.
     *
     * Document numbers read via the branch code — "CTR-MAIN-2026-000123" — so a
     * rename after the fact would silently mislabel every document an office has
     * ever printed. The form freezes the code once this becomes true.
     *
     * There is no Sequence model: the sequences counter is written directly by
     * App\Support\Sequences\SequenceGenerator, hence the query here.
     */
    public function hasNumberedDocuments(): bool
    {
        return DB::table('sequences')->where('branch_id', $this->id)->exists();
    }
}
