<?php

namespace App\Http\Middleware;

use App\Models\PartnerTrustedKey;
use App\Services\SignatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidatePartnerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $partnerName = $request->header('X-Partner-Name', '');
        $signature = $request->header('X-Partner-Signature', '');
        $timestamp = $request->header('X-Partner-Timestamp', '');
        $nonce = $request->header('X-Partner-Nonce', '');

        // Validate required headers
        if (!$partnerName || !$signature || !$timestamp || !$nonce) {
            return response()->json(
                ['error' => 'Missing required signature headers'],
                401
            );
        }

        // Get partner trusted key
        $partner = PartnerTrustedKey::findByPartnerName($partnerName);
        if (!$partner) {
            return response()->json(['error' => 'Partner not found or inactive'], 401);
        }

        // Verify signature
        $method = $request->getMethod();
        $path = $request->getPathInfo();
        $body = (string) $request->getContent();

        $result = SignatureService::verifySignature(
            $partner->current_secret_key,
            $method,
            $path,
            $body,
            $signature,
            $timestamp,
            $nonce
        );

        if (!$result['valid']) {
            \Log::warning('Partner signature verification failed', [
                'partner_name' => $partnerName,
                'error' => $result['error'],
                'path' => $path,
            ]);
            return response()->json(['error' => 'Signature verification failed: ' . $result['error']], 401);
        }

        // Attach partner info to request
        $request->merge(['_partner' => $partner]);

        return $next($request);
    }
}
