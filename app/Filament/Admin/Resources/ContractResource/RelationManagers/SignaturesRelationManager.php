<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every signature recorded against this contract, oldest last.
 *
 * Signatures are written exclusively by ContractService::markSigned() (in-person) and
 * SignatureService (OTP) — never by editing a row here. The history shows who signed
 * what, when and how: role, method, name snapshot and the signer's IP for remote
 * signatures.
 */
class SignaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'signatures';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('contracts.sections.signatures');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('signer_name_snapshot')->label(__('contracts.fields.signer_name'))->searchable(),
                TextColumn::make('signer_role')->label(__('contracts.fields.signer_role'))->badge(),
                TextColumn::make('method')->label(__('contracts.fields.signature_method'))->badge(),
                TextColumn::make('signedBy.name')->label(__('contracts.fields.witness'))->placeholder('—'),
                TextColumn::make('signed_at')->label(__('contracts.fields.signed_at'))->dateTime(),
                TextColumn::make('ip_address')->label(__('contracts.fields.ip_address'))->placeholder('—'),
            ])
            ->defaultSort('signed_at', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
