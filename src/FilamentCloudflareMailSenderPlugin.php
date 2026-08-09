<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender;

use Closure;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Pages\ComposeEmail;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

final class FilamentCloudflareMailSenderPlugin implements Plugin
{
    private ?Closure $composeAuthorization = null;

    private ?Closure $auditAuthorization = null;

    public static function make(): self
    {
        return new self;
    }

    public function authorizeComposeUsing(Closure $callback): self
    {
        $this->composeAuthorization = $callback;

        return $this;
    }

    public function authorizeAuditUsing(Closure $callback): self
    {
        $this->auditAuthorization = $callback;

        return $this;
    }

    public function canCompose(?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $this->composeAuthorization === null || (bool) ($this->composeAuthorization)($actor);
    }

    public function canViewAudit(?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $this->auditAuthorization === null || (bool) ($this->auditAuthorization)($actor);
    }

    public function getId(): string
    {
        return 'filament-cloudflare-mail-sender';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([ComposeEmail::class])
            ->resources([CloudflareMailMessageResource::class]);
    }

    public function boot(Panel $panel): void {}
}
