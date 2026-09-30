<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class HealthController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'status'  => 'ok',
            'service' => 'Monteriq API',
            'time'    => now()->toIso8601String(),
        ]);
    }
}