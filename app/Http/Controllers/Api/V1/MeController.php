<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        return response()->json([
            'profile'       => $sb->restOne('profiles', ['id' => 'eq.' . $uid]),
            'wallet'        => $sb->restOne('wallets', ['user_id' => 'eq.' . $uid]),
            'config'        => $sb->restOne('app_config', ['id' => 'eq.1']),
            'announcements' => $sb->rest('announcements', [
                'is_active' => 'eq.true',
                'order'     => 'priority.desc',
                'limit'     => 5,
            ]),
        ]);
    }
}