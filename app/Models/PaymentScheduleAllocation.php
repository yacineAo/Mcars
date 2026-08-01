<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which payment settled which instalment, and for how much.
 *
 * This is the join `docs/01-database-schema.md` relies on when it says a schedule
 * line's `amount_paid` is **derived**: there is no `amount_paid` column and there
 * must not be one — the figure is `sum(amount)` over the allocations pointing at
 * the line. A payment can settle more than one instalment and an instalment can be
 * settled by more than one payment, so the amount lives here rather than on either
 * side.
 *
 * Properties are declared because static analysis reads column types from the
 * schema, not from casts().
 *
 * @property int $id
 * @property int|null $branch_id
 * @property int $payment_id
 * @property int $payment_schedule_id
 * @property string $amount
 */
class PaymentScheduleAllocation extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'payment_id', 'payment_schedule_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<PaymentSchedule, $this> */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }
}
