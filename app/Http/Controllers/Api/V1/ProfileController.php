<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        $data = $request->validate([
            'full_name'            => 'sometimes|string|max:120',
            'phone'                => 'sometimes|string|max:20',
            'theme_preference'     => 'sometimes|string|in:light,dark,system',
            'biometric_unlock'     => 'sometimes|boolean',
            'biometric_transfer'   => 'sometimes|boolean',
            'auto_lock_seconds'    => 'sometimes|integer|min:0|max:3600',
        ]);

        if (empty($data)) {
            return response()->json(['message' => 'Nothing to update'], 422);
        }

        $rows = $sb->update('profiles', ['id' => $uid], $data);
        return response()->json(['profile' => $rows[0] ?? null]);
    }

    public function uploadAvatar(Request $request, SupabaseClient $sb)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $uid  = $request->attributes->get('supabase_user')['id'];
        $file = $request->file('avatar');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = "{$uid}/profile.{$ext}";

        $sb->storageUpload(
            'avatars',
            $path,
            (string) file_get_contents($file->getRealPath()),
            (string) $file->getMimeType()
        );

        $url = $sb->publicUrl('avatars', $path) . '?v=' . time();

        $rows = $sb->update('profiles', ['id' => $uid], [
            'avatar_url'        => $url,
            'avatar_updated_at' => now()->toIso8601String(),
        ]);

        return response()->json(['avatar_url' => $url, 'profile' => $rows[0] ?? null]);
    }
}