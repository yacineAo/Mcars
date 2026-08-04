<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CarCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarCategory extends Model
{
    /** @use HasFactory<CarCategoryFactory> */
    use HasAuditColumns, HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Car, $this> */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }
}
