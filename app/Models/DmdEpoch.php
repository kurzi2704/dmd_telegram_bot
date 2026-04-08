<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DmdEpoch extends Model
{
    protected $fillable = [
        'staking_epoch',
        'keygen_round',
        'staking_epoch_start_time',
        'staking_epoch_start_block',
        'are_stake_and_withdraw_allowed',
        'staking_fixed_epoch_end_time',
        'staking_fixed_epoch_duration',
        'staking_withdraw_disallow_period',
        'delta_pot',
        'reinsert_pot',
    ];

    protected $casts = [
        'are_stake_and_withdraw_allowed' => 'boolean',
    ];
}
