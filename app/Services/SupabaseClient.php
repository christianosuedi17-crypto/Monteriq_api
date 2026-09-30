<?php

namespace App\Services;

use GuzzleHttp\Client;

class SupabaseClient
{
    protected Client $http;
    protected string $url;
    protected string $serviceKey;

    public function __construct()
    {
        $this->url = rtrim((string) config('monteriq.supabase.url'), '/');
        $this->serviceKey = (string) config('monteriq.supabase.service_key');

        $this->http = new Client([
            'base_uri' => $this->url,
            'timeout'  => 20,
            'headers'  => [
                'apikey'        => $this->serviceKey,
                'Authorization' => 'Bearer ' . $this->serviceKey,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ],
        ]);
    }

    public function rest(string $table, array $query = []): array
    {
        $res = $this->http->get("/rest/v1/{$table}", ['query' => $query]);
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    public function restOne(string $table, array $query = []): ?array
    {
        $query['limit'] = 1;
        $rows = $this->rest($table, $query);
        return $rows[0] ?? null;
    }

    public function update(string $table, array $filters, array $patch): array
    {
        $query = [];
        foreach ($filters as $k => $v) $query[$k] = 'eq.' . $v;
        $res = $this->http->patch("/rest/v1/{$table}", [
            'query' => $query,
            'json'  => $patch,
        ]);
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    public function insert(string $table, array $row): array
    {
        $res = $this->http->post("/rest/v1/{$table}", ['json' => $row]);
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    public function delete(string $table, array $filters): array
    {
        $query = [];
        foreach ($filters as $k => $v) $query[$k] = 'eq.' . $v;
        $res = $this->http->delete("/rest/v1/{$table}", ['query' => $query]);
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    public function storageUpload(string $bucket, string $path, string $binary, string $mime): array
    {
        $res = $this->http->post("/storage/v1/object/{$bucket}/{$path}", [
            'headers' => ['Content-Type' => $mime, 'x-upsert' => 'true'],
            'body'    => $binary,
        ]);
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    public function publicUrl(string $bucket, string $path): string
    {
        return "{$this->url}/storage/v1/object/public/{$bucket}/{$path}";
    }

    public function signedUrl(string $bucket, string $path, int $seconds = 3600): string
    {
        $res = $this->http->post("/storage/v1/object/sign/{$bucket}/{$path}", [
            'json' => ['expiresIn' => $seconds],
        ]);
        $data = json_decode((string) $res->getBody(), true) ?? [];
        return "{$this->url}/storage/v1{$data['signedURL']}";
    }
}