<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractTemplateResource\Pages;

use App\Filament\Admin\Resources\ContractTemplateResource;
use App\Models\ContractTemplate;
use App\Support\ContractTemplatePreview;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ViewContractTemplate extends ViewRecord
{
    protected static string $resource = ContractTemplateResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Template Details'))
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('locale'),
                        TextEntry::make('terms_version'),
                        IconEntry::make('is_active')->boolean(),
                        IconEntry::make('is_default')->boolean(),
                    ])
                    ->columns(2),
                Section::make(__('Preview'))
                    // The caveat travels with the preview: a resolved preview otherwise
                    // reads as proof the placeholders work on a real contract.
                    ->description(__('Rendered with sample data.').' '.ContractTemplatePreview::warning())
                    ->schema([
                        TextEntry::make('body')
                            ->label(__('Rendered body'))
                            // The body is plain text, so it is escaped and only its line
                            // breaks are honoured — never rendered as markup.
                            ->formatStateUsing(
                                fn (ContractTemplate $record): HtmlString => new HtmlString(
                                    nl2br(e(ContractTemplatePreview::render($record->body))),
                                ),
                            )
                            ->extraAttributes(fn (ContractTemplate $record): array => [
                                'dir' => $record->locale->direction(),
                                'lang' => $record->locale->value,
                            ]),
                    ]),
            ]);
    }
}
