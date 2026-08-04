<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The first login. Credentials come from the environment so a production seed
 * never ships the same admin@mcars.local / "password" pair as every other
 * install — in production, an unset ADMIN_EMAIL/ADMIN_PASSWORD fails the seed
 * loudly rather than falling back to something guessable. Local/CI keep the
 * old defaults so `migrate --seed` and the test suite need no .env changes.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default();

        $name = config('mcars.admin.name');
        $email = config('mcars.admin.email');
        $password = config('mcars.admin.password');

        if (App::environment('production')) {
            if (blank($email) || blank($password)) {
                throw new RuntimeException(
                    'ADMIN_EMAIL and ADMIN_PASSWORD must be set in the environment before seeding production — '.
                    'refusing to create the admin account with a guessable default.',
                );
            }
        } else {
            $email ??= 'admin@mcars.local';
            $password ??= 'password';
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'branch_id' => $branch?->id,
                'is_active' => true,
            ],
        );

        if (! $admin->hasRole(UserRole::SuperAdmin->value)) {
            $admin->assignRole(UserRole::SuperAdmin->value);
        }
    }
}
