<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Filament\Admin\Resources\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Override;

class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        // No delete — see the resource. Deleting a role locks its holders out of
        // the panel with no sanctioned way back, and the seeder owns the list.
        return [];
    }

    /**
     * The guard field is hidden but dehydrated, so a crafted request could post
     * any value into it: Shield's afterSave() would firstOrCreate() permissions
     * under that guard and syncPermissions() the role to them, detaching the
     * seeded `web` set. Everything here is the web guard, so clamp it
     * server-side instead of trusting the request — and keep it in
     * `$this->data`, which afterSave() reads directly.
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $guard = Utils::getFilamentAuthGuard();
        $data['guard_name'] = $guard;
        $this->data['guard_name'] = $guard;

        return parent::mutateFormDataBeforeSave($data);
    }
}
