<?php

namespace App\Support;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Dropbox\RefreshableTokenProvider;

class DropboxTokenProvider implements RefreshableTokenProvider
{
    private const CACHE_KEY = 'services.dropbox.access_token';

    private const CACHE_SAFETY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly ?string $accessToken = null,
        private readonly ?string $refreshToken = null,
        private readonly ?string $appKey = null,
        private readonly ?string $appSecret = null,
    ) {}

    public function getToken(): string
    {
        if (! $this->canRefresh()) {
            return (string) $this->accessToken;
        }

        $cachedToken = $this->cachedAccessToken();
        if ($cachedToken !== null) {
            return $cachedToken;
        }

        if ($this->refreshAccessToken()) {
            return (string) $this->cachedAccessToken();
        }

        return (string) $this->accessToken;
    }

    public function refresh(ClientException $exception): bool
    {
        if (! $this->canRefresh()) {
            return false;
        }

        return $this->refreshAccessToken();
    }

    private function canRefresh(): bool
    {
        return $this->refreshToken !== null
            && $this->refreshToken !== ''
            && $this->appKey !== null
            && $this->appKey !== ''
            && $this->appSecret !== null
            && $this->appSecret !== '';
    }

    private function cachedAccessToken(): ?string
    {
        $token = Cache::get(self::CACHE_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function refreshAccessToken(): bool
    {
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
        ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            return false;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $ttl = max($expiresIn - self::CACHE_SAFETY_MARGIN_SECONDS, 60);

        Cache::put(self::CACHE_KEY, $token, $ttl);

        return true;
    }
}
