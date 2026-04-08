<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPoolSubscription extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'staking_address',
        'notify_frontend_status',
        'notify_score',
        'notify_connectivity_report',
        'notify_total_stake',
    ];

    protected $casts = [
        'notify_frontend_status' => 'boolean',
        'notify_score' => 'boolean',
        'notify_connectivity_report' => 'boolean',
        'notify_total_stake' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }
}
