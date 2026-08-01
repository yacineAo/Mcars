<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentResource\Pages;

use App\Filament\Admin\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * Post the payment to the ledger as soon as it is recorded.
     *
     * A payment that exists only as a `payments` row is invisible to every balance
     * in the system: the customer still shows as owing, cash on hand is understated
     * and the day's takings are wrong. Recording and posting are one action, not
     * two, so there is no window in which the two disagree.
     */
    protected function afterCreate(): void
    {
        /** @var Payment $payment */
        $payment = $this->record;

        try {
            app(PaymentService::class)->recordPayment($payment, (int) Auth::id());
        } catch (Throwable $e) {
            // The payment row is already committed. Surfacing the failure loudly
            // beats a silent half-state — the operator can retry with the
            // "Post to ledger" action once the cause is fixed.
            //
            // Reported as well as shown: a posting that fails at the counter is an
            // operations problem, and nobody would learn of it from a notification
            // the receptionist dismisses. The driver's own message stays in the log
            // rather than on screen — it is addressed to whoever fixes the cause,
            // not to the person holding the customer's cash.
            report($e);

            Notification::make()
                ->danger()
                ->title(__('payments.notifications.post_failed'))
                ->body(__('payments.notifications.post_failed_body'))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('payments.notifications.posted'))
            ->send();
    }
}
