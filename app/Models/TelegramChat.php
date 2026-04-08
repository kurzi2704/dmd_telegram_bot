<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramChat extends Model
{
    protected $fillable = [
        'chat_id',
        'type',
        'username',
        'first_name',
        'last_name',
        'is_active',
        'wants_all_pool_notifications',
        'wants_epoch_notifications',
        'pending_command',
        'last_interaction_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'wants_all_pool_notifications' => 'boolean',
        'wants_epoch_notifications' => 'boolean',
        'last_interaction_at' => 'datetime',
    ];

    public function poolSubscriptions(): HasMany
    {
        return $this->hasMany(TelegramPoolSubscription::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(TelegramNotificationLog::class);
    }
}
