<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\DigestMail;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily digest for users who opted out of immediate emails.
 *
 * Summarises the alerts raised for them in the last 24 hours, drawn from the
 * in-app notifications they did receive — so a digest user sees exactly what a
 * non-digest user saw, bundled.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'alerts:digest {--now= : Treat this as the current moment}';

    protected $description = 'Send the daily alert digest to users who opted in';

    public function handle(MailFactory $mailer): int
    {
        $now = CarbonImmutable::parse((string) ($this->option('now') ?? 'now'));
        $since = $now->subDay();
        $sent = 0;

        $users = User::query()
            ->where('is_active', true)
            ->where('notification_digest', true)
            // Only users whose configured send time has come round in this hour, so
            // an hourly scheduler does not mail everyone at midnight.
            ->whereRaw('EXTRACT(HOUR FROM notification_digest_at) = ?', [$now->hour])
            ->get();

        foreach ($users as $user) {
            $entries = NotificationLog::query()
                ->where('user_id', $user->getKey())
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            if ($user->email === '') {
                continue;
            }

            try {
                $mailer->mailer()->to($user->email)->send(new DigestMail($user, $entries, $since, $now));
                $sent++;
            } catch (Throwable $e) {
                // One bad address must not stop the rest of the digests.
                Log::error('Failed to send notification digest.', [
                    'user_id' => $user->getKey(),
                    'exception' => $e,
                ]);
            }
        }

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
