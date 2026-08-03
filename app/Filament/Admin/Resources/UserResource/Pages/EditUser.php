<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The row actions, bound to this page's record so the same action instances
     * work from the table and from here. Delete is deliberately absent: staff
     * accounts are parked via Deactivate, never destroyed.
     */
    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            UserResource::assignRolesAction()->record($record),
            UserResource::resetPasswordAction()->record($record),
            UserResource::setActiveAction()->record($record),
        ];
    }
}
