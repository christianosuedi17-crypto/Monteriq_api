<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        return response()->json([
            'wallet' => $sb->restOne('wallets', ['user_id' => 'eq.' . $uid]),
        ]);
    }

    public function virtualAccount(Request $request, SupabaseClient $sb, PaystackService $ps)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);

        if (!$profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        if (!empty($profile['dva_account_number'])) {
            return response()->json([
                'account_number' => $profile['dva_account_number'],
                'bank_name'      => $profile['dva_bank_name'],
                'account_name'   => $profile['dva_account_name'],
                'cached'         => true,
            ]);
        }

        $phone = trim((string) ($profile['phone'] ?? ''));
        if (empty($phone)) {
            return response()->json([
                'message' => 'Please add a phone number to your profile before funding.',
            ], 422);
        }

        $customerCode = $profile['paystack_customer_code'] ?? null;

        if (!$customerCode) {
            // Create fresh customer
            $names = explode(' ', trim($profile['full_name'] ?? 'Customer User'));
            $firstName = $names[0] ?? 'Customer';
            $lastName  = $names[1] ?? 'User';

            $customer = $ps->createCustomer($profile['email'], $firstName, $lastName, $phone);
            if (!$customer || empty($customer['customer_code'])) {
                return response()->json(['message' => 'Could not create Paystack customer'], 500);
            }
            $customerCode = $customer['customer_code'];
            $sb->update('profiles', ['id' => $uid], [
                'paystack_customer_code' => $customerCode,
                'paystack_customer_id'   => (string) ($customer['id'] ?? ''),
            ]);
        } else {
            // Customer exists — force phone sync if missing
            $existing = $ps->getCustomer($customerCode);
            if ($existing && empty($existing['phone'])) {
                $ps->updateCustomerPhone($customerCode, $phone);
            }
        }

        // Try to reuse an existing DVA
        $existingDvas = $ps->listDedicatedAccounts($customerCode);
        $dva = null;
        if (!empty($existingDvas) && !empty($existingDvas[0]['account_number'])) {
            $dva = $existingDvas[0];
        } else {
            $dva = $ps->createDedicatedAccount($customerCode, $phone);
        }

        if (!$dva || empty($dva['account_number'])) {
            return response()->json(['message' => 'Could not create virtual account'], 500);
        }

        $bankName = $dva['bank']['name'] ?? 'Paystack';
        $accountName = $dva['account_name'] ?? ($profile['full_name'] . ' / Monteriq');

        $sb->update('profiles', ['id' => $uid], [
            'dva_account_number' => $dva['account_number'],
            'dva_bank_name'      => $bankName,
            'dva_account_name'   => $accountName,
            'dva_bank_code'      => (string) ($dva['bank']['id'] ?? ''),
            'dva_created_at'     => now()->toIso8601String(),
        ]);

        return response()->json([
            'account_number' => $dva['account_number'],
            'bank_name'      => $bankName,
            'account_name'   => $accountName,
            'cached'         => false,
        ]);
    }
}