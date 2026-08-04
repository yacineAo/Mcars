<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarDocumentResource\Pages;

use App\Filament\Admin\Resources\CarDocumentResource;
use App\Models\CarDocument;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class ViewCarDocument extends ViewRecord
{
    protected static string $resource = CarDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview_scan')
                ->label(__('Preview scan'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (CarDocument $record): bool => $record->getFirstMedia('document') !== null)
                ->url(fn (CarDocument $record): string => URL::temporarySignedRoute(
                    'media.car-documents.download',
                    now()->addMinutes(5),
                    ['carDocument' => $record->id],
                ))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Colour logic mirrors the index: danger past/today, warning inside the
     * document's own reminder window, gray comfortably in the future.
     */
    private function daysRemainingColor(CarDocument $record): string
    {
        $days = $record->expiry_date?->diffInDays(now(), false);

        return match (true) {
            $days === null, $days > (int) ($record->reminder_days_before ?? 30) => 'gray',
            $days <= 0 => 'danger',
            default => 'warning',
        };
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Document'))
                    ->schema([
                        TextEntry::make('car.registration_number')
                            ->label(__('Car')),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('number')
                            ->placeholder('—'),
                        TextEntry::make('issuer')
                            ->placeholder('—'),
                        TextEntry::make('issue_date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('expiry_date')
                            ->date(),
                        TextEntry::make('days_remaining')
                            ->label(__('Days remaining'))
                            ->state(fn (CarDocument $record): ?float => $record->expiry_date?->diffInDays(now(), false))
                            ->formatStateUsing(function (?float $state): string {
                                if ($state === null) {
                                    return '—';
                                }

                                $days = (int) $state;

                                if ($days <= 0) {
                                    return __('Expired');
                                }

                                return trans_choice('{1} :count day|[2,*] :count days', $days, ['count' => $days]);
                            })
                            ->color(fn (CarDocument $record): string => $this->daysRemainingColor($record)),
                        TextEntry::make('reminder_days_before')
                            ->label(__('Reminder lead time')),
                    ])
                    ->columns(3),
                Section::make(__('Cost'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('cost')
                            ->money('DZD')
                            ->placeholder('—'),
                        IconEntry::make('posted_to_ledger')
                            ->label(__('In ledger'))
                            ->boolean()
                            ->state(fn (CarDocument $record): bool => $record->isPostedToLedger()),
                    ])
                    ->columns(2),
                Section::make(__('History'))
                    ->schema([
                        TextEntry::make('replaced_by_id')
                            ->label(__('Superseded'))
                            ->formatStateUsing(fn (?int $state): string => $state !== null
                                ? __('Renewed — see the replacement document')
                                : __('Current'))
                            ->badge()
                            ->color(fn (?int $state): string => $state !== null ? 'gray' : 'success')
                            ->url(fn (CarDocument $record): ?string => $record->replaced_by_id !== null
                                ? CarDocumentResource::getUrl('view', ['record' => $record->replaced_by_id])
                                : null),
                    ]),
                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
