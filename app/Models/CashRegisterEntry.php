<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegisterEntry extends Model
{
    protected $table = 'cash_register_entries';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'posted_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }
}
