<?php

namespace App\Http\Controllers;

use App\Models\PartnerCreditSyncEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnerBillingWebhookController extends Controller
{
    public function creditUpdated(Request $request)
    {
        $expectedToken = (string) config('services.partner.token', '');
        $providedToken = (string) $request->header('X-Partner-Token', '');
        $payload = $request->json()->all();
        if (!is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        $event = PartnerCreditSyncEvent::create([
            'event_type' => isset($payload['event_type']) ? (string) $payload['event_type'] : null,
            'user_email' => isset($payload['user_email']) ? (string) $payload['user_email'] : null,
            'credit_balance' => isset($payload['credit_balance']) ? (int) $payload['credit_balance'] : null,
            'credit_cap' => isset($payload['credit_cap']) ? (int) $payload['credit_cap'] : null,
            'reported_status' => isset($payload['status']) ? (string) $payload['status'] : null,
            'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
            'occurred_at' => isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            'received_at' => now(),
            'source_ip' => $request->ip(),
            'auth_valid' => false,
            'processing_status' => 'received',
            'raw_payload' => is_array($payload) ? $payload : null,
        ]);

        $authValid = $expectedToken !== '' && hash_equals($expectedToken, $providedToken);
        $event->auth_valid = $authValid;

        if (!$authValid) {
            $event->processing_status = 'rejected_auth';
            $event->error_message = 'Invalid partner token';
            $event->save();
            abort(401);
        }

        $validator = Validator::make($payload, [
            'event_type' => 'required|string|max:120',
            'user_email' => 'required|email',
            'credit_balance' => 'required|integer|min:0',
            'credit_cap' => 'nullable|integer|min:0',
            'status' => 'nullable|string|max:40',
            'meta' => 'nullable|array',
            'occurred_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            $event->processing_status = 'validation_failed';
            $event->error_message = $validator->errors()->first();
            $event->save();

            return response()->json([
                'ok' => false,
                'error' => 'Invalid sync payload',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $event->event_type = (string) ($data['event_type'] ?? '');
        $event->user_email = (string) ($data['user_email'] ?? '');
        $event->credit_balance = (int) ($data['credit_balance'] ?? 0);
        $event->credit_cap = isset($data['credit_cap']) ? (int) $data['credit_cap'] : null;
        $event->reported_status = isset($data['status']) ? (string) $data['status'] : null;
        $event->meta = isset($data['meta']) ? (array) $data['meta'] : null;
        $event->occurred_at = isset($data['occurred_at']) ? (string) $data['occurred_at'] : null;
        $event->processing_status = 'accepted';
        $event->error_message = null;
        $event->save();

        return response()->json(['ok' => true, 'event_id' => $event->id]);
    }
}
