<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\EmployeeStatus;
use App\Filament\Admin\Concerns\ChecksBranchAccess;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\EmployeeResource\Pages;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EmployeeResource extends Resource
{
    use ChecksBranchAccess, TranslatesModelLabel;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'HR';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('hr.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('hr.manage') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Employee && self::userCanReachBranch($record->branch_id);
    }

    public static function canEdit(Model $record): bool
    {
        return (Auth::user()?->can('hr.manage') ?? false)
            && $record instanceof Employee
            && self::userCanReachBranch($record->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        // Master data referenced by payroll items, advances and commissions.
        // There is no delete path at all — an employee leaves by a status
        // change (terminated), never by removing the row history points at.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Pins every page — list, view, edit — to the branches the user can
        // reach, server-side, before any filter they submit. Rows of a branch
        // the user cannot reach never exist as far as the page is concerned.
        return self::pinToAccessibleBranches(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // The number is minted by EmployeeService through
                // SequenceGenerator on create, inside the same transaction as
                // the insert. It is never typed, and once issued it is
                // immutable — the same shape as a contract number.
                TextInput::make('employee_number')
                    ->label(__('employees.fields.employee_number'))
                    ->hiddenOn('create')
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('employees.fields.employee_number_help')),
                TextInput::make('first_name')->label(__('employees.fields.first_name'))->required()->maxLength(255),
                TextInput::make('last_name')->label(__('employees.fields.last_name'))->required()->maxLength(255),
                TextInput::make('job_title')->label(__('employees.fields.job_title'))->nullable(),
                TextInput::make('department')->label(__('employees.fields.department'))->nullable(),
                // Payroll confidentiality: the salary field exists only for
                // roles holding hr.view_salary. It is still required whenever
                // it renders — a payroll run reads it.
                TextInput::make('base_salary')
                    ->label(__('employees.fields.base_salary'))
                    ->numeric()
                    ->required()
                    ->prefix('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('hr.view_salary') ?? false),
                DatePicker::make('hire_date')->label(__('employees.fields.hire_date'))->required(),
                Select::make('contract_type')
                    ->label(__('employees.fields.contract_type'))
                    ->options(['cdi' => 'CDI', 'cdd' => 'CDD', 'trial' => 'Trial', 'freelance' => 'Freelance'])
                    ->default('cdi')
                    ->required(),
                Select::make('status')
                    ->label(__('employees.fields.status'))
                    ->options(EmployeeStatus::options())
                    ->default(EmployeeStatus::Active->value)
                    ->required(),
                TextInput::make('phone')->label(__('employees.fields.phone'))->nullable(),
                TextInput::make('bank_rib')->label(__('employees.fields.bank_rib'))->nullable(),
                Textarea::make('notes')->label(__('employees.fields.notes'))->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_number')->searchable()->sortable(),
                TextColumn::make('first_name')->searchable(),
                TextColumn::make('last_name')->searchable(),
                TextColumn::make('job_title')->searchable(),
                TextColumn::make('department')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('hire_date')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(EmployeeStatus::options()),
                // Distinct values from the rows the user can actually see, not
                // a fixed list that enumerates departments nobody in this
                // branch uses — same shape as the pinned options elsewhere.
                SelectFilter::make('department')
                    ->options(fn (): array => self::pinToAccessibleBranches(Employee::query())
                        ->whereNotNull('department')
                        ->distinct()
                        ->orderBy('department')
                        ->pluck('department', 'department')
                        ->all()),
                SelectFilter::make('job_title')
                    ->options(fn (): array => self::pinToAccessibleBranches(Employee::query())
                        ->whereNotNull('job_title')
                        ->distinct()
                        ->orderBy('job_title')
                        ->pluck('job_title', 'job_title')
                        ->all()),
            ])
            ->actions([
                EditAction::make(),
            ])
            // No bulk delete: employees are master data that payroll history
            // references. An employee leaves by a status change, never by
            // removing the row.
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
