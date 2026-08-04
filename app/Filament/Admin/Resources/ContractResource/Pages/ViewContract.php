<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractResource\Pages;

use App\Enums\ConditionReportType;
use App\Enums\ContractStatus;
use App\Enums\SignerRole;
use App\Filament\Admin\Resources\ContractResource;
use App\Models\ConditionReport;
use App\Models\Contract;
use App\Services\Booking\ContractService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * The lifecycle actions used to be row actions on the list — seven icons per
     * row made it unusable, and neither this page nor the edit page showed any of
     * them at all. They live here now instead: the record's own status and stored
     * document are visible right next to the buttons that change them.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('render_pdf')
                ->label(__('contracts.actions.render_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->action(function (Contract $record): void {
                    app(ContractService::class)->renderPdf($record);

                    Notification::make()->success()->title(__('contracts.notifications.pdf_generated'))->send();
                })
                ->visible(fn (Contract $record): bool => ContractResource::canOperate() && ! $record->status->is(ContractStatus::Draft)),

            Action::make('download_pdf')
                ->label(__('contracts.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (Contract $record): string => URL::temporarySignedRoute(
                    'contracts.pdf.download',
                    now()->addMinutes(5),
                    ['contract' => $record->id],
                ))
                ->openUrlInNewTab()
                ->visible(fn (Contract $record): bool => $record->pdf_path !== null),

            Action::make('send')
                ->label(__('contracts.actions.send'))
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->requiresConfirmation()
                ->action(function (Contract $record): void {
                    app(ContractService::class)->send($record);

                    Notification::make()->success()->title(__('contracts.notifications.sent'))->send();
                })
                ->visible(fn (Contract $record): bool => ContractResource::canOperate()
                    && $record->content_snapshot !== null
                    && $record->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature)),

            Action::make('sign')
                ->label(__('contracts.actions.sign'))
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('contracts.actions.sign_heading'))
                ->modalDescription(__('contracts.actions.sign_description'))
                ->form([
                    Select::make('signer_role')
                        ->label(__('contracts.fields.signer_role'))
                        ->options(SignerRole::options())
                        ->required(),
                    TextInput::make('signer_name')
                        ->label(__('contracts.fields.signer_name'))
                        ->required(),
                ])
                ->action(function (Contract $record, array $data): void {
                    try {
                        app(ContractService::class)->markSigned(
                            $record,
                            SignerRole::from($data['signer_role']),
                            $data['signer_name'],
                            Auth::user(),
                        );
                    } catch (RuntimeException $e) {
                        // A concurrent desk may have signed in the same moment —
                        // show the service's refusal rather than a 500.
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('contracts.notifications.signed'))->send();
                })
                ->visible(fn (Contract $record): bool => ContractResource::canSign()
                    && $record->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature)),

            Action::make('close')
                ->label(__('contracts.actions.close'))
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->form([
                    Select::make('checkin_report')
                        ->label(__('contracts.fields.checkin_report'))
                        ->options(fn (Contract $record): array => ConditionReport::query()
                            ->where('booking_id', $record->booking_id)
                            ->where('type', ConditionReportType::Checkin)
                            ->get()
                            ->mapWithKeys(fn (ConditionReport $report) => [
                                $report->id => $report->performed_at?->format('Y-m-d H:i'),
                            ])
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (Contract $record, array $data): void {
                    try {
                        $report = ConditionReport::findOrFail($data['checkin_report']);

                        app(ContractService::class)->close($record, $report, Auth::user());
                    } catch (RuntimeException $e) {
                        // The report/contract pairing is enforced in the service —
                        // surface its refusal instead of a 500.
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('contracts.notifications.closed'))->send();
                })
                ->visible(fn (Contract $record): bool => ContractResource::canOperate()
                    && $record->status->is(ContractStatus::Active, ContractStatus::Signed)),
        ];
    }

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make(__('contracts.sections.document'))
                    ->schema([
                        TextEntry::make('document')
                            ->hiddenLabel()
                            ->html()
                            // Shared with renderPdf() via ContractService::renderDocumentHtml()
                            // so the screen and the PDF can never disagree on content.
                            ->state(fn (Contract $record): string => app(ContractService::class)->renderDocumentHtml($record))
                            // The snapshot embeds the template's language; an Arabic
                            // contract renders right-to-left even in an LTR panel.
                            ->extraAttributes(fn (Contract $record): array => ['dir' => $record->direction()])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('contracts.sections.identity'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('contract_number')->label(__('contracts.fields.contract_number')),
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('booking.reference')->label(__('Booking')),
                        TextEntry::make('customer')
                            ->label(__('Customer'))
                            ->state(fn (Contract $record): string => $record->customer?->displayName() ?? '—'),
                        TextEntry::make('car.registration_number')->label(__('Car')),
                        TextEntry::make('generated_at')->label(__('Generated'))->dateTime(),
                        TextEntry::make('signed_at')->label(__('Signed'))->dateTime()->placeholder('—'),
                        TextEntry::make('terms_version')->label(__('contracts.fields.terms_version')),
                        TextEntry::make('insurance_type')->label(__('Insurance type'))->placeholder('—'),
                        TextEntry::make('franchise_amount')->label(__('Franchise amount'))->money('DZD')->placeholder('—'),
                        TextEntry::make('document_hash')->label(__('contracts.fields.document_hash'))->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('closing_notes')->label(__('Closing notes'))->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
