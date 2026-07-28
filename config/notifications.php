<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Services\Notification\Drivers\DatabaseDriver;
use App\Services\Notification\Drivers\DiscordDriver;
use App\Services\Notification\Drivers\MailDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Channel drivers
    |--------------------------------------------------------------------------
    |
    | Maps a NotificationChannel to the class that delivers it. This indirection
    | is the point of the layer: swapping a provider is a change here, never in
    | NotificationService or in any calling code.
    |
    | To add WhatsApp or SMS later: write a driver implementing MessageDriver,
    | add the enum case, widen the notification_logs channel CHECK constraint in
    | a migration, and register it below.
    |
    */

    'drivers' => [
        NotificationChannel::Database->value => DatabaseDriver::class,
        NotificationChannel::Mail->value => MailDriver::class,
        NotificationChannel::Discord->value => DiscordDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discord
    |--------------------------------------------------------------------------
    |
    | Alerts are delivered to an incoming webhook. `webhooks` optionally routes a
    | given alert type to its own channel — maintenance to the workshop channel,
    | cash variance to the finance channel — falling back to `webhook_url`.
    |
    */

    'discord' => [
        'enabled' => (bool) env('DISCORD_ALERTS_ENABLED', false),
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'username' => env('DISCORD_WEBHOOK_USERNAME', 'Mcars'),
        'timeout' => (int) env('DISCORD_TIMEOUT', 10),

        // Optional per-alert-type overrides, keyed by AlertType value.
        'webhooks' => array_filter([
            'cash_variance' => env('DISCORD_WEBHOOK_FINANCE'),
            'customer_payment_overdue' => env('DISCORD_WEBHOOK_FINANCE'),
            'owner_installment_due' => env('DISCORD_WEBHOOK_FINANCE'),
            'maintenance_due' => env('DISCORD_WEBHOOK_WORKSHOP'),
            'car_document_expiring' => env('DISCORD_WEBHOOK_WORKSHOP'),
            'backup_failed' => env('DISCORD_WEBHOOK_OPS'),
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Every send is queued. A provider timeout must never stall a receptionist
    | mid-checkout, so nothing in the request path talks to a provider.
    |
    */

    'queue' => [
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION'),
        'name' => env('NOTIFICATIONS_QUEUE', 'notifications'),
        'tries' => (int) env('NOTIFICATIONS_TRIES', 3),

        // Seconds between retries. Discord rate-limits; backing off beats hammering.
        'backoff' => [60, 300, 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    |
    | Cap on subjects examined per rule per sweep. A runaway query — every booking
    | overdue after a data import — should not enqueue tens of thousands of
    | messages. When the cap bites it is logged, never silently truncated.
    |
    */

    'evaluation' => [
        'max_subjects_per_rule' => (int) env('NOTIFICATIONS_MAX_SUBJECTS_PER_RULE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | Fallback when a recipient has no locale of their own. Templates exist in
    | ar, fr and en.
    |
    */

    'default_locale' => env('NOTIFICATIONS_DEFAULT_LOCALE', 'fr'),

];
