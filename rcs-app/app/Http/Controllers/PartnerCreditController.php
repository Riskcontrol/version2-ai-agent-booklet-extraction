<?php

namespace App\Http\Controllers;

use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerCreditController extends Controller
{
    private function partnerConfig(): array
    {
        return [
            'base_url' => rtrim((string) config('services.partner.base_url', ''), '/'),
            'token' => (string) config('services.partner.token', ''),
            'timeout' => (int) config('services.partner.timeout', 15),
        ];
    }

    private function authenticatedUserEmail(Request $request): string
    {
        $userEmail = (string) $request->session()->get('user_email', '');
        if ($userEmail === '') {
            throw ValidationException::withMessages(['user' => 'Authenticated user email is missing.']);
        }

        return $userEmail;
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

        $sig = SignatureService::generateSignature(
            $secret,
            strtoupper($method),
            $path,
            $body
        );

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

    public function summary(Request $request)
    {
        $userEmail = $this->authenticatedUserEmail($request);

        $config = $this->partnerConfig();
        $baseUrl = $config['base_url'];
        $token = $config['token'];
        if ($baseUrl === '' || $token === '') {
            return response()->json(['error' => 'Partner billing integration is not configured.'], 503);
        }

        $payload = [
            'user_email' => $userEmail,
        ];

        $response = Http::withHeaders($this->signedPartnerHeaders('POST', '/api/partner/credit-summary', $payload))
            ->asJson()
            ->connectTimeout(5)
            ->timeout($config['timeout'])
            ->post($baseUrl . '/api/partner/credit-summary', $payload);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Unable to load credit summary from Google Cloud billing authority.',
                'status' => $response->status(),
            ], 502);
        }

        return response()->json($response->json());
    }

    public function paymentHistory(Request $request)
    {
        $userEmail = $this->authenticatedUserEmail($request);

        $config = $this->partnerConfig();
        $baseUrl = $config['base_url'];
        $token = $config['token'];
        if ($baseUrl === '' || $token === '') {
            return response()->json(['error' => 'Partner billing integration is not configured.'], 503);
        }

        $payload = array_filter([
            'user_email' => $userEmail,
            'year' => $request->query('year'),
            'month' => $request->query('month'),
        ], static fn ($value) => $value !== null && $value !== '');

        $validator = validator($payload, [
            'user_email' => 'required|email',
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid payment history filter.',
                'details' => $validator->errors(),
            ], 422);
        }

        $response = Http::withHeaders($this->signedPartnerHeaders('POST', '/api/partner/payment-history', $payload))
            ->asJson()
            ->connectTimeout(5)
            ->timeout($config['timeout'])
            ->post($baseUrl . '/api/partner/payment-history', $payload);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Unable to load payment history from Google Cloud billing authority.',
                'status' => $response->status(),
            ], 502);
        }

        return response()->json($response->json());
    }

    public function paystackInitialize(Request $request)
    {
        $userEmail = $this->authenticatedUserEmail($request);
        $config = $this->partnerConfig();
        if ($config['base_url'] === '' || $config['token'] === '') {
            return response()->json(['error' => 'Partner billing integration is not configured.'], 503);
        }

        $data = $request->validate([
            'requested_credits' => 'required|integer|min:1',
        ]);

        $payload = [
            'user_email' => $userEmail,
            'requested_credits' => (int) $data['requested_credits'],
            'callback_url' => url('/top-up'),
        ];

        $response = Http::withHeaders($this->signedPartnerHeaders('POST', '/api/partner/paystack/initialize', $payload))
            ->asJson()
            ->connectTimeout(5)
            ->timeout($config['timeout'])
            ->post($config['base_url'] . '/api/partner/paystack/initialize', $payload);

        if (!$response->successful()) {
            $error = $response->json('message') ?: $response->json('error') ?: 'Unable to initialize Paystack checkout.';
            return response()->json([
                'error' => (string) $error,
                'status' => $response->status(),
            ], 502);
        }

        return response()->json($response->json());
    }

    public function paystackVerify(Request $request)
    {
        $userEmail = $this->authenticatedUserEmail($request);
        $config = $this->partnerConfig();
        if ($config['base_url'] === '' || $config['token'] === '') {
            return response()->json(['error' => 'Partner billing integration is not configured.'], 503);
        }

        $data = $request->validate([
            'reference' => 'required|string|max:255',
        ]);

        $payload = [
            'user_email' => $userEmail,
            'reference' => (string) $data['reference'],
        ];

        $response = Http::withHeaders($this->signedPartnerHeaders('POST', '/api/partner/paystack/verify', $payload))
            ->asJson()
            ->connectTimeout(5)
            ->timeout($config['timeout'])
            ->post($config['base_url'] . '/api/partner/paystack/verify', $payload);

        if (!$response->successful()) {
            $error = $response->json('message') ?: $response->json('error') ?: 'Unable to verify Paystack payment.';
            return response()->json([
                'error' => (string) $error,
                'status' => $response->status(),
            ], 502);
        }

        return response()->json($response->json());
    }
}