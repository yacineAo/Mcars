<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use DomainException;
use Spatie\Activitylog\Facades\Activity;

/**
 * The only sanctioned way staff accounts are mutated beyond the plain profile
 * fields: role assignment, password resets and deactivation all go through here,
 * so the guards live in one place and every call is audited.
 *
 * The create/edit *form* still writes name/email/phone/locale/branch directly —
 * this service owns the actions that decide who can do what, not the identity
 * fields themselves.
 */
final class UserService
{
    /**
     * The roles an actor may hand out: never one they do not hold themselves.
     * A manager is meant to create receptionists, not promote anybody — including
     * themselves — to super_admin.
     *
     * @return list<string>
     */
    public function assignableRoleNames(?User $actor): array
    {
        $roles = UserRole::values();

        if ($actor?->hasRole(UserRole::SuperAdmin->value)) {
            return $roles;
        }

        return array_values(array_diff($roles, [UserRole::SuperAdmin->value]));
    }

    /**
     * Replaces the user's roles. Refuses anything the actor could not assign
     * themselves — including editing a user who holds a role the actor may not
     * assign, since a manager stripping a super_admin is as loud as a manager
     * crowning one.
     *
     * @param list<string> $roleNames
     */
    public function assignRoles(User $user, array $roleNames, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('users.errors.action_requires_staff'));
        }

        if ($user->is($actor)) {
            throw new DomainException(__('users.errors.cannot_change_own_roles'));
        }

        $assignable = $this->assignableRoleNames($actor);

        if (array_diff($roleNames, $assignable) !== []) {
            throw new DomainException(__('users.errors.role_not_assignable'));
        }

        if (! $actor->hasRole(UserRole::SuperAdmin->value)) {
            $current = $user->getRoleNames()->all();

            if (in_array(UserRole::SuperAdmin->value, $current, true)) {
                throw new DomainException(__('users.errors.cannot_modify_super_admin'));
            }
        }

        $roleNames = array_values(array_unique($roleNames));

        $user->syncRoles($roleNames);

        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->event('roles_updated')
            ->withProperties(['roles' => $roleNames])
            ->log(__('users.activity.roles_updated'));
    }

    /**
     * Sets a new password and flags it for forced change at the user's next request —
     * the password itself is hidden from the activity log, so the flag is what the
     * audit trail shows.
     */
    public function resetPassword(User $user, string $password, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('users.errors.action_requires_staff'));
        }

        $user->update([
            'password' => $password,
            'must_change_password' => true,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->event('password_reset')
            ->log(__('users.activity.password_reset'));
    }

    /**
     * Deactivate (park a leaver, revoke access) or reactivate. `is_active` changes
     * are already audited by the model's activity trait — including the causer —
     * so no extra row is written here.
     */
    public function setActive(User $user, bool $active, ?User $actor): void
    {
        if ($actor === null) {
            throw new DomainException(__('users.errors.action_requires_staff'));
        }

        if (! $active && $user->is($actor)) {
            throw new DomainException(__('users.errors.cannot_deactivate_self'));
        }

        $user->update(['is_active' => $active]);
    }
}
