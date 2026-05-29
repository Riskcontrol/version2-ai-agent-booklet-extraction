<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerAuthorizationDecision extends Model
{
    protected $fillable = [
        'partner_request_id',
        'user_email',
        'extraction_type',
        'pages_requested',
        'decision',
        'enforcement_mode',
        'hard_blocked',
        'response_status',
        'response_payload',
        'error_message',
    ];

    protected $casts = [
        'pages_requested' => 'integer',
        'hard_blocked' => 'boolean',
        'response_status' => 'integer',
        'response_payload' => 'array',
    ];
}
