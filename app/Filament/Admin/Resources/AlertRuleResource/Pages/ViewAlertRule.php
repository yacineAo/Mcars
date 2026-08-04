<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AlertRuleResource\Pages;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AlertRuleResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewAlertRule extends ViewRecord
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AlertRuleResource::viewDeliveriesAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('notifications.resources.alert_rule.sections.what'))
                    ->schema([
                        TextEntry::make('type')
                            ->label(__('notifications.resources.alert_rule.fields.type'))
                            ->badge(),
                        TextEntry::make('branch.name')
                            ->label(__('notifications.resources.alert_rule.fields.branch'))
                            ->placeholder(__('notifications.resources.alert_rule.global')),
                        TextEntry::make('template_key')
                            ->label(__('notifications.resources.alert_rule.fields.template_key')),
                        IconEntry::make('is_active')
                            ->label(__('notifications.resources.alert_rule.fields.is_active'))
                            ->boolean(),
                    ])
                    ->columns(4),
                Section::make(__('notifications.resources.alert_rule.sections.when'))
                    ->schema([
                        TextEntry::make('days_before')
                            ->label(__('notifications.resources.alert_rule.fields.days_before'))
                            ->suffix(' d'),
                        TextEntry::make('repeat_every_days')
                            ->label(__('notifications.resources.alert_rule.fields.repeat_every_days'))
                            ->suffix(' d')
                            ->placeholder(__('notifications.resources.alert_rule.once')),
                        TextEntry::make('max_repeats')
                            ->label(__('notifications.resources.alert_rule.fields.max_repeats'))
                            ->placeholder('∞'),
                    ])
                    ->columns(3),
                Section::make(__('notifications.resources.alert_rule.sections.who'))
                    ->schema([
                        TextEntry::make('channels')
                            ->label(__('notifications.resources.alert_rule.fields.channels'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => NotificationChannel::tryFrom($state)?->getLabel() ?? $state),
                        TextEntry::make('recipient_roles')
                            ->label(__('notifications.resources.alert_rule.fields.recipient_roles'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->getLabel() ?? $state),
                    ])
                    ->columns(2),
                Section::make(__('notifications.resources.alert_rule.fields.updated_by'))
                    ->schema([
                        TextEntry::make('updatedBy.name')
                            ->label(__('notifications.resources.alert_rule.fields.updated_by'))
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label(__('notifications.resources.alert_rule.fields.updated_at'))
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}
