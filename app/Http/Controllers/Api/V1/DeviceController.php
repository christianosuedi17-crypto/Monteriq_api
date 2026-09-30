<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    protected SupabaseClient $sb;

    public function __construct(SupabaseClient $sb)
    {
        $this->sb = $sb;
    }

    /**
     * Register (or refresh) an FCM token for the authenticated user.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'token'        => 'required|string|max:4096',
            'platform'     => 'nullable|string|max:32',
            'device_name'  => 'nullable|string|max:128',
            'device_model' => 'nullable|string|max:128',
            'app_version'  => 'nullable|string|max:32',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $token = trim($data['token']);
        if ($token === '') {
            return response()->json(['error' => 'Empty token'], 422);
        }

        try {
            $existing = $this->sb->restOne('device_tokens', [
                'user_id' => 'eq.' . $userId,
                'token'   => 'eq.' . $token,
            ]);

            $now = gmdate('Y-m-d\TH:i:s\Z');

            if ($existing) {
                $this->sb->update(
                    'device_tokens',
                    ['id' => $existing['id']],
                    [
                        'last_seen_at' => $now,
                        'platform'     => $data['platform']     ?? ($existing['platform'] ?? 'android'),
                        'device_name'  => $data['device_name']  ?? ($existing['device_name'] ?? null),
                        'device_model' => $data['device_model'] ?? ($existing['device_model'] ?? null),
                        'app_version'  => $data['app_version']  ?? ($existing['app_version'] ?? null),
                    ]
                );
                $id = $existing['id'];
            } else {
                $rows = $this->sb->insert('device_tokens', [
                    'user_id'      => $userId,
                    'token'        => $token,
                    'platform'     => $data['platform']     ?? 'android',
                    'device_name'  => $data['device_name']  ?? null,
                    'device_model' => $data['device_model'] ?? null,
                    'app_version'  => $data['app_version']  ?? null,
                    'last_seen_at' => $now,
                    'created_at'   => $now,
                ]);
                $id = $rows[0]['id'] ?? null;
            }

            return response()->json([
                'ok'    => true,
                'id'    => $id,
                'token' => substr($token, 0, 12) . '...',
            ]);
        } catch (\Throwable $e) {
            Log::error('DeviceController::register failed: ' . $e->getMessage());
            return response()->json([
                'error'   => 'register_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unregister a token (used on logout).
     */
    public function unregister(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:4096',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        try {
            $this->sb->delete('device_tokens', [
                'user_id' => $userId,
                'token'   => $data['token'],
            ]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('DeviceController::unregister failed: ' . $e->getMessage());
            return response()->json(['error' => 'unregister_failed'], 500);
        }
    }

    /**
     * Update push preferences on the user's profile.
     */
    public function preferences(Request $request)
    {
        $data = $request->validate([
            'push_enabled'      => 'sometimes|boolean',
            'push_transactions' => 'sometimes|boolean',
            'push_security'     => 'sometimes|boolean',
            'push_kyc'          => 'sometimes|boolean',
            'push_promotions'   => 'sometimes|boolean',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        try {
            $this->sb->update('profiles', ['id' => $userId], $data);

            return response()->json(['ok' => true] + $data);
        } catch (\Throwable $e) {
            Log::error('DeviceController::preferences failed: ' . $e->getMessage());
            return response()->json(['error' => 'preferences_failed'], 500);
        }
    }

    /**
     * Resolve the authenticated user id from the request, matching what
     * App\Http\Middleware\SupabaseAuth sets: attribute 'supabase_user' => ['id' => ...]
     */
    protected function resolveUserId(Request $request): ?string
    {
        $user = $request->attributes->get('supabase_user');

        if (is_array($user) && !empty($user['id'])) {
            return $user['id'];
        }
        if (is_object($user) && !empty($user->id)) {
            return $user->id;
        }

        $fallback = $request->attributes->get('supabase_user_id')
                 ?? $request->attributes->get('user_id');
        if ($fallback) {
            return $fallback;
        }

        return optional($request->user())->id;
    }
}