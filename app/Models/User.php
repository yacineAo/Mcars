<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'branch_id', 'phone', 'whatsapp', 'avatar', 'locale', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
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

    protected static function withoutBranchScope(): bool
    {
        return true;
    }

    public function hasGlobalBranchAccess(): bool
    {
        return $this->can('branches.view_all');
    }

    /** @return list<int> */
    public function accessibleBranchIds(): array
    {
        if ($this->can('branches.view_all')) {
            return Branch::query()->pluck('id')->all();
        }

        $pivotIds = $this->branchUsers()->pluck('branches.id')->all();

        if ($pivotIds !== []) {
            return $pivotIds;
        }

        if ($this->branch_id !== null) {
            return [$this->branch_id];
        }

        report('User #'.$this->id.' ('.$this->email.') has no branch access configured.');

        return [];
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

        return $this->hasAnyRole(UserRole::values());
    }
}
