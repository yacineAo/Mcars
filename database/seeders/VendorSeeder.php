<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            ['name' => 'ENPI Garage Central', 'type' => 'garage', 'contact_name' => 'Ahmed'],
            ['name' => 'SAA Insurance', 'type' => 'insurance', 'contact_name' => 'Karim'],
            ['name' => 'Naftal Station', 'type' => 'fuel_station', 'contact_name' => 'Service'],
            ['name' => 'Auto Parts Algiers', 'type' => 'parts', 'contact_name' => 'Yacine'],
        ];

        foreach ($vendors as $v) {
            Vendor::firstOrCreate(
                ['name' => $v['name']],
                $v,
            );
        }
    }
}
