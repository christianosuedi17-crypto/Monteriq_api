<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request, PaystackService $ps, SupabaseClient $sb)
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        if (!$ps->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Paystack webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) return response()->json(['message' => 'Invalid JSON'], 400);

        $eventType = $event['event'] ?? 'unknown';
        $data = $event['data'] ?? [];
        $reference = $data['reference'] ?? null;

        try {
            $sb->insert('webhook_events', [
                'provider'   => 'paystack',
                'event_type' => $eventType,
                'reference'  => $reference,
                'payload'    => $event,
                'signature'  => $signature,
                'processed'  => false,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Already processed']);
        }

        try {
            match ($eventType) {
                'charge.success'    => $this->handleChargeSuccess($data, $sb),
                'transfer.success'  => $this->handleTransferStatus($data, $sb, 'success'),
                'transfer.failed'   => $this->handleTransferStatus($data, $sb, 'failed'),
                'transfer.reversed' => $this->handleTransferStatus($data, $sb, 'reversed'),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Webhook handler error: ' . $e->getMessage(), $data);
        }

        if ($reference) {
            try {
                $sb->update('webhook_events',
                    ['provider' => 'paystack', 'reference' => $reference],
                    ['processed' => true]
                );
            } catch (\Throwable $e) {}
        }

        return response()->json(['message' => 'ok']);
    }

    protected function handleChargeSuccess(array $data, SupabaseClient $sb): void
    {
        $reference = $data['reference'] ?? null;
        $amount = ($data['amount'] ?? 0) / 100;
        $customerCode = $data['customer']['customer_code'] ?? null;

        if (!$reference || !$customerCode || $amount <= 0) return;

        $profile = $sb->restOne('profiles', ['paystack_customer_code' => 'eq.' . $customerCode]);
        if (!$profile) return;
        $userId = $profile['id'];

        $sb->insert('deposits', [
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'NGN',
            'status' => 'success',
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'provider_response' => $data,
            'channel' => $data['channel'] ?? 'dedicated_nuban',
            'sender_name' => $data['authorization']['sender_name'] ?? null,
            'sender_bank' => $data['authorization']['sender_bank'] ?? null,
            'paid_at' => now()->toIso8601String(),
        ]);

        $wallet = $sb->restOne('wallets', ['user_id' => 'eq.' . $userId]);
        if (!$wallet) return;

        $before = (float) ($wallet['balance_ngn'] ?? 0);
        $after = $before + $amount;

        $sb->update('wallets', ['user_id' => $userId], ['balance_ngn' => $after]);

        $sb->insert('transactions', [
            'user_id' => $userId,
            'type' => 'credit',
            'category' => 'deposit',
            'amount' => $amount,
            'fee' => 0,
            'total' => $amount,
            'currency' => 'NGN',
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'MON-' . strtoupper(Str::random(12)),
            'provider_ref' => $reference,
            'status' => 'successful',
            'description' => 'Virtual account deposit',
            'counterparty' => $data['authorization']['sender_name'] ?? null,
            'metadata' => ['source' => 'paystack_webhook'],
        ]);

        \App\Services\NotificationService::send(
            $userId,
            'deposit_success',
            'Deposit received',
                        "NGN " . number_format($amount, 2) . " credited to your wallet.",
            null,
            ['amount' => $amount, 'reference' => $reference]
        );
    }

    protected function handleTransferStatus(array $data, SupabaseClient $sb, string $newStatus): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        $wd = $sb->restOne('withdrawals', ['reference' => 'eq.' . $reference]);
        if (!$wd) return;

        $updates = ['status' => $newStatus, 'provider_response' => $data];

        if ($newStatus === 'failed' || $newStatus === 'reversed') {
            // Refund the user
            $userId = $wd['user_id'];
            $wallet = $sb->restOne('wallets', ['user_id' => 'eq.' . $userId]);
            if ($wallet) {
                $before = (float) $wallet['balance_ngn'];
                $refundAmount = (float) $wd['total'];
                $after = $before + $refundAmount;

                $sb->update('wallets', ['user_id' => $userId], ['balance_ngn' => $after]);

                $sb->insert('transactions', [
                    'user_id' => $userId,
                    'type' => 'credit',
                    'category' => 'refund',
                    'amount' => $refundAmount,
                    'fee' => 0,
                    'total' => $refundAmount,
                    'currency' => 'NGN',
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference' => 'MON-' . strtoupper(Str::random(12)),
                    'provider_ref' => $reference,
                    'status' => 'successful',
                    'description' => 'Withdrawal reversed Ã¢â‚¬â€ refund',
                    'withdrawal_id' => $wd['id'],
                ]);
            }
            $updates['failure_reason'] = $data['reason'] ?? $data['gateway_response'] ?? 'Transfer failed';
        }

        // Also mark the corresponding debit transaction status
        $tx = $sb->restOne('transactions', ['withdrawal_id' => 'eq.' . $wd['id']]);
        if ($tx) {
            $sb->update('transactions', ['id' => $tx['id']], [
                'status' => $newStatus === 'success' ? 'successful' : 'failed',
            ]);
        }

        // ── Send notification to user about the outcome
        try {
            $userId = $wd['user_id'];
            $amount = (float) $wd['amount'];
            $name = $wd['account_name'] ?? '';
            $bank = $wd['bank_name'] ?? '';

            if ($newStatus === 'failed' || $newStatus === 'reversed') {
                \App\Services\NotificationService::send(
                    $userId,
                    'withdrawal_failed',
                    'Withdrawal failed — refunded',
                                        'NGN ' . number_format($amount, 2) . ' to ' . $name
                        . ' was refunded to your wallet.',
                    null,
                    [
                        'amount' => $amount,
                        'reference' => $reference,
                        'reason' => $updates['failure_reason'] ?? null,
                    ]
                );
            } elseif ($newStatus === 'success') {
                \App\Services\NotificationService::send(
                    $userId,
                    'withdrawal_success',
                    'Withdrawal confirmed',
                                        'NGN ' . number_format($amount, 2) . ' to ' . $name
                        . ' (' . $bank . ') has settled.',
                    null,
                    [
                        'amount' => $amount,
                        'reference' => $reference,
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Withdrawal notification failed: ' . $e->getMessage());
        }

        $sb->update('withdrawals', ['id' => $wd['id']], $updates);
    }
}