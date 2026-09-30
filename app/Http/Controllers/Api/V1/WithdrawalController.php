<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WithdrawalController extends Controller
{
    public function create(Request $request, SupabaseClient $sb, PaystackService $ps)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        $data = $request->validate([
            'amount'             => 'required|numeric|min:100',
            'bank_code'          => 'required|string',
            'bank_name'          => 'required|string',
            'account_number'     => 'required|digits:10',
            'account_name'       => 'required|string',
            'pin'                => 'nullable|digits:4',
            'biometric_verified' => 'nullable|boolean',
        ]);

        $pin = (string) ($data['pin'] ?? '');
        $useBiometric = !empty($data['biometric_verified']);
        $usePin = $pin !== '';

        if (!$useBiometric && !$usePin) {
            return response()->json(['message' => 'PIN or biometric required'], 422);
        }

        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);
        $wallet  = $sb->restOne('wallets',  ['user_id' => 'eq.' . $uid]);
        $config  = $sb->restOne('app_config', ['id' => 'eq.1']);

        if (!$profile || !$wallet) {
            return response()->json(['message' => 'Account error'], 500);
        }

        $status = (string) ($profile['kyc_status'] ?? 'not_started');
        if (!in_array($status, ['verified', 'approved'], true)) {
            return response()->json(['message' => 'Complete KYC before withdrawing.'], 403);
        }

        if ($usePin) {
            $lockedUntil = $profile['pin_locked_until'] ?? null;
            if ($lockedUntil && strtotime($lockedUntil) > time()) {
                $remaining = max(1, (int) ceil((strtotime($lockedUntil) - time()) / 60));
                return response()->json(['message' => "PIN locked. Try again in {$remaining} min."], 423);
            }

            if (empty($profile['transaction_pin_hash']) ||
                !Hash::check($pin, $profile['transaction_pin_hash'])) {
                $attempts = (int) ($profile['pin_failed_attempts'] ?? 0) + 1;
                $updates = ['pin_failed_attempts' => $attempts];
                if ($attempts >= 5) {
                    $updates['pin_locked_until'] = now()->addMinutes(30)->toIso8601String();
                }
                $sb->update('profiles', ['id' => $uid], $updates);
                return response()->json(['message' => 'Incorrect PIN'], 401);
            }

            $sb->update('profiles', ['id' => $uid], [
                'pin_failed_attempts' => 0,
                'pin_locked_until'    => null,
            ]);
        }

        $amount = (float) $data['amount'];
        $flat = (float) ($config['withdrawal_fee_flat'] ?? 50);
        $percent = (float) ($config['withdrawal_fee_percent'] ?? 5);
        $percentFee = $amount * ($percent / 100);
        $fee = max($flat, $percentFee);
        $total = $amount + $fee;

        $tier = (int) ($profile['kyc_level'] ?? 0);
        $dailyLimits = [0 => 0, 1 => 50000, 2 => 200000, 3 => 5000000];
        $dailyLimit = $dailyLimits[$tier] ?? 0;
        if ($total > $dailyLimit) {
            return response()->json([
                'message' => "Exceeds daily limit (NGN" . number_format($dailyLimit) . ")"
            ], 422);
        }

        $balBefore = (float) ($wallet['balance_ngn'] ?? 0);
        if ($balBefore < $total) {
            return response()->json([
                'message' => 'Insufficient balance. Required: NGN' . number_format($total, 2)
            ], 422);
        }

        $existing = $sb->restOne('bank_accounts', [
            'user_id'        => 'eq.' . $uid,
            'bank_code'      => 'eq.' . $data['bank_code'],
            'account_number' => 'eq.' . $data['account_number'],
        ]);
        if ($existing) {
            $beneficiaryId = $existing['id'];
        } else {
            $created = $sb->insert('bank_accounts', [
                'user_id'        => $uid,
                'bank_code'      => $data['bank_code'],
                'bank_name'      => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name'   => $data['account_name'],
            ]);
            $beneficiaryId = $created[0]['id'] ?? null;
        }

        $recipient = $ps->createTransferRecipient(
            $data['account_name'],
            $data['account_number'],
            $data['bank_code']
        );
        if (!$recipient || empty($recipient['recipient_code'])) {
            return response()->json(['message' => 'Could not create transfer recipient.'], 500);
        }

        $sessionId = $request->attributes->get('supabase_user')['session_id'] ?? null;
        $deviceInfo = [
            'user_agent' => $request->header('User-Agent'),
            'ip'         => $request->ip(),
            'platform'   => $request->header('X-Platform'),
        ];

        $reference = 'WD-' . strtoupper(Str::random(12));
        $balAfter = $balBefore - $total;

        $w = $sb->insert('withdrawals', [
            'user_id'         => $uid,
            'amount'          => $amount,
            'fee'             => $fee,
            'total'           => $total,
            'currency'        => 'NGN',
            'bank_account_id' => $beneficiaryId,
            'bank_code'       => $data['bank_code'],
            'bank_name'       => $data['bank_name'],
            'account_number'  => $data['account_number'],
            'account_name'    => $data['account_name'],
            'status'          => 'processing',
            'provider'        => 'paystack',
            'reference'       => $reference,
            'balance_before'  => $balBefore,
            'balance_after'   => $balAfter,
            'session_id'      => $sessionId,
            'device_info'     => $deviceInfo,
        ]);
        $withdrawal = $w[0] ?? null;

        $sb->update('wallets', ['user_id' => $uid], ['balance_ngn' => $balAfter]);

        $sb->insert('transactions', [
            'user_id'         => $uid,
            'type'            => 'debit',
            'category'        => 'withdrawal',
            'amount'          => $amount,
            'fee'             => $fee,
            'total'           => $total,
            'currency'        => 'NGN',
            'balance_before'  => $balBefore,
            'balance_after'   => $balAfter,
            'reference'       => 'MON-' . strtoupper(Str::random(12)),
            'provider_ref'    => $reference,
            'status'          => 'processing',
            'description'     => 'Withdrawal to ' . $data['account_name'],
            'counterparty'    => $data['bank_name'] . ' - ' . $data['account_number'],
            'withdrawal_id'   => $withdrawal['id'] ?? null,
        ]);

        $transfer = $ps->initiateTransfer(
            $recipient['recipient_code'],
            (int) round($amount * 100),
            $reference,
            'Monteriq withdrawal'
        );

        if (!$transfer || empty($transfer['transfer_code'])) {
            $sb->update('wallets', ['user_id' => $uid], ['balance_ngn' => $balBefore]);
            $sb->update('withdrawals', ['id' => $withdrawal['id']], [
                'status'         => 'failed',
                'failure_reason' => 'Transfer initiation failed',
            ]);
            return response()->json(['message' => 'Transfer failed. Balance refunded.'], 500);
        }

        $sb->update('withdrawals', ['id' => $withdrawal['id']], [
            'provider_transfer_code' => $transfer['transfer_code'],
            'provider_response'      => $transfer,
            'status'                 => 'pending',
        ]);

        return response()->json([
            'message'       => 'Withdrawal initiated.',
            'reference'     => $reference,
            'status'        => 'pending',
            'amount'        => $amount,
            'fee'           => $fee,
            'total'         => $total,
            'balance_after' => $balAfter,
        ]);
    }

    public function index(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $rows = $sb->rest('withdrawals', [
            'user_id' => 'eq.' . $uid,
            'order'   => 'created_at.desc',
            'limit'   => 50,
        ]);
        return response()->json(['withdrawals' => $rows]);
    }

    public function show(Request $request, SupabaseClient $sb, string $id)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $row = $sb->restOne('withdrawals', [
            'id'      => 'eq.' . $id,
            'user_id' => 'eq.' . $uid,
        ]);
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['withdrawal' => $row]);
    }
}