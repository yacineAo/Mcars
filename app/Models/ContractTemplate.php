<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id',
        'name',
        'locale',
        'body',
        'terms_version',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
