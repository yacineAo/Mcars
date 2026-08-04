<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\Pages;

use App\Filament\Admin\Resources\PaymentScheduleResource;
use App\Models\Booking;
use App\Models\Contract;
use App\Services\Payment\PaymentScheduleService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

class ListPaymentSchedules extends ListRecords
{
    protected static string $resource = PaymentScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_plan')
                ->label(__('payment_schedules.actions.generate'))
                ->icon('heroicon-o-calendar-days')
                ->form([
                    Select::make('schedulable_type')
                        ->label(__('payment_schedules.fields.plan_for'))
                        ->options([
                            Booking::class => __('payment_schedules.fields.booking'),
                            Contract::class => __('payment_schedules.fields.contract'),
                        ])
                        ->live()
                        ->required(),
                    Select::make('booking_id')
                        ->label(__('payment_schedules.fields.booking'))
                        ->options(fn (): array => PaymentScheduleResource::pinToAccessibleBranches(Booking::query())
                            ->orderBy('reference')
                            ->pluck('reference', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->visible(fn (callable $get): bool => $get('schedulable_type') === Booking::class)
                        ->required(fn (callable $get): bool => $get('schedulable_type') === Booking::class),
                    Select::make('contract_id')
                        ->label(__('payment_schedules.fields.contract'))
                        ->options(fn (): array => PaymentScheduleResource::pinToAccessibleBranches(Contract::query())
                            ->orderBy('contract_number')
                            ->pluck('contract_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->visible(fn (callable $get): bool => $get('schedulable_type') === Contract::class)
                        ->required(fn (callable $get): bool => $get('schedulable_type') === Contract::class),
                    TextInput::make('total')
                        ->label(__('payment_schedules.fields.total'))
                        ->numeric()
                        ->prefix('DZD')
                        ->required(),
                    TextInput::make('installments')
                        ->label(__('payment_schedules.fields.installments'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    DatePicker::make('first_due_date')
                        ->label(__('payment_schedules.fields.first_due_date'))
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('payment_schedules.fields.notes'))
                        ->nullable(),
                ])
                ->action(function (array $data, PaymentScheduleService $schedules): void {
                    /** @var class-string<Booking|Contract> $type */
                    $type = $data['schedulable_type'];
                    $id = $type === Booking::class ? $data['booking_id'] : $data['contract_id'];

                    /** @var Booking|Contract $schedulable */
                    $schedulable = $type::query()->findOrFail($id);

                    // The pickers above are already branch-pinned, but their options are
                    // client state: this re-derives the answer server-side from the id
                    // that actually arrived.
                    if (! PaymentScheduleResource::userCanReachBranch($schedulable->branch_id)) {
                        throw new AuthorizationException;
                    }

                    $created = $schedules->generate(
                        schedulable: $schedulable,
                        total: (string) $data['total'],
                        installments: (int) $data['installments'],
                        firstDueDate: Carbon::parse($data['first_due_date']),
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->success()
                        ->title(__('payment_schedules.notifications.generated'))
                        // Templated, not concatenated: the currency sits on the other
                        // side of the number in Arabic.
                        ->body(__('payment_schedules.notifications.generated_body', [
                            'count' => $created->count(),
                            'total' => $data['total'],
                        ]))
                        ->send();
                }),
        ];
    }
}
