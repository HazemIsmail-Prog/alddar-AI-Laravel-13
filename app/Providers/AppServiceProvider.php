<?php

namespace App\Providers;

use App\Support\Commentables;
use App\Support\DropboxTokenProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap(Commentables::morphMap());

        $this->registerDropboxDriver();
    }

    private function registerDropboxDriver(): void
    {
        Storage::extend('dropbox', function (Application $app, array $config): FilesystemAdapter {
            $tokenProvider = new DropboxTokenProvider(
                accessToken: $config['token'] ?? null,
                refreshToken: $config['refresh_token'] ?? null,
                appKey: $config['app_key'] ?? null,
                appSecret: $config['app_secret'] ?? null,
            );

            $adapter = new DropboxAdapter(new DropboxClient($tokenProvider), (string) ($config['root'] ?? ''));

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
