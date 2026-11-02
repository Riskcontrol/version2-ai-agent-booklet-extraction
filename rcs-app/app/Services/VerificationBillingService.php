<?php

namespace App\Services;

use App\Models\VerificationBatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class VerificationBillingService
{
    private function post(string $path, array $payload): array
    {
        $base = rtrim((string) config('services.partner.base_url'), '/');
        $token = (string) config('services.partner.token');
        $secret = (string) config('services.partner.signature_secret');
        if (!$base || !$token || !$secret) {
            throw new RuntimeException('Partner billing signing credentials are not configured.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $sig = SignatureService::generateSignature($secret, 'POST', $path, $body);
        // One HTTP attempt only. Never call retry() or replay a timed-out request.
        $response = Http::withHeaders([
            'X-Partner-Token' => $token, 'X-Partner-Name' => config('services.partner.partner_name', 'riskcontrol'),
            'X-Partner-Signature' => $sig['signature'], 'X-Partner-Timestamp' => $sig['timestamp'],
            'X-Partner-Nonce' => $sig['nonce'], 'X-Signature-Algorithm' => $sig['algorithm'],
            'Idempotency-Key' => $payload['partner_request_id'].':'.basename($path), 'Accept' => 'application/json',
        ])->withBody($body, 'application/json')->connectTimeout(5)
            ->timeout((int) config('services.partner.timeout', 15))->post($base.$path);
        if (!$response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?? $response->json('message') ?? 'Billing request rejected.'));
        }
        return (array) $response->json();
    }

    public function authorize(VerificationBatch $batch, string $userName): void
    {
        // Persist before sending: ambiguous network failures cannot start a second authorization.
        $batch->update(['partner_request_id' => (string) Str::uuid(), 'credit_status' => 'authorizing']);
        $payload = $this->post('/api/partner/authorize-extraction', [
            'partner_request_id' => $batch->partner_request_id, 'user_email' => $batch->user_email,
            'pages_requested' => $batch->pages_requested, 'extraction_type' => 'certificates',
            'partner_name' => config('services.partner.partner_name', 'riskcontrol'), 'partner_domain' => config('app.url'),
            'partner_user_reference' => $userName,
            'workload_metadata' => ['operation' => 'verify_certificate_report', 'batch_id' => $batch->id,
                'pdf_files' => count($batch->inventory ?? []), 'page_start' => 1, 'page_end' => $batch->pages_requested],
        ]);
        $batch->update(['credits_reserved' => (int) ($payload['credits_reserved'] ?? 0), 'credit_status' => 'authorized']);
        if (($payload['api_tier'] ?? '') !== 'paid_1') {
            throw new RuntimeException('Partner authorization returned an unsupported workflow tier.');
        }
        $batch->update(['api_key_tier' => 'GEMINI_API_KEY_PAID']);
    }

    public function settle(VerificationBatch $batch): void
    {
        // Atomic claim means duplicate callbacks cannot resend settlement.
        if (!$batch->partner_request_id || !VerificationBatch::whereKey($batch->id)
            ->where('credit_status', 'authorized')->update(['credit_status' => 'settling'])) {
            return;
        }
        try {
            $result = $this->post('/api/partner/finalize-extraction', [
                'partner_request_id' => $batch->partner_request_id,
                'status' => $batch->status === 'complete' ? 'success' : 'failed',
                'pages_processed' => $batch->pages_processed, 'pages_with_results' => $batch->pages_with_results,
                'failed_reason' => $batch->failed_reason,
            ]);
            $batch->update(['credit_status' => (string) ($result['status'] ?? 'settled'),
                'credits_consumed' => (int) ($result['credits_consumed'] ?? 0),
                'credits_refunded' => (int) ($result['credits_refunded'] ?? 0)]);
        } catch (\Throwable $e) {
            $batch->update(['credit_status' => 'settlement_failed']);
            report($e);
        }
    }
}
