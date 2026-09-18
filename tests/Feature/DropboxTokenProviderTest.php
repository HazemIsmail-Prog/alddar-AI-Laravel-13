<?php

namespace Tests\Feature;

use App\Support\DropboxTokenProvider;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DropboxTokenProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('services.dropbox.access_token');
    }

    public function test_it_returns_the_static_access_token_when_no_refresh_token_is_configured(): void
    {
        Http::fake();

        $provider = new DropboxTokenProvider(accessToken: 'static-token');

        $this->assertSame('static-token', $provider->getToken());
        Http::assertNothingSent();
    }

    public function test_it_refreshes_and_caches_a_short_lived_access_token(): void
    {
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 14400,
                'token_type' => 'bearer',
            ]),
        ]);

        $provider = new DropboxTokenProvider(
            accessToken: 'expired-token',
            refreshToken: 'refresh-token',
            appKey: 'app-key',
            appSecret: 'app-secret',
        );

        $this->assertSame('fresh-token', $provider->getToken());
        $this->assertSame('fresh-token', Cache::get('services.dropbox.access_token'));

        Http::assertSent(fn ($request): bool => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token'
            && $request['client_id'] === 'app-key'
            && $request['client_secret'] === 'app-secret');

        $this->assertSame('fresh-token', $provider->getToken());
        Http::assertSentCount(1);
    }

    public function test_it_cannot_refresh_without_a_refresh_token(): void
    {
        Http::fake();

        $provider = new DropboxTokenProvider(accessToken: 'static-token');

        $exception = new ClientException(
            'Unauthorized',
            new Request('POST', 'https://api.dropboxapi.com/2/files/list_folder'),
            new Response(401),
        );

        $this->assertFalse($provider->refresh($exception));
        Http::assertNothingSent();
    }
}
