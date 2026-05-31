<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserAccountController extends Controller
{
    public function show()
    {
        return view('settings', [
            'userEmail' => (string) Session::get('user_email', ''),
            'userName'  => (string) Session::get('user_name', ''),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $currentEmail = (string) Session::get('user_email', '');
        if ($currentEmail === '') {
            return response()->json(['error' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'name'  => 'required|string|min:2|max:120',
            'email' => 'required|email|max:180',
        ]);

        $user = User::where('email', $currentEmail)->first();
        if (!$user) {
            return response()->json(['error' => 'User account not found.'], 404);
        }

        // If email is changing, ensure no other local user already owns the new email.
        $emailChanging = strtolower($data['email']) !== strtolower($currentEmail);
        if ($emailChanging) {
            $exists = User::where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json(['error' => 'That email is already taken by another account.'], 422);
            }

            // Sync the email change to Peldarg so top-ups continue to work.
            $syncError = $this->syncEmailToPeldarg($currentEmail, $data['email']);
            if ($syncError !== null) {
                return response()->json(['error' => 'Email update blocked: ' . $syncError], 422);
            }
        }

        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->save();

        // Keep session in sync.
        Session::put('user_email', $user->email);
        Session::put('user_name', $user->name);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'name'    => $user->name,
            'email'   => $user->email,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $currentEmail = (string) Session::get('user_email', '');
        if ($currentEmail === '') {
            return response()->json(['error' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'current_password'      => 'required|string',
            'new_password'          => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::where('email', $currentEmail)->first();
        if (!$user) {
            return response()->json(['error' => 'User account not found.'], 404);
        }

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['error' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Propagate an email change to the Peldarg billing system.
     * Returns null on success, or an error string on failure.
     */
    private function syncEmailToPeldarg(string $currentEmail, string $newEmail): ?string
    {
        $baseUrl = rtrim((string) config('services.partner.base_url', ''), '/');
        $token   = (string) config('services.partner.token', '');
        $secret  = (string) config('services.partner.signature_secret', '');
        $partner = (string) config('services.partner.partner_name', 'riskcontrol');

        if ($baseUrl === '' || $token === '' || $secret === '') {
            // Peldarg not configured — skip sync silently so settings page still works.
            return null;
        }

        $path    = '/api/partner/user/update-email';
        $payload = ['current_email' => $currentEmail, 'new_email' => $newEmail];
        $body    = (string) json_encode($payload);

        $sig = SignatureService::generateSignature($secret, 'POST', $path, $body);

        try {
            $response = Http::withHeaders([
                'X-Partner-Token'       => $token,
                'X-Partner-Name'        => $partner,
                'X-Partner-Signature'   => $sig['signature'],
                'X-Partner-Timestamp'   => $sig['timestamp'],
                'X-Partner-Nonce'       => $sig['nonce'],
                'X-Signature-Algorithm' => $sig['algorithm'],
                'Idempotency-Key'       => (string) Str::uuid(),
                'Accept'                => 'application/json',
            ])
                ->asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($baseUrl . $path, $payload);

            if ($response->successful()) {
                return null;
            }

            $err = $response->json('message')
                ?? $response->json('error')
                ?? ($response->json('errors.new_email.0') ?? null)
                ?? ($response->json('errors.current_email.0') ?? null)
                ?? 'Billing system rejected the email update.';

            return (string) $err;
        } catch (\Throwable $e) {
            // Network error — allow the local change but log the failure.
            \Illuminate\Support\Facades\Log::warning('Peldarg email sync failed', [
                'current' => $currentEmail,
                'new'     => $newEmail,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
