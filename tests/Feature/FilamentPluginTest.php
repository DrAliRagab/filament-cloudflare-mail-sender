<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Filament\Pages\ComposeEmail;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

test('the plugin registers its compose page and read only audit resource', function (): void {
    $plugin = FilamentCloudflareMailSenderPlugin::make();
    $panel = Panel::make()->id('mail')->plugin($plugin);

    expect($panel->getPages())->toContain(ComposeEmail::class)
        ->and($panel->getResources())->toContain(CloudflareMailMessageResource::class)
        ->and($plugin->canCompose(null))->toBeFalse()
        ->and($plugin->canViewAudit(null))->toBeFalse()
        ->and($plugin->canCompose(new TestActor('actor-1')))->toBeTrue()
        ->and($plugin->canViewAudit(new TestActor('actor-1')))->toBeTrue();
});

test('compose and sent emails share a default Filament navigation section', function (): void {
    expect(config('filament-cloudflare-mail-sender.navigation.group'))->toBe('Email')
        ->and(ComposeEmail::getNavigationGroup())->toBe('Email')
        ->and(CloudflareMailMessageResource::getNavigationGroup())->toBe('Email');
});

test('host callbacks can independently restrict compose and audit access', function (): void {
    $plugin = FilamentCloudflareMailSenderPlugin::make()
        ->authorizeComposeUsing(static fn (Authenticatable $actor): bool => $actor->getAuthIdentifier() === 'composer')
        ->authorizeAuditUsing(static fn (Authenticatable $actor): bool => $actor->getAuthIdentifier() === 'auditor');

    expect($plugin->canCompose(new TestActor('composer')))->toBeTrue()
        ->and($plugin->canCompose(new TestActor('auditor')))->toBeFalse()
        ->and($plugin->canViewAudit(new TestActor('auditor')))->toBeTrue()
        ->and($plugin->canViewAudit(new TestActor('composer')))->toBeFalse();
});
