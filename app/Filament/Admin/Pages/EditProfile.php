<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Locale;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The staff-facing account screen.
 *
 * Gap 4 of docs/resource/33-user.md: before this page a receptionist could not
 * change their own locale or password from inside the panel — the resource is
 * gated to users.manage and the password field was hidden from its edit form.
 *
 * Email is deliberately absent: changing it is identity work, belongs to the
 * manager on the user's edit page, and dodges Filament's email-change
 * verification flow (not configured in this panel). The 2FA section comes from
 * the vendor page's content() automatically once the panel declares
 * multiFactorAuthentication providers.
 */
class EditProfile extends FilamentEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            TextInput::make('phone')
                ->label(__('Phone'))
                ->tel()
                ->maxLength(255),
            TextInput::make('whatsapp')
                ->label(__('Whatsapp'))
                ->maxLength(255),
            Select::make('locale')
                ->label(__('Locale'))
                ->options(Locale::options())
                ->required(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /**
     * A password saved here is the user changing their own password, which
     * discharges the forced-change flag a manager's reset set. Verified by the
     * current-password field the vendor form already requires.
     */
    protected function afterSave(): void
    {
        if (! filled($this->data['password'] ?? null)) {
            return;
        }

        $user = $this->getUser();

        if ($user instanceof User && $user->must_change_password) {
            $user->update(['must_change_password' => false]);
        }
    }
}
