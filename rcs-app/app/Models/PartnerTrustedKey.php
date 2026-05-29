<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerTrustedKey extends Model
{
    protected $table = 'partner_trusted_keys';
    protected $fillable = [
        'partner_name',
        'partner_domain',
        'current_secret_key',
        'current_key_id',
        'secret_rotated_at',
        'active',
    ];

    protected $casts = [
        'secret_rotated_at' => 'datetime',
        'active' => 'boolean',
    ];

    public static function findByPartnerName(string $partnerName): ?self
    {
        return self::where('partner_name', $partnerName)->where('active', true)->first();
    }
}
