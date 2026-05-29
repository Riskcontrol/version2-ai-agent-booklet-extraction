<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerAuthorizationRejection extends Model
{
    protected $fillable = [
        'user_email',
        'partner_request_id',
        'partner_name',
        'partner_domain',
        'extraction_type',
        'pages_requested',
        'returned_api_tier',
        'reason',
        'payload',
    ];

    protected $casts = [
        'pages_requested' => 'integer',
        'payload' => 'array',
    ];
}