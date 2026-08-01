<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\InstallmentStatus;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleAllocation;
use App\Support\Money;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Creates a complete customer instalment plan as one atomic operation. */
class PaymentScheduleService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Build the whole plan, or none of it.
     *
     * `$total` is deliberately not a float: money enters this system as a string
     * from a form or a `decimal(18,2)` column, and the one place a float could
     * creep in is a caller typing one here.
     *
     * @return Collection<int, PaymentSchedule>
     */
    public function generate(Model $schedulable, string|int $total, int $installments, Carbon $firstDueDate, ?string $notes = null): Collection
    {
        if (! $schedulable instanceof Booking && ! $schedulable instanceof Contract) {
            throw new DomainException('Payment plans may only be attached to a booking or contract.');
        }

        if ($schedulable->customer_id === null) {
            throw new DomainException('A payment plan requires a customer.');
        }

        $amount = Money::of($total);

        if (! $amount->isPositive()) {
            throw new DomainException('The plan total must be greater than zero.');
        }

        if ($installments < 1) {
            throw new DomainException('A payment plan needs at least one instalment.');
        }

        return $this->db->transaction(function () use ($schedulable, $amount, $installments, $firstDueDate, $notes): Collection {
            // Lock the parent first. The existence check alone races: two clerks can
            // otherwise both see an empty relation and create separate plans.
            $schedulable = $schedulable->newQuery()
                ->lockForUpdate()
                ->findOrFail($schedulable->getKey());

            if ($schedulable->paymentSchedules()->exists()) {
                throw new DomainException('This booking or contract already has a payment plan.');
            }

            return collect($amount->allocate($installments))
                ->map(function (Money $part, int $index) use ($schedulable, $firstDueDate, $notes): PaymentSchedule {
                    return $schedulable->paymentSchedules()->create([
                        'customer_id' => $schedulable->customer_id,
                        'branch_id' => $schedulable->branch_id,
                        'sequence' => $index + 1,
                        'due_date' => $firstDueDate->copy()->addMonthsNoOverflow($index)->toDateString(),
                        'amount' => $part->toDecimal(),
                        'status' => InstallmentStatus::Pending,
                        'notes' => $notes,
                    ]);
                });
        });
    }

    /**
     * Settle one instalment: take the money, post it, and record which line it paid.
     *
     * The allocation row is what makes a schedule line's paid amount derivable
     * (`docs/01-database-schema.md` → `payment_schedules`). It shares the payment's
     * transaction, because an allocation without its payment describes a settlement
     * that never happened.
     *
     * @param array{method: string, financial_account_id?: int|null, notes?: string|null} $data
     */
    public function recordPayment(PaymentSchedule $schedule, array $data, int $userId): PaymentSchedule
    {
        return $this->db->transaction(function () use ($schedule, $data, $userId): PaymentSchedule {
            $schedule = PaymentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $this->assertUnpaid($schedule);

            $schedulable = $schedule->schedulable;

            $booking = $schedulable instanceof Booking
                ? $schedulable
                : ($schedulable instanceof Contract ? $schedulable->booking : null);

            if (! $booking instanceof Booking) {
                throw new DomainException('A schedule payment requires a booking.');
            }

            $payment = $this->payments->recordBookingPayment($booking, [
                ...$data,
                'amount' => $schedule->amount,
            ], $userId);

            PaymentScheduleAllocation::create([
                'branch_id' => $schedule->branch_id,
                'payment_id' => $payment->id,
                'payment_schedule_id' => $schedule->id,
                'amount' => $schedule->amount,
            ]);

            $schedule->update(['status' => InstallmentStatus::Paid]);

            return $schedule->refresh();
        });
    }

    public function reschedule(PaymentSchedule $schedule, Carbon $dueDate): PaymentSchedule
    {
        return $this->db->transaction(function () use ($schedule, $dueDate): PaymentSchedule {
            $schedule = PaymentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $this->assertUnpaid($schedule);

            $schedule->update(['due_date' => $dueDate->toDateString()]);

            return $schedule->refresh();
        });
    }

    /**
     * Write off one instalment without money moving.
     *
     * A schedule line is never posted to the ledger by itself — only the
     * payments settling it are (through their allocation) — so a waiver is a
     * status transition with an audit trail, not a posting. The reason is
     * mandatory, and the actor and moment are stamped so the decision can be
     * questioned later.
     */
    public function waive(PaymentSchedule $schedule, string $reason, int $userId): PaymentSchedule
    {
        if (trim($reason) === '') {
            throw new DomainException('A waiver needs a reason.');
        }

        return $this->db->transaction(function () use ($schedule, $reason, $userId): PaymentSchedule {
            $schedule = PaymentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $this->assertUnpaid($schedule);

            $schedule->update([
                'status' => InstallmentStatus::Waived,
                'waived_reason' => trim($reason),
                'waived_at' => now(),
                'waived_by_id' => $userId,
            ]);

            return $schedule->refresh();
        });
    }

    /**
     * Overdue counts as unpaid: an instalment whose date has passed is precisely the
     * one a clerk needs to move or settle, and locking it out would leave the only
     * fix as editing the row by hand.
     */
    private function assertUnpaid(PaymentSchedule $schedule): void
    {
        if ($schedule->status !== InstallmentStatus::Pending && $schedule->status !== InstallmentStatus::Overdue) {
            throw new DomainException('Only an unpaid instalment can be changed.');
        }
    }
}
