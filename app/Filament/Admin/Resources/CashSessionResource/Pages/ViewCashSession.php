<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CashSessionResource\Pages;

use App\Enums\CashSessionStatus;
use App\Filament\Admin\Resources\CashSessionResource;
use App\Filament\Admin\Resources\CashSessionResource\RelationManagers\TransactionsRelationManager;
use App\Models\CashSession;
use App\Services\CashRegisterService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCashSession extends ViewRecord
{
    protected static string $resource = CashSessionResource::class;

    /** @var array{expected: string, variance: ?string}|null */
    protected ?array $cachedReconciliation = null;

    /**
     * Expected/variance, computed once per request: the expected and variance
     * entries both need the same two aggregate queries, so running
     * `reconciliation()` in each state closure would double them.
     *
     * @return array{expected: string, variance: ?string}
     */
    protected function reconciliationFor(CashSession $record): array
    {
        return $this->cachedReconciliation ??= app(CashRegisterService::class)->reconciliation($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The single close entry point (docs/resource/14-cash-session.md gap 5):
            // closing is a state transition that posts to the ledger, so it lives on
            // the page where the session is understood, not on every list.
            Action::make('close_session')
                ->label('Close Session')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->form([
                    TextInput::make('counted_amount')
                        ->label('Counted Amount')
                        ->numeric()
                        ->prefix('DZD')
                        ->required(),
                    Textarea::make('closing_notes')
                        ->label('Closing Notes'),
                ])
                ->action(function (array $data, CashRegisterService $service): void {
                    /** @var CashSession $record */
                    $record = $this->getRecord();

                    $service->closeSession($record, (string) $data['counted_amount'], auth()->user(), isset($data['closing_notes']) ? (string) $data['closing_notes'] : null);

                    Notification::make()
                        ->success()
                        ->title(__('Session closed'))
                        ->body(__('Variance has been posted to the ledger.'))
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $record]));
                })
                ->visible(fn (CashSession $record): bool => $record->status === CashSessionStatus::Open && (auth()->user()?->can('cash_sessions.operate') ?? false)),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Session Details'))
                    ->schema([
                        TextEntry::make('financialAccount.name')
                            ->label('Account'),
                        TextEntry::make('branch.name')
                            ->label('Branch'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('openedBy.name')
                            ->label('Opened By'),
                        TextEntry::make('opened_at')
                            ->label('Opened At')
                            ->dateTime(),
                        TextEntry::make('opening_float')
                            ->label('Opening Float')
                            ->money('DZD'),
                        TextEntry::make('closedBy.name')
                            ->label('Closed By')
                            ->placeholder('—')
                            ->visible(fn (CashSession $record): bool => $record->closed_at !== null),
                        TextEntry::make('closed_at')
                            ->label('Closed At')
                            ->dateTime()
                            ->placeholder('—')
                            ->visible(fn (CashSession $record): bool => $record->closed_at !== null),
                        TextEntry::make('notes')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make(__('Reconciliation'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('expected')
                            ->label('Expected')
                            ->money('DZD')
                            ->state(fn (CashSession $record): string => $this->reconciliationFor($record)['expected']),
                        TextEntry::make('counted_amount')
                            ->label('Counted')
                            ->money('DZD')
                            ->placeholder('—'),
                        TextEntry::make('variance')
                            ->label('Variance')
                            ->money('DZD')
                            ->placeholder('—')
                            ->state(fn (CashSession $record): ?string => $this->reconciliationFor($record)['variance'])
                            ->color(fn (?string $state): string => match (true) {
                                $state === null => 'success',
                                Money::of($state)->isNegative() => 'danger',
                                Money::of($state)->isPositive() => 'warning',
                                default => 'success',
                            }),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * The session's postings, strictly read-only (ADR-003). "Show me every
     * movement in this till today" is the question someone asks when a drawer is
     * short, so the ledger rows are gated on the same permission as the variance.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Postings'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
