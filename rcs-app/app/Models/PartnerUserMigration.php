<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerUserMigration extends Model
{
    protected $fillable = [
        'riskcontrol_user_email',
        'partner_user_email',
        'partner_user_id',
        'opening_balance',
        'opening_cap',
        'status',
        'migrated_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'partner_user_id' => 'integer',
        'opening_balance' => 'integer',
        'opening_cap' => 'integer',
        'migrated_at' => 'datetime',
        'metadata' => 'array',
    ];
}
