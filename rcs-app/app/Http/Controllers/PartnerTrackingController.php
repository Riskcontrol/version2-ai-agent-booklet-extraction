<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerTrackingController extends Controller
{
    private function trackingUiEnabled(): bool
    {
        return (bool) config('services.partner.tracking_ui_enabled', true);
    }

    private function trackingObservabilityEnabled(): bool
    {
        return (bool) config('services.partner.tracking_observability_enabled', true);
    }

    private function redactedRequestId(?string $requestId): string
    {
        if (!$requestId) {
            return 'none';
        }

        if (strlen($requestId) <= 8) {
            return '***';
        }

        return substr($requestId, 0, 4) . '...' . substr($requestId, -4);
    }

    private function userHash(?string $email): string
    {
        return substr(hash('sha256', strtolower((string) $email)), 0, 12);
    }

    private function partnerConfig(): array
    {
        return [
            'base_url' => rtrim((string) config('services.partner.base_url', ''), '/'),
            'token' => (string) config('services.partner.token', ''),
            'timeout' => (int) config('services.partner.timeout', 15),
        ];
    }

    private function signedPartnerHeaders(string $method, string $path, array $payload): array
    {
        $token = (string) config('services.partner.token', '');
        $partnerName = (string) config('services.partner.partner_name', 'riskcontrol');
        $secret = (string) config('services.partner.signature_secret', '');

        if ($token === '' || $partnerName === '' || $secret === '') {
            throw ValidationException::withMessages([
                'partner' => 'Partner signing configuration is missing (token, partner name, or signature secret).',
            ]);
        }

        $body = json_encode($payload);
        if (!is_string($body)) {
            throw ValidationException::withMessages(['partner' => 'Unable to encode partner request payload.']);
        }

        $sig = SignatureService::generateSignature($secret, strtoupper($method), $path, $body);

        return [
            'X-Partner-Token' => $token,
            'X-Partner-Name' => $partnerName,
            'X-Partner-Signature' => $sig['signature'],
            'X-Partner-Timestamp' => $sig['timestamp'],
            'X-Partner-Nonce' => $sig['nonce'],
            'X-Signature-Algorithm' => $sig['algorithm'],
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ];
    }

    public function show(Document $doc, Request $request)
    {
        abort_unless($this->trackingUiEnabled(), 404);
        abort_unless((string) $doc->user_email === (string) $request->session()->get('user_email', ''), 404);

        return view('tracking', [
            'document' => $doc,
        ]);
    }

    public function progress(Document $doc, Request $request)
    {
        abort_unless($this->trackingUiEnabled(), 404);
        abort_unless((string) $doc->user_email === (string) $request->session()->get('user_email', ''), 404);

        $base = [
            'document_id' => (int) $doc->id,
            'filename' => (string) $doc->filename,
            'status' => (string) $doc->status,
            'credit_status' => (string) ($doc->credit_status ?? 'none'),
            'pages_requested' => (int) ($doc->pages_requested ?? 0),
            'pages_processed' => (int) ($doc->pages_processed ?? 0),
            'pages_with_results' => (int) ($doc->pages_with_results ?? 0),
            'partner_request_id' => $doc->partner_request_id,
            'payment_reference' => $doc->payment_reference,
            'failed_reason' => $doc->failed_reason,
            'csv_download' => $doc->csv_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $doc->id, 'type' => 'csv']) : null,
            'xlsx_download' => $doc->xlsx_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $doc->id, 'type' => 'xlsx']) : null,
            'created_at' => optional($doc->created_at)->toIso8601String(),
        ];

        if (!$doc->partner_request_id) {
            return response()->json(array_merge($base, [
                'phase' => $doc->status === 'complete' ? 'completed' : ($doc->status === 'failed' ? 'failed' : 'processing'),
                'progress_percent' => $doc->status === 'complete' ? 100 : 0,
                'partner_tracking' => null,
            ]));
        }

        $config = $this->partnerConfig();
        if ($config['base_url'] === '' || $config['token'] === '') {
            return response()->json(array_merge($base, [
                'phase' => 'processing',
                'progress_percent' => 0,
                'partner_tracking' => null,
                'tracking_error' => 'Partner tracking integration is not configured.',
            ]), 503);
        }

        $payload = [
            'partner_request_id' => (string) $doc->partner_request_id,
            'user_email' => (string) $doc->user_email,
        ];

        if ($this->trackingObservabilityEnabled()) {
            Log::info('tracking.progress.read.start', [
                'document_id' => (int) $doc->id,
                'status' => (string) $doc->status,
                'request_id' => $this->redactedRequestId($doc->partner_request_id),
                'user_hash' => $this->userHash((string) $doc->user_email),
            ]);
        }

        $response = Http::withHeaders($this->signedPartnerHeaders('POST', '/api/partner/extraction-progress', $payload))
            ->asJson()
            ->connectTimeout(5)
            ->timeout($config['timeout'])
            ->post($config['base_url'] . '/api/partner/extraction-progress', $payload);

        if (!$response->successful()) {
            if ($this->trackingObservabilityEnabled()) {
                Log::warning('tracking.progress.read.failed', [
                    'document_id' => (int) $doc->id,
                    'status' => (string) $doc->status,
                    'request_id' => $this->redactedRequestId($doc->partner_request_id),
                    'user_hash' => $this->userHash((string) $doc->user_email),
                    'upstream_status' => $response->status(),
                ]);
            }

            return response()->json(array_merge($base, [
                'phase' => $doc->status === 'complete' ? 'completed' : ($doc->status === 'failed' ? 'failed' : 'processing'),
                'progress_percent' => $doc->status === 'complete' ? 100 : 0,
                'partner_tracking' => null,
                'tracking_error' => 'Unable to load partner tracking progress.',
            ]), 502);
        }

        $tracking = (array) $response->json();

        if ($this->trackingObservabilityEnabled()) {
            Log::info('tracking.progress.read.success', [
                'document_id' => (int) $doc->id,
                'status' => (string) $doc->status,
                'request_id' => $this->redactedRequestId($doc->partner_request_id),
                'user_hash' => $this->userHash((string) $doc->user_email),
                'phase' => (string) ($tracking['phase'] ?? 'processing'),
                'progress_percent' => (int) ($tracking['progress_percent'] ?? 0),
            ]);
        }

        return response()->json(array_merge($base, [
            'phase' => (string) ($tracking['phase'] ?? 'processing'),
            'progress_percent' => (int) ($tracking['progress_percent'] ?? 0),
            'partner_tracking' => $tracking,
            'failed_reason' => $doc->failed_reason ?: ($tracking['failed_reason'] ?? null),
        ]));
    }
}
