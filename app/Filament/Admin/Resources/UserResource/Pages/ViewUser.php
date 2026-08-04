<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ActivityLogResource;
use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * A handful of fields (docs/resource/33-user.md §Decisions taken, point 8):
 * roles, password and 2FA stay actions on Edit, not fields here. "What has
 * this person done" is answered by the activity log, one click away.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_history')
                ->label(__('activity_log.resource.actions.view_history'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->url(fn (User $record): string => ActivityLogResource::getUrl('index', [
                    'tableFilters' => [
                        'subject_type' => ['value' => User::class],
                        'subject_id' => ['subject_id' => $record->getKey()],
                    ],
                ], panel: 'admin'))
                ->visible(fn (): bool => (bool) (Auth::user()?->can('audit.view') ?? false)),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('whatsapp')->placeholder('—'),
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->color(fn (string $state): string => UserRole::tryFrom($state)?->getColor() ?? 'gray')
                            ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->getLabel() ?? $state),
                    ])
                    ->columns(2),
                Section::make('Status')
                    ->schema([
                        IconEntry::make('is_active')->boolean(),
                        IconEntry::make('two_factor_enabled')
                            ->label(__('Two-factor authentication'))
                            ->boolean()
                            ->state(fn (User $record): bool => $record->getAppAuthenticationSecret() !== null),
                        TextEntry::make('last_login_at')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('last_login_ip')
                            ->placeholder('—'),
                    ])
                    ->columns(4),
                Section::make('Placement')
                    ->schema([
                        TextEntry::make('branch.name')
                            ->label(__('Branch'))
                            ->placeholder('—'),
                    ]),
                Section::make('Preferences')
                    ->schema([
                        TextEntry::make('locale')
                            ->formatStateUsing(fn (?Locale $state): ?string => $state?->getLabel()),
                    ]),
            ]);
    }

    /**
     * The branch pivot stays on EditUser — it is maintained in place there,
     * not duplicated as read-only history here.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
