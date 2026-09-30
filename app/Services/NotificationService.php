<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a notification (writes to DB + pushes to device via FCM).
     *
     * @param  string $userId  Supabase profile id
     * @param  string $type    notification type (deposit_success, withdrawal_failed, security_alert, kyc_approved, promo, system, welcome, ...)
     * @param  string $title
     * @param  string $body
     * @param  string|null $deepLink  e.g. "/transactions/abc"
     * @param  array $metadata
     */
    public static function send(
        string $userId,
        string $type,
        string $title,
        string $body = '',
        ?string $deepLink = null,
        array $metadata = []
    ): void {
        // 1. Persist the in-app notification (never throws)
        try {
            $sb = app(SupabaseClient::class);
            $sb->insert('notifications', [
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'body'      => $body,
                'deep_link' => $deepLink,
                'metadata'  => $metadata,
                'read'      => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationService::send DB insert failed: ' . $e->getMessage());
        }

        // 2. Push to device via FCM (never throws — a broken push must never
        //    break a deposit / withdrawal / KYC flow).
        try {
            $data = array_filter([
                'type'      => $type,
                'deep_link' => $deepLink,
            ], fn ($v) => $v !== null && $v !== '');

            if (!empty($metadata)) {
                $data['metadata'] = $metadata;
            }

            $category = self::categoryFor($type);

            app(FcmService::class)->sendToUser(
                $userId,
                $title,
                $body,
                $data,
                $category
            );
        } catch (\Throwable $e) {
            Log::warning('NotificationService::send FCM failed: ' . $e->getMessage());
        }
    }

    /**
     * Map notification type -> FCM preference category.
     * Returns null for types governed only by the master push_enabled flag.
     */
    protected static function categoryFor(string $type): ?string
    {
        return match (true) {
            str_starts_with($type, 'deposit_'),
            str_starts_with($type, 'withdrawal_'),
            $type === 'transaction',
            $type === 'transfer'                    => 'transactions',

            str_starts_with($type, 'kyc_')          => 'kyc',

            str_starts_with($type, 'security_'),
            $type === 'login_alert',
            $type === 'pin_changed',
            $type === 'password_changed'            => 'security',

            str_starts_with($type, 'promo'),
            $type === 'marketing'                   => 'promotions',

            default                                 => null,
        };
    }
}