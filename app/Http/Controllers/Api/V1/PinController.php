<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    public function set(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $data = $request->validate(['pin' => 'required|digits:4']);

        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);
        if (!empty($profile['transaction_pin_hash'])) {
            return response()->json(['message' => 'PIN already set'], 422);
        }

        $sb->update('profiles', ['id' => $uid], [
            'transaction_pin_hash'   => Hash::make($data['pin']),
            'transaction_pin_set_at' => now()->toIso8601String(),
        ]);

        return response()->json(['message' => 'PIN set successfully']);
    }

    public function verify(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $data = $request->validate(['pin' => 'required|digits:4']);

        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);
        if (empty($profile['transaction_pin_hash'])) {
            return response()->json(['message' => 'No PIN set'], 422);
        }

        // Fast path: only read, don't write on success
        if (Hash::check($data['pin'], $profile['transaction_pin_hash'])) {
            return response()->json(['ok' => true, 'message' => 'PIN verified']);
        }

        // Failure path: update attempt counter
        $attempts = (int) ($profile['pin_failed_attempts'] ?? 0) + 1;
        $updates = ['pin_failed_attempts' => $attempts];
        if ($attempts >= 5) {
            $updates['pin_locked_until'] = now()->addMinutes(30)->toIso8601String();
        }
        $sb->update('profiles', ['id' => $uid], $updates);

        return response()->json(['message' => 'Incorrect PIN'], 401);
    }
}