<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class BeneficiaryController extends Controller
{
    public function index(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $rows = $sb->rest('bank_accounts', [
            'user_id' => 'eq.' . $uid,
            'order'   => 'created_at.desc',
            'limit'   => 50,
        ]);
        return response()->json(['beneficiaries' => $rows]);
    }

    public function destroy(Request $request, SupabaseClient $sb, string $id)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $row = $sb->restOne('bank_accounts', ['id' => 'eq.' . $id, 'user_id' => 'eq.' . $uid]);
        if (!$row) return response()->json(['message' => 'Not found'], 404);

        // Soft delete via update is fine too; here we hard delete
        $sb->http->delete("/rest/v1/bank_accounts", ['query' => ['id' => 'eq.' . $id]]);
        return response()->json(['message' => 'Deleted']);
    }
}