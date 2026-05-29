<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;

class SignatureService
{
    public const ALGORITHM = 'hmac-sha256';
    public const MAX_TIMESTAMP_AGE_SECONDS = 300; // 5 minutes
    private static array $nonces = [];

    /**
     * Generate HMAC signature for request body.
     */
    public static function generateSignature(
        string $secretKey,
        string $method,
        string $path,
        string $body,
        string $timestamp = '',
        string $nonce = ''
    ): array {
        $timestamp = $timestamp ?: now()->toIso8601String();
        $nonce = $nonce ?: (string) \Illuminate\Support\Str::uuid();

        $signingString = implode("\n", [
            $method,
            $path,
            $timestamp,
            $nonce,
            $body,
        ]);

        $signature = hash_hmac('sha256', $signingString, $secretKey);

        return [
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'algorithm' => self::ALGORITHM,
        ];
    }

    /**
     * Verify request signature and check timestamp/nonce validity.
     */
    public static function verifySignature(
        string $secretKey,
        string $method,
        string $path,
        string $body,
        string $signature,
        string $timestamp,
        string $nonce
    ): array {
        // Verify timestamp is recent
        $timestampError = self::validateTimestamp($timestamp);
        if ($timestampError) {
            return ['valid' => false, 'error' => $timestampError];
        }

        // Verify nonce uniqueness
        $nonceError = self::validateNonce($nonce);
        if ($nonceError) {
            return ['valid' => false, 'error' => $nonceError];
        }

        // Calculate expected signature
        $expectedSig = self::generateSignature($secretKey, $method, $path, $body, $timestamp, $nonce);
        
        // Timing-safe comparison
        if (!hash_equals($signature, $expectedSig['signature'])) {
            return ['valid' => false, 'error' => 'Signature mismatch'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate that timestamp is within acceptable age.
     */
    private static function validateTimestamp(string $timestamp): ?string
    {
        try {
            $requestTime = \Carbon\Carbon::parse($timestamp);
            $now = now();
            $diff = $now->diffInSeconds($requestTime, false);

            if ($diff > self::MAX_TIMESTAMP_AGE_SECONDS || $diff < -self::MAX_TIMESTAMP_AGE_SECONDS) {
                return 'Request timestamp is too old or in future (max age: ' . self::MAX_TIMESTAMP_AGE_SECONDS . ' seconds)';
            }

            return null;
        } catch (Exception $e) {
            return 'Invalid timestamp format: ' . $e->getMessage();
        }
    }

    /**
     * Validate that nonce is unique (not replayed).
     */
    private static function validateNonce(string $nonce): ?string
    {
        // Check in-memory cache
        if (isset(self::$nonces[$nonce])) {
            return 'Nonce replay detected: ' . $nonce;
        }

        // Check in database
        if (DB::table('partner_request_idempotency')->where('request_nonce', $nonce)->exists()) {
            return 'Nonce replay detected in database: ' . $nonce;
        }

        // Record nonce
        self::$nonces[$nonce] = true;

        return null;
    }
}
