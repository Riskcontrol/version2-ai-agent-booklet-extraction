<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerCreditSyncEvent extends Model
{
    protected $fillable = [
        'event_type',
        'user_email',
        'credit_balance',
        'credit_cap',
        'reported_status',
        'meta',
        'occurred_at',
        'received_at',
        'source_ip',
        'auth_valid',
        'processing_status',
        'error_message',
        'raw_payload',
    ];

    protected $casts = [
        'meta' => 'array',
        'raw_payload' => 'array',
        'auth_valid' => 'boolean',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'credit_balance' => 'integer',
        'credit_cap' => 'integer',
    ];
}
