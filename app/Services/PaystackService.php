<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    protected Client $http;
    protected string $secret;

    public function __construct()
    {
        $this->secret = (string) config('monteriq.paystack.secret_key');
        $this->http = new Client([
            'base_uri' => 'https://api.paystack.co',
            'timeout'  => 30,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->secret,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    // ───── Customers & DVA (existing) ─────

    public function createCustomer(string $email, string $firstName, string $lastName, string $phone): ?array
    {
        try {
            $res = $this->http->post('/customer', [
                'json' => ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone],
            ]);
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Paystack createCustomer failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getCustomer(string $customerCode): ?array
    {
        try {
            $res = $this->http->get("/customer/{$customerCode}");
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    public function updateCustomerPhone(string $customerCode, string $phone): bool
    {
        try {
            $this->http->put("/customer/{$customerCode}", ['json' => ['phone' => $phone]]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public function createDedicatedAccount(string $customerCode, string $phone = ''): ?array
    {
        try {
            $payload = ['customer' => $customerCode, 'preferred_bank' => 'wema-bank'];
            if (!empty($phone)) $payload['phone'] = $phone;
            $res = $this->http->post('/dedicated_account', ['json' => $payload]);
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Paystack createDVA failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function listDedicatedAccounts(string $customerCode): array
    {
        try {
            $res = $this->http->get('/dedicated_account', ['query' => ['customer' => $customerCode]]);
            return json_decode((string) $res->getBody(), true)['data'] ?? [];
        } catch (\Throwable $e) { return []; }
    }

    // ───── Banks & account resolution ─────

    public function listBanks(): array
    {
        try {
            $res = $this->http->get('/bank', ['query' => ['country' => 'nigeria', 'currency' => 'NGN']]);
            return json_decode((string) $res->getBody(), true)['data'] ?? [];
        } catch (\Throwable $e) {
            Log::error('Paystack listBanks failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function resolveAccount(string $accountNumber, string $bankCode): ?array
    {
        try {
            $res = $this->http->get('/bank/resolve', [
                'query' => ['account_number' => $accountNumber, 'bank_code' => $bankCode],
            ]);
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Paystack resolveAccount failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ───── Transfers (withdrawals) ─────

    /**
     * Create a transfer recipient (Paystack-side).
     * Returns a recipient_code used for future transfers.
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): ?array
    {
        try {
            $res = $this->http->post('/transferrecipient', [
                'json' => [
                    'type'           => 'nuban',
                    'name'           => $name,
                    'account_number' => $accountNumber,
                    'bank_code'      => $bankCode,
                    'currency'       => 'NGN',
                ],
            ]);
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Paystack createTransferRecipient failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Initiate a transfer (in kobo).
     */
    public function initiateTransfer(string $recipientCode, int $amountKobo, string $reference, string $reason = 'Withdrawal'): ?array
    {
        try {
            $res = $this->http->post('/transfer', [
                'json' => [
                    'source'    => 'balance',
                    'amount'    => $amountKobo,
                    'recipient' => $recipientCode,
                    'reason'    => $reason,
                    'reference' => $reference,
                ],
            ]);
            return json_decode((string) $res->getBody(), true)['data'] ?? null;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = (string) $e->getResponse()->getBody();
            Log::error('Paystack initiateTransfer failed', ['error' => $e->getMessage(), 'body' => $body]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Paystack initiateTransfer failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $hash = hash_hmac('sha512', $payload, $this->secret);
        return hash_equals($hash, $signature);
    }
}