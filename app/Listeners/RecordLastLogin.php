<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Wires the dead-schema columns of docs/resource/33-user.md gap 9: last_login_at
 * and last_login_ip were cast on the model and never written by anything. Fired
 * from Laravel's Login event, which Filament's login page dispatches.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();
    }
}
