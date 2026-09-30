<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use GuzzleHttp\Client;

class JwtVerifier
{
    public static function verify(string $jwt): ?array
    {
        try {
            $keys = self::getJwks();
            if (empty($keys)) {
                \Log::warning('JWT verify: no keys from JWKS');
                return null;
            }
            // Tolerate up to 60s of clock skew between the phone and this server.
            // Without this, any iat/exp within 1-2s of "now" gets rejected with
            // "Cannot handle token with iat prior to ..." or "Expired token".
            JWT::$leeway = 60;

            $decoded = JWT::decode($jwt, $keys);
            return (array) $decoded;
        } catch (\Throwable $e) {
            \Log::warning('JWT verify failed: ' . $e->getMessage());
            return null;
        }
    }

    protected static function getJwks(): array
    {
        $cacheKey = 'supabase_jwks';
        $cached = cache()->get($cacheKey);
        if ($cached && is_array($cached)) {
            return self::parseJwks($cached);
        }

        $url = rtrim((string) config('monteriq.supabase.url'), '/')
             . '/auth/v1/.well-known/jwks.json';

        try {
            $client = new Client(['timeout' => 10]);
            $res = $client->get($url);
            $json = json_decode((string) $res->getBody(), true);
        } catch (\Throwable $e) {
            \Log::warning('JWKS fetch failed: ' . $e->getMessage());
            return [];
        }

        if (!$json || empty($json['keys'])) {
            return [];
        }

        cache()->put($cacheKey, $json, now()->addHours(6));

        return self::parseJwks($json);
    }

    protected static function parseJwks(array $jwks): array
    {
        try {
            return JWK::parseKeySet($jwks, 'ES256');
        } catch (\Throwable $e) {
            \Log::warning('JWKS parse failed: ' . $e->getMessage());
            return [];
        }
    }
}