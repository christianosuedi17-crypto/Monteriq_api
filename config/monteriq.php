<?php

return [
    'supabase' => [
        'url'         => env('SUPABASE_URL'),
        'anon_key'    => env('SUPABASE_ANON_KEY'),
        'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'jwt_secret'  => env('SUPABASE_JWT_SECRET'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url'   => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'env'        => env('PAYSTACK_ENV', 'test'),
    ],

    'fcm' => [
        'project_id'       => env('FCM_PROJECT_ID', 'monteriq'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH', storage_path('app/firebase-service-account.json')),
    ],
];