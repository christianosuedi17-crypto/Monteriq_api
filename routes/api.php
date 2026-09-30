<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\PinController;
use App\Http\Controllers\Api\V1\BankController;
use App\Http\Controllers\Api\V1\BeneficiaryController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\DeviceController;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);
    Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle']);

    Route::middleware('supabase.auth')->group(function () {
        Route::get('/me', MeController::class);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::post('/wallet/virtual-account', [WalletController::class, 'virtualAccount']);
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show']);

        // KYC
        Route::get('/kyc/status',         [KycController::class, 'status']);
        Route::post('/kyc/submit',        [KycController::class, 'submit']);
        Route::post('/kyc/upload-selfie', [KycController::class, 'uploadSelfie']);
        Route::post('/kyc/upload-id',     [KycController::class, 'uploadId']);

        // PIN
        Route::post('/pin/set',    [PinController::class, 'set']);
        Route::post('/pin/verify', [PinController::class, 'verify']);

        // Banks & resolve
        Route::get('/banks',          [BankController::class, 'index']);
        Route::post('/banks/resolve', [BankController::class, 'resolve']);

        // Beneficiaries
        Route::get('/beneficiaries',         [BeneficiaryController::class, 'index']);
        Route::delete('/beneficiaries/{id}', [BeneficiaryController::class, 'destroy']);

        // Withdrawals
        Route::post('/withdrawals',      [WithdrawalController::class, 'create']);
        Route::get('/withdrawals',       [WithdrawalController::class, 'index']);
        Route::get('/withdrawals/{id}',  [WithdrawalController::class, 'show']);

        // Notifications
        Route::get('/notifications',            [NotificationController::class, 'index']);
        Route::post('/notifications/read-all',  [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{id}',    [NotificationController::class, 'destroy']);

        // Security
        Route::patch('/security',            [SecurityController::class, 'update']);
        Route::post('/security/change-pin',  [SecurityController::class, 'changePin']);

        // Devices (FCM push)
        Route::post('/devices/register',     [DeviceController::class, 'register']);
        Route::post('/devices/unregister',   [DeviceController::class, 'unregister']);
        Route::patch('/devices/preferences', [DeviceController::class, 'preferences']);
    });
});