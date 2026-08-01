<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use App\Filament\Admin\Resources\ContractResource;
use App\Models\Contract;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The contract rendered from this booking, read-only.
 *
 * Generating, sending and signing a contract are ContractResource's job — duplicating
 * them here would put two paths on the same document. This answers "what was signed"
 * and links out.
 */
class ContractRelationManager extends RelationManager
{
    protected static string $relationship = 'contract';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contract');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract_number')->label(__('Contract number'))->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('terms_version')->label(__('Terms version')),
                TextColumn::make('generated_at')->label(__('Generated at'))->dateTime(),
                TextColumn::make('signed_at')->label(__('Signed at'))->dateTime()->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // ContractResource has no view page — 19-contract.md is still 🔴 and
                    // adding one there is its job, not this file's.
                    ->url(fn (Contract $record): ?string => ContractResource::canAccess()
                        ? ContractResource::getUrl('edit', ['record' => $record])
                        : null)
                    ->visible(fn (): bool => ContractResource::canAccess()),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }
}
