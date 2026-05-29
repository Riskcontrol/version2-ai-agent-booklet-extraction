<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerRequestIdempotency extends Model
{
    protected $table = 'partner_request_idempotency';
    protected $fillable = [
        'idempotency_key',
        'partner_name',
        'request_method',
        'request_path',
        'request_body',
        'response_status',
        'response_body',
        'signature_algorithm',
        'request_timestamp',
        'request_nonce',
    ];

    public static function recordRequest(
        string $idempotencyKey,
        string $partnerName,
        string $method,
        string $path,
        string $body,
        string $algorithm = 'hmac-sha256',
        string $timestamp = '',
        string $nonce = ''
    ): self {
        return self::create([
            'idempotency_key' => $idempotencyKey,
            'partner_name' => $partnerName,
            'request_method' => $method,
            'request_path' => $path,
            'request_body' => $body,
            'signature_algorithm' => $algorithm,
            'request_timestamp' => $timestamp,
            'request_nonce' => $nonce,
        ]);
    }

    public function recordResponse(int $status, string $body): void
    {
        $this->update(['response_status' => $status, 'response_body' => $body]);
    }
}
