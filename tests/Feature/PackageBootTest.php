<?php

use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderServiceProvider;
use DrAliRagab\FilamentCloudflareMailSender\Support\ConfiguredSender;
use Filament\Panel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;

test('the package provider boots its defaults and the plugin has a stable identity', function (): void {
    $plugin = new FilamentCloudflareMailSenderPlugin;
    $panel = Panel::make()
        ->id('mail-sender')
        ->plugin($plugin);

    $panel->boot();

    expect(app()->getLoadedProviders())
        ->toHaveKey(FilamentCloudflareMailSenderServiceProvider::class)
        ->and(config('filament-cloudflare-mail-sender.mailer'))->toBe('cloudflare')
        ->and(config('filament-cloudflare-mail-sender.from.address'))->toBeNull()
        ->and(config('filament-cloudflare-mail-sender.from.name'))->toBeNull()
        ->and(config('filament-cloudflare-mail-sender.from.editable'))->toBeTrue()
        ->and(config('filament-cloudflare-mail-sender.queue.connection'))->toBeNull()
        ->and(ConfiguredSender::address())->toBe(config('mail.from.address'))
        ->and(ConfiguredSender::name())->toBe(config('mail.from.name'))
        ->and(ConfiguredSender::editable())->toBeTrue()
        ->and($panel->hasPlugin('filament-cloudflare-mail-sender'))->toBeTrue()
        ->and($panel->getPlugin('filament-cloudflare-mail-sender'))->toBe($plugin);
});

test('Laravel discovers the package provider from Composer package metadata', function (): void {
    $files = new Filesystem;
    $packageRoot = dirname(__DIR__, 2);
    $temporaryHost = sys_get_temp_dir().'/filament-cloudflare-mail-sender-manifest-'.bin2hex(random_bytes(8));

    try {
        $files->ensureDirectoryExists($temporaryHost.'/vendor/composer');
        $files->ensureDirectoryExists($temporaryHost.'/bootstrap/cache');

        $packageMetadata = json_decode(
            $files->get($packageRoot.'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        assert(is_array($packageMetadata));

        $files->put(
            $temporaryHost.'/vendor/composer/installed.json',
            json_encode(['packages' => [$packageMetadata]], JSON_THROW_ON_ERROR),
        );

        $manifest = new PackageManifest(
            $files,
            $temporaryHost,
            $temporaryHost.'/bootstrap/cache/packages.php',
        );

        expect($manifest->providers())->toContain(FilamentCloudflareMailSenderServiceProvider::class);
    } finally {
        $files->deleteDirectory($temporaryHost);
    }
});

test('the publish-only migration remains publishable without being auto-loaded', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $migrationDirectory = $packageRoot.'/database/migrations';
    $publishedMigrations = ServiceProvider::pathsToPublish(
        FilamentCloudflareMailSenderServiceProvider::class,
        'filament-cloudflare-mail-sender-migrations',
    );

    expect(resolve(Migrator::class)->paths())->not->toContain($migrationDirectory)
        ->and($publishedMigrations)->toHaveKey($migrationDirectory.'/create_cloudflare_mail_messages_table.php.stub')
        ->and($publishedMigrations[$migrationDirectory.'/create_cloudflare_mail_messages_table.php.stub'])
        ->toBe(database_path('migrations/create_cloudflare_mail_messages_table.php'));
});
