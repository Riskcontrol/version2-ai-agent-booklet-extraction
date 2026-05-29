<?php

namespace App\Http\Middleware;

use App\Models\PartnerRequestIdempotency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackIncomingIdempotency
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header(self::IDEMPOTENCY_HEADER);
        $partnerName = $request->header('X-Partner-Name', 'peldarg');

        // For non-idempotent requests, skip tracking
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        // If no idempotency key, generate one
        if (!$idempotencyKey) {
            $idempotencyKey = (string) \Illuminate\Support\Str::uuid();
        }

        $timestamp = $request->header('X-Partner-Timestamp', now()->toIso8601String());
        $nonce = $request->header('X-Partner-Nonce', '');
        $body = (string) $request->getContent();

        // Check if we've seen this idempotency key before
        $existing = PartnerRequestIdempotency::where('idempotency_key', $idempotencyKey)
            ->where('partner_name', $partnerName)
            ->first();

        if ($existing && $existing->response_status) {
            // Return cached response
            return response()->json(
                json_decode($existing->response_body, true) ?? ['cached' => true],
                $existing->response_status
            );
        }

        // Record the incoming request
        if (!$existing) {
            $existing = PartnerRequestIdempotency::recordRequest(
                $idempotencyKey,
                $partnerName,
                $request->getMethod(),
                $request->getPathInfo(),
                $body,
                'hmac-sha256',
                $timestamp,
                $nonce
            );
        }

        // Store idempotency record in request
        $request->merge(['_idempotency' => $existing]);

        // Process request and capture response
        $response = $next($request);

        // Store response for future replays
        if ($existing && is_numeric($response->getStatusCode())) {
            $existing->recordResponse($response->getStatusCode(), (string) $response->getContent());
        }

        return $response;
    }
}
