<?php

namespace App\Http\Middleware;

use App\Services\JwtVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupabaseAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Missing bearer token'], 401);
        }

        $claims = JwtVerifier::verify(substr($header, 7));

        if (!$claims || empty($claims['sub'])) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $request->attributes->set('supabase_user', [
            'id'         => $claims['sub'],
            'email'      => $claims['email'] ?? null,
            'session_id' => $claims['session_id'] ?? null,
        ]);

        return $next($request);
    }
}