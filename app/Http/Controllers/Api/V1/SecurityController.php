<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    /** Update biometric + auto-lock preferences */
    public function update(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $data = $request->validate([
            'biometric_unlock'   => 'sometimes|boolean',
            'biometric_transfer' => 'sometimes|boolean',
            'auto_lock_seconds'  => 'sometimes|integer|min:0|max:3600',
        ]);

        if (empty($data)) {
            return response()->json(['message' => 'Nothing to update'], 422);
        }

        $rows = $sb->update('profiles', ['id' => $uid], $data);
        return response()->json(['profile' => $rows[0] ?? null]);
    }

    /** Verify current PIN then set a new one */
    public function changePin(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        $data = $request->validate([
            'current_pin' => 'required|digits:4',
            'new_pin'     => 'required|digits:4|different:current_pin',
        ]);

        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);
        if (empty($profile['transaction_pin_hash'])) {
            return response()->json(['message' => 'No PIN set'], 422);
        }
        if (!Hash::check($data['current_pin'], $profile['transaction_pin_hash'])) {
            return response()->json(['message' => 'Current PIN is incorrect'], 401);
        }

        $sb->update('profiles', ['id' => $uid], [
            'transaction_pin_hash'   => Hash::make($data['new_pin']),
            'transaction_pin_set_at' => now()->toIso8601String(),
            'pin_failed_attempts'    => 0,
            'pin_locked_until'       => null,
        ]);

        return response()->json(['message' => 'PIN changed successfully']);
    }
}