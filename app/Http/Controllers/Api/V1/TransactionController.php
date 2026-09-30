<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        $page = max(1, (int) $request->query('page', 1));
        $per  = min(50, max(5, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $per;

        $type   = $request->query('type');
        $search = trim((string) $request->query('search', ''));

        $query = [
            'user_id' => 'eq.' . $uid,
            'order'   => 'created_at.desc',
            'limit'   => $per,
            'offset'  => $offset,
        ];

        if ($type === 'credit' || $type === 'debit') {
            $query['type'] = 'eq.' . $type;
        }

        $rows = [];
        if ($search !== '') {
            $pattern = '*' . $search . '*';

            $byDesc = $sb->rest('transactions', array_merge($query, [
                'description' => 'ilike.' . $pattern,
            ]));

            $byRef = $sb->rest('transactions', array_merge($query, [
                'reference' => 'ilike.' . $pattern,
            ]));

            $seen = [];
            foreach (array_merge($byDesc, $byRef) as $r) {
                if (!isset($seen[$r['id']])) {
                    $seen[$r['id']] = true;
                    $rows[] = $r;
                }
            }
            usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        } else {
            $rows = $sb->rest('transactions', $query);
        }

        $allCredits = $sb->rest('transactions', [
            'user_id' => 'eq.' . $uid,
            'type' => 'eq.credit',
            'select' => 'amount',
        ]);
        $allDebits = $sb->rest('transactions', [
            'user_id' => 'eq.' . $uid,
            'type' => 'eq.debit',
            'select' => 'amount',
        ]);

        $totalCredit = array_sum(array_map(fn($r) => (float) ($r['amount'] ?? 0), $allCredits));
        $totalDebit  = array_sum(array_map(fn($r) => (float) ($r['amount'] ?? 0), $allDebits));

        return response()->json([
            'transactions' => $rows,
            'page'         => $page,
            'per_page'     => $per,
            'has_more'     => count($rows) >= $per,
            'total_credit' => round($totalCredit, 2),
            'total_debit'  => round($totalDebit, 2),
        ]);
    }

    public function show(Request $request, SupabaseClient $sb, string $id)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $row = $sb->restOne('transactions', [
            'id'      => 'eq.' . $id,
            'user_id' => 'eq.' . $uid,
        ]);
        if (!$row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['transaction' => $row]);
    }
}