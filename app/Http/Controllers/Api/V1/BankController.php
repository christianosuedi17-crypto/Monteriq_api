<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BankController extends Controller
{
    public function index(PaystackService $ps)
    {
        $banks = Cache::remember('ng_banks', now()->addDay(), fn () => $ps->listBanks());
        return response()->json(['banks' => $banks]);
    }

    public function resolve(Request $request, PaystackService $ps)
    {
        $data = $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code'      => 'required|string',
        ]);

        $resolved = $ps->resolveAccount($data['account_number'], $data['bank_code']);
        if (!$resolved || empty($resolved['account_name'])) {
            return response()->json(['message' => 'Could not resolve account. Check the number.'], 422);
        }

        return response()->json([
            'account_number' => $resolved['account_number'] ?? $data['account_number'],
            'account_name'   => $resolved['account_name'],
        ]);
    }
}