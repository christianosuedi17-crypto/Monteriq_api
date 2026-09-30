<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $limit = min(100, max(10, (int) $request->query('limit', 50)));

        $rows = $sb->rest('notifications', [
            'user_id' => 'eq.' . $uid,
            'order'   => 'created_at.desc',
            'limit'   => $limit,
        ]);

        $unread = $sb->rest('notifications', [
            'user_id' => 'eq.' . $uid,
            'read'    => 'eq.false',
            'select'  => 'id',
        ]);

        return response()->json([
            'notifications' => $rows,
            'unread_count'  => count($unread),
        ]);
    }

    public function markRead(Request $request, SupabaseClient $sb, string $id)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $rows = $sb->update('notifications',
            ['id' => $id, 'user_id' => $uid],
            ['read' => true, 'read_at' => now()->toIso8601String()]
        );
        return response()->json(['notification' => $rows[0] ?? null]);
    }

    public function markAllRead(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        // Supabase REST doesn't support bulk update by filter easily — fetch then update
        $unread = $sb->rest('notifications', [
            'user_id' => 'eq.' . $uid,
            'read'    => 'eq.false',
            'select'  => 'id',
        ]);

        foreach ($unread as $n) {
            $sb->update('notifications', ['id' => $n['id']], [
                'read' => true,
                'read_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json(['message' => 'All marked as read', 'count' => count($unread)]);
    }

    public function destroy(Request $request, SupabaseClient $sb, string $id)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $row = $sb->restOne('notifications', ['id' => 'eq.' . $id, 'user_id' => 'eq.' . $uid]);
        if (!$row) return response()->json(['message' => 'Not found'], 404);

        $sb->http->delete("/rest/v1/notifications", ['query' => ['id' => 'eq.' . $id]]);
        return response()->json(['message' => 'Deleted']);
    }
}