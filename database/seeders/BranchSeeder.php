<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * The single default branch every existing row belongs to.
 *
 * Idempotent: safe to re-run on an environment that already has it. Roles, users
 * and permissions are seeded in Phase 1.
 */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Branch',
                'city' => 'Alger',
                'wilaya' => 'Alger',
                'timezone' => 'Africa/Algiers',
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }
}
