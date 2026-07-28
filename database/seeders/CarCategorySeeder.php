<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CarCategory;
use Illuminate\Database\Seeder;

class CarCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Economy', 'slug' => 'economy', 'sort_order' => 1],
            ['name' => 'Compact', 'slug' => 'compact', 'sort_order' => 2],
            ['name' => 'SUV', 'slug' => 'suv', 'sort_order' => 3],
            ['name' => 'Luxury', 'slug' => 'luxury', 'sort_order' => 4],
            ['name' => 'Utility', 'slug' => 'utility', 'sort_order' => 5],
            ['name' => 'Van', 'slug' => 'van', 'sort_order' => 6],
        ];

        foreach ($categories as $cat) {
            CarCategory::firstOrCreate(
                ['slug' => $cat['slug']],
                $cat,
            );
        }
    }
}
