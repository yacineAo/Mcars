<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use App\Models\AlertRule;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\CarCategory;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Fine;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 22;

    /**
     * The subject of an activity row — every model that logs its changes,
     * plus the Transaction rows the ledger writes under its own 'ledger' log
     * name. Effectively fixed, so hardcoded rather than a distinct() scan of
     * the largest table in the audit cluster (gap 6 in
     * docs/resource/38-activity-log.md). Add an entry here when a model
     * starts logging.
     */
    private const SUBJECT_TYPES = [
        AlertRule::class => 'AlertRule',
        Booking::class => 'Booking',
        Branch::class => 'Branch',
        Car::class => 'Car',
        CarBlock::class => 'CarBlock',
        CarCategory::class => 'CarCategory',
        CarOwner::class => 'CarOwner',
        CarOwnershipAgreement::class => 'CarOwnershipAgreement',
        CashSession::class => 'CashSession',
        ChartOfAccount::class => 'ChartOfAccount',
        Commission::class => 'Commission',
        Contract::class => 'Contract',
        Customer::class => 'Customer',
        Deposit::class => 'Deposit',
        Employee::class => 'Employee',
        EmployeeAdvance::class => 'EmployeeAdvance',
        Expense::class => 'Expense',
        FinancialAccount::class => 'FinancialAccount',
        Fine::class => 'Fine',
        MaintenanceLog::class => 'MaintenanceLog',
        MaintenanceSchedule::class => 'MaintenanceSchedule',
        OwnerInstallment::class => 'OwnerInstallment',
        Payment::class => 'Payment',
        PayrollRun::class => 'PayrollRun',
        Transaction::class => 'Transaction',
        User::class => 'User',
        Vendor::class => 'Vendor',
    ];

    public static function getModelLabel(): string
    {
        return __('activity_log.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity_log.resource.plural_label');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('audit.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['causer', 'branch']);

        $user = Auth::user();

        if ($user !== null && ! $user->can('branches.view_all')) {
            $ids = $user->accessibleBranchIds();

            if ($ids !== []) {
                $query->whereIn('branch_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * The view-page URL for whatever a row is about, when that subject has its
     * own resource with a view page. Null — and therefore no link — for
     * subjects that only have a list/edit page or no resource at all.
     */
    public static function subjectUrl(Activity $record): ?string
    {
        $subject = $record->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        $resource = Filament::getModelResource($subject::class);

        if ($resource === null || ! isset($resource::getPages()['view'])) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $subject], panel: 'admin');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity_log.resource.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label(__('activity_log.resource.fields.log_name'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('activity_log.resource.fields.description'))
                    ->limit(60)
                    ->tooltip(fn (TextColumn $column): ?string => $column->getState())
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__('activity_log.resource.fields.event'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label(__('activity_log.resource.fields.causer'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label(__('activity_log.resource.fields.subject'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                    ->placeholder('—')
                    // The subject is why the row exists — make it navigable.
                    ->url(fn (Activity $record): ?string => self::subjectUrl($record)),

                TextColumn::make('branch.name')
                    ->label(__('activity_log.resource.fields.branch'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                // Both option sets are effectively fixed — the LogsActivity trait
                // only ever writes 'default', Transaction writes 'ledger', and the
                // custom events come from a handful of services — so hardcoding
                // beats two unbounded distinct() scans per page render (gap 6).
                SelectFilter::make('log_name')
                    ->label(__('activity_log.resource.fields.log_name'))
                    ->options(['default' => 'Default', 'ledger' => 'Ledger']),

                SelectFilter::make('event')
                    ->label(__('activity_log.resource.fields.event'))
                    ->options([
                        'created',
                        'updated',
                        'deleted',
                        'restored',
                        'made_default',
                        'roles_updated',
                        'password_reset',
                    ]),

                SelectFilter::make('causer_id')
                    ->label(__('activity_log.resource.filters.causer'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query
                            ->where('causer_type', User::class)
                            ->where('causer_id', $data['value']),
                    )),

                SelectFilter::make('subject_type')
                    ->label(__('activity_log.resource.filters.subject_type'))
                    ->options(self::SUBJECT_TYPES),

                // Filters a specific record's own trail — the other half of the
                // "History" row actions that deep-link here. A plain input rather
                // than a SelectFilter because the option space is every row in
                // audited tables.
                Filter::make('subject_id')
                    ->label(__('activity_log.resource.filters.subject_id'))
                    ->schema([
                        TextInput::make('subject_id')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['subject_id'] ?? null),
                        fn (Builder $query): Builder => $query->where('subject_id', (int) $data['subject_id']),
                    )),

                Filter::make('created_at')
                    ->label(__('activity_log.resource.filters.date_range'))
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['to'], fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('activity_log.resource.sections.details'))
                ->schema([
                    TextEntry::make('created_at')
                        ->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('log_name')->badge(),
                    TextEntry::make('event')->badge()->placeholder('—'),
                    TextEntry::make('description'),
                    TextEntry::make('causer.name')
                        ->label(__('activity_log.resource.fields.causer'))
                        ->placeholder('—'),
                    TextEntry::make('branch.name')
                        ->label(__('activity_log.resource.fields.branch'))
                        ->placeholder('—'),
                    TextEntry::make('subject_type')
                        ->label(__('activity_log.resource.fields.subject'))
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                        ->placeholder('—')
                        ->url(fn (Activity $record): ?string => self::subjectUrl($record)),
                    TextEntry::make('subject_id')
                        ->label(__('activity_log.resource.fields.subject_id'))
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('activity_log.resource.sections.changes'))
                ->schema([
                    // A per-field old → new table rather than KeyValueEntry's two
                    // json blobs (gap 5), with render-time secret redaction inside
                    // ActivityChanges (gap 1's second layer).
                    SchemaView::make('activity-log.attribute-changes'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
