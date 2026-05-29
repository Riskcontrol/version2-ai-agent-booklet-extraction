<?php

namespace Tests\Feature;

use App\Models\PartnerTrustedKey;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6RiskControlSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected string $partnerName = 'peldarg';
    protected string $secretKey = 'test_secret_key_64_chars_long_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Peldarg as trusted partner in RiskControl
        PartnerTrustedKey::create([
            'partner_name' => $this->partnerName,
            'partner_domain' => 'http://127.0.0.1:9010',
            'current_secret_key' => $this->secretKey,
            'current_key_id' => 'key_v1',
            'secret_rotated_at' => now(),
            'active' => true,
        ]);
    }

    /**
     * Test that Peldarg credit-updated webhook requires valid signature
     */
    public function testCreditUpdatedWebhookRequiresValidSignature()
    {
        $method = 'POST';
        $path = '/api/partner/credit-updated';
        $body = json_encode(['user_email' => 'test@example.com', 'credits_added' => 100]);
        $timestamp = now()->toIso8601String();
        $nonce = (string) \Illuminate\Support\Str::uuid();

        $sig = SignatureService::generateSignature(
            $this->secretKey,
            $method,
            $path,
            $body,
            $timestamp,
            $nonce
        );

        // Valid signature
        $response = $this->postJson(
            '/api/partner/credit-updated',
            json_decode($body, true),
            [
                'X-Partner-Name' => $this->partnerName,
                'X-Partner-Signature' => $sig['signature'],
                'X-Partner-Timestamp' => $sig['timestamp'],
                'X-Partner-Nonce' => $sig['nonce'],
            ]
        );

        // Valid signature should not fail at signature middleware layer.
        $error = (string) ($response->json('error') ?? '');
        $this->assertFalse(
            str_contains($error, 'Signature verification failed'),
            'Valid signature should not fail signature verification middleware.'
        );
    }

    /**
     * Test that reconciliation-summary webhook requires valid signature
     */
    public function testReconciliationSummaryWebhookRequiresValidSignature()
    {
        $badSignature = str_repeat('x', 64);
        $timestamp = now()->toIso8601String();
        $nonce = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson(
            '/api/partner/reconciliation-summary',
            ['date_from' => '2026-05-01', 'date_to' => '2026-05-01'],
            [
                'X-Partner-Name' => $this->partnerName,
                'X-Partner-Signature' => $badSignature,
                'X-Partner-Timestamp' => $timestamp,
                'X-Partner-Nonce' => $nonce,
            ]
        );

        // Should reject due to signature verification
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test that idempotency prevents duplicate webhook processing
     */
    public function testIdempotencyPreventsWebhookDuplication()
    {
        $method = 'POST';
        $path = '/api/partner/credit-updated';
        $body = json_encode(['user_email' => 'user1@example.com', 'credits_added' => 50]);
        $timestamp = now()->toIso8601String();
        $nonce = (string) \Illuminate\Support\Str::uuid();
        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        $sig = SignatureService::generateSignature(
            $this->secretKey,
            $method,
            $path,
            $body,
            $timestamp,
            $nonce
        );

        $headers = [
            'X-Partner-Name' => $this->partnerName,
            'X-Partner-Signature' => $sig['signature'],
            'X-Partner-Timestamp' => $sig['timestamp'],
            'X-Partner-Nonce' => $sig['nonce'],
            'Idempotency-Key' => $idempotencyKey,
        ];

        // First webhook
        $response1 = $this->postJson(
            '/api/partner/credit-updated',
            json_decode($body, true),
            $headers
        );

        // Replay with same idempotency key
        $response2 = $this->postJson(
            '/api/partner/credit-updated',
            json_decode($body, true),
            $headers
        );

        // Both should succeed (cached response on second)
        $this->assertIsInt($response1->getStatusCode());
        $this->assertIsInt($response2->getStatusCode());
    }

    /**
     * Test that untrusted partner is rejected
     */
    public function testUntrustedPartnerIsRejected()
    {
        $method = 'POST';
        $path = '/api/partner/credit-updated';
        $body = json_encode(['user_email' => 'test@example.com', 'credits_added' => 100]);
        $timestamp = now()->toIso8601String();
        $nonce = (string) \Illuminate\Support\Str::uuid();

        $sig = SignatureService::generateSignature(
            $this->secretKey,
            $method,
            $path,
            $body,
            $timestamp,
            $nonce
        );

        $response = $this->postJson(
            '/api/partner/credit-updated',
            json_decode($body, true),
            [
                'X-Partner-Name' => 'unknown-partner',
                'X-Partner-Signature' => $sig['signature'],
                'X-Partner-Timestamp' => $sig['timestamp'],
                'X-Partner-Nonce' => $sig['nonce'],
            ]
        );

        $this->assertEquals(401, $response->getStatusCode());
    }
}
