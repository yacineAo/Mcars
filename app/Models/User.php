<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * `locale` is declared here because static analysis reads column types from the schema,
 * not from casts() — without it `$user->locale` looks like a string and every
 * `instanceof Locale` check reads as dead code.
 *
 * @property Locale $locale
 */
#[Fillable(['name', 'email', 'password', 'branch_id', 'phone', 'whatsapp', 'avatar', 'locale', 'is_active', 'must_change_password', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locale' => Locale::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branchUsers(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->update(['two_factor_secret' => $secret]);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        if ($this->two_factor_recovery_codes === null) {
            return null;
        }

        $codes = json_decode($this->two_factor_recovery_codes, true);

        return is_array($codes) ? array_values($codes) : null;
    }

    /**
     * @param list<string>|null $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->update(['two_factor_recovery_codes' => $codes === null ? null : json_encode($codes)]);
    }

    protected static function withoutBranchScope(): bool
    {
        return true;
    }

    public function hasGlobalBranchAccess(): bool
    {
        return $this->can('branches.view_all');
    }

    /**
     * Memoised for the lifetime of the instance: Filament evaluates record-level
     * authorization once per row, so an un-cached lookup here turns a table page
     * into one query per row per action. Branch membership does not change
     * mid-request, so the cache cannot go stale within one.
     *
     * @var list<int>|null
     */
    private ?array $accessibleBranchIds = null;

    /** @return list<int> */
    public function accessibleBranchIds(): array
    {
        if ($this->accessibleBranchIds !== null) {
            return $this->accessibleBranchIds;
        }

        if ($this->can('branches.view_all')) {
            return $this->accessibleBranchIds = Branch::query()->pluck('id')->all();
        }

        $pivotIds = $this->branchUsers()->pluck('branches.id')->all();

        if ($pivotIds !== []) {
            return $this->accessibleBranchIds = $pivotIds;
        }

        if ($this->branch_id !== null) {
            return $this->accessibleBranchIds = [$this->branch_id];
        }

        report('User #'.$this->id.' ('.$this->email.') has no branch access configured.');

        return $this->accessibleBranchIds = [];
    }

    /**
     * There is one panel and it is staff-only.
     *
     * Every role in UserRole is a staff role, so this is a membership check rather
     * than a list — a role added later is admitted by default, and the finer
     * question of what it may then do is a permission, not a panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        // Deactivated accounts are out on their very next request — a parked leaver
        // must not linger behind an open tab. Checked here rather than at login so
        // every request re-asserts it, and it applies to existing sessions, not just
        // the next attempt.
        if (! $this->is_active) {
            return false;
        }

        return $this->hasAnyRole(UserRole::values());
    }
}
