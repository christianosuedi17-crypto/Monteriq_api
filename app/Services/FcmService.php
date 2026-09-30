<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;

class FcmService
{
    protected ?Client $http = null;
    protected ?string $projectId = null;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->projectId = config('monteriq.fcm.project_id');
        $credsPath = config('monteriq.fcm.credentials_path');

        if (!$this->projectId || !$credsPath) {
            return;
        }

        $fullPath = base_path($credsPath);
        if (!file_exists($fullPath)) {
            Log::warning("FCM credentials not found: {$fullPath}");
            return;
        }

        try {
            $this->accessToken = $this->getAccessToken($fullPath);
            $this->http = new Client([
                'base_uri' => 'https://fcm.googleapis.com',
                'timeout'  => 20,
                'headers'  => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('FCM init failed: ' . $e->getMessage());
        }
    }

    /**
     * Get an OAuth2 access token from the service account JSON.
     * Cached for 55 minutes.
     */
    protected function getAccessToken(string $credsPath): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(55), function () use ($credsPath) {
            $creds = json_decode(file_get_contents($credsPath), true);

            if (!$creds || empty($creds['private_key']) || empty($creds['client_email'])) {
                throw new \Exception('Invalid service account JSON');
            }

            $now = time();
            $payload = [
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ];

            $jwt = JWT::encode($payload, $creds['private_key'], 'RS256');

            $client = new Client(['timeout' => 15]);
            $res = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ]);

            $body = json_decode((string) $res->getBody(), true);
            if (empty($body['access_token'])) {
                throw new \Exception('No access_token returned');
            }
            return $body['access_token'];
        });
    }

    /**
     * Send push to a single device token (FCM v1 HTTP API).
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): bool {
        if (!$this->http || !$this->projectId) return false;

        try {
            $res = $this->http->post("/v1/projects/{$this->projectId}/messages:send", [
                'json' => [
                    'message' => [
                        'token'        => $token,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],
                        'data'         => $this->stringifyData($data),
                        'android'      => [
                            'priority'     => 'high',
                            'notification' => [
                                'channel_id' => 'monteriq_default',
                                'sound'      => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

            return $res->getStatusCode() === 200;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse()?->getStatusCode();
            // 404 / 400 = invalid/expired token → will be removed by caller
            Log::info("FCM rejected token (HTTP {$status})");
            return false;
        } catch (\Throwable $e) {
            Log::warning('FCM send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send push to all of a user's tokens, honoring their preferences.
     */
    public function sendToUser(
        string $userId,
        string $title,
        string $body,
        array $data = [],
        ?string $category = null
    ): int {
        if (!$this->http) return 0;

        $sb = app(SupabaseClient::class);

        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $userId]);
        if (!$profile || ($profile['push_enabled'] ?? true) === false) return 0;

        if ($category !== null) {
            $pref = match ($category) {
                'transactions' => 'push_transactions',
                'security'     => 'push_security',
                'promotions'   => 'push_promotions',
                'kyc'          => 'push_kyc',
                default        => null,
            };
            if ($pref && isset($profile[$pref]) && $profile[$pref] === false) {
                return 0;
            }
        }

        $tokens = $sb->rest('device_tokens', [
            'user_id' => 'eq.' . $userId,
            'select'  => 'id,token',
        ]);

        $sent = 0;
        foreach ($tokens as $t) {
            $ok = $this->sendToToken($t['token'], $title, $body, $data);
            if ($ok) {
                $sent++;
            } else {
                // Remove invalid token
                try {
                    $sb->http->delete('/rest/v1/device_tokens', [
                        'query' => ['id' => 'eq.' . $t['id']],
                    ]);
                } catch (\Throwable $e) {}
            }
        }

        return $sent;
    }

    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
        }
        return $out;
    }
}
