<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
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

        // If email is changing, ensure no other user already owns the new email.
        if (strtolower($data['email']) !== strtolower($currentEmail)) {
            $exists = User::where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json(['error' => 'That email is already taken by another account.'], 422);
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
}
