<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DmdPool extends Model
{
    protected $fillable = [
        'staking_address',
        'mining_address',
        'score',
        'is_active',
        'is_to_be_elected',
        'is_pending_validator',
        'frontend_valid',
        'frontend_status',
        'is_faulty_validator',
        'connectivity_report',
        'total_stake',
        'available_since',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_to_be_elected' => 'boolean',
        'is_pending_validator' => 'boolean',
        'frontend_valid' => 'boolean',
        'is_faulty_validator' => 'boolean',
    ];
}
