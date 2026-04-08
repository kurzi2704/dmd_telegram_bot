<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DmdChainStatus extends Model
{
    protected $fillable = [
        'network',
        'latest_block_number',
        'latest_block_timestamp',
        'last_rpc_check_at',
    ];

    protected $casts = [
        'latest_block_timestamp' => 'datetime',
        'last_rpc_check_at' => 'datetime',
    ];
}
