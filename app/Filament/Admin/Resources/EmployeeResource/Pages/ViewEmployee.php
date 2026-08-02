<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\AdvancesRelationManager;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\CommissionsRelationManager;
use App\Filament\Admin\Resources\EmployeeResource\RelationManagers\PayrollItemsRelationManager;
use App\Models\Employee;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The full employment record — and the only place salary lives on screen:
 * base_salary renders behind the hr.view_salary gate, and so do the three pay
 * relations below it, so a supervisor who may read who works where never sees
 * what anyone earns.
 */
class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('employees.sections.identity'))
                    ->schema([
                        TextEntry::make('employee_number')->label(__('employees.fields.employee_number')),
                        TextEntry::make('first_name')->label(__('employees.fields.first_name')),
                        TextEntry::make('last_name')->label(__('employees.fields.last_name')),
                        TextEntry::make('job_title')->label(__('employees.fields.job_title'))->placeholder('—'),
                        TextEntry::make('department')->label(__('employees.fields.department'))->placeholder('—'),
                        TextEntry::make('status')->label(__('employees.fields.status'))->badge(),
                    ])
                    ->columns(4),

                Section::make(__('employees.sections.employment'))
                    ->schema([
                        TextEntry::make('hire_date')
                            ->label(__('employees.fields.hire_date'))
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('termination_date')
                            ->label(__('employees.fields.termination_date'))
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('termination_reason')
                            ->label(__('employees.fields.termination_reason'))
                            ->placeholder('—'),
                        TextEntry::make('contract_type')
                            ->label(__('employees.fields.contract_type'))
                            ->placeholder('—'),
                    ])
                    ->columns(4),

                Section::make(__('employees.sections.contact'))
                    ->schema([
                        TextEntry::make('phone')->label(__('employees.fields.phone'))->placeholder('—'),
                        TextEntry::make('bank_rib')->label(__('employees.fields.bank_rib'))->placeholder('—'),
                        TextEntry::make('ccp_account')->label(__('employees.fields.ccp_account'))->placeholder('—'),
                        TextEntry::make('national_id')->label(__('employees.fields.national_id'))->placeholder('—'),
                    ])
                    ->columns(4),

                // Payroll confidentiality: the salary is invisible to anyone
                // without hr.view_salary (supervisors read the directory, not
                // the payslips).
                Section::make(__('employees.sections.salary'))
                    ->schema([
                        TextEntry::make('base_salary')
                            ->label(__('employees.fields.base_salary'))
                            ->money('DZD'),
                        TextEntry::make('salary_type')
                            ->label(__('employees.fields.salary_type'))
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => Auth::user()?->can('hr.view_salary') ?? false),

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

    /**
     * The three pay histories are the reason the view page exists, and each is
     * gated on hr.view_salary by its own canViewForRecord — one employee's own
     * pay on their own record is fine, another employee seeing it is not, and
     * the distinction is enforced here by simply restricting who may read pay
     * at all.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('employees.sections.pay_history'), [
                PayrollItemsRelationManager::class,
                AdvancesRelationManager::class,
                CommissionsRelationManager::class,
            ]),
        ];
    }
}
