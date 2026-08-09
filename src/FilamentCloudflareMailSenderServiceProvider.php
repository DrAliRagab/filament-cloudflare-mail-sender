<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender;

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Console\Commands\PruneCloudflareMailMessages;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\MessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\RecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Recipients\EloquentRecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Rendering\CommonMarkMessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Support\AuditBodyStoragePolicy;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFactory;
use Illuminate\Support\ServiceProvider;

final class FilamentCloudflareMailSenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-cloudflare-mail-sender.php',
            'filament-cloudflare-mail-sender',
        );

        $this->app->singleton(RecipientSuggestionProvider::class, function (): EloquentRecipientSuggestionProvider {
            $recipients = config('filament-cloudflare-mail-sender.recipients', []);
            $model = is_array($recipients) && is_string($recipients['model'] ?? null)
                ? $recipients['model']
                : null;
            $emailColumn = is_array($recipients) && is_string($recipients['email_column'] ?? null)
                ? $recipients['email_column']
                : 'email';
            $nameColumn = is_array($recipients) && is_string($recipients['name_column'] ?? null)
                ? $recipients['name_column']
                : 'name';
            $suggestionLimit = is_array($recipients) && is_int($recipients['suggestion_limit'] ?? null)
                ? $recipients['suggestion_limit']
                : 250;

            return new EloquentRecipientSuggestionProvider(
                model: $model,
                emailColumn: $emailColumn,
                nameColumn: $nameColumn,
                limit: $suggestionLimit,
            );
        });

        $this->app->singleton(MessageBodyRenderer::class, CommonMarkMessageBodyRenderer::class);
        $this->app->singleton(AttachmentPolicy::class, AttachmentPolicy::fromConfiguration(...));
        $this->app->singleton(AuditBodyStoragePolicy::class, AuditBodyStoragePolicy::fromConfiguration(...));
        $this->app->singleton(MessageSnapshotFactory::class);
    }

    public function boot(): void
    {
        $migration = dirname(__DIR__).'/database/migrations/create_cloudflare_mail_messages_table.php.stub';

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-cloudflare-mail-sender');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-cloudflare-mail-sender');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneCloudflareMailMessages::class]);
        }

        $this->publishes([
            __DIR__.'/../config/filament-cloudflare-mail-sender.php' => config_path('filament-cloudflare-mail-sender.php'),
        ], 'filament-cloudflare-mail-sender-config');

        $this->publishesMigrations([
            $migration => database_path('migrations/create_cloudflare_mail_messages_table.php'),
        ], 'filament-cloudflare-mail-sender-migrations');
    }
}
