<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Pages\ComposeEmail;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages\ListCloudflareMailMessages;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages\ViewCloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('filament-cloudflare-mail-sender.navigation', [
        'compose_label' => 'Write transactional email',
        'messages_label' => 'Email audit',
        'group' => 'Operations',
        'icon' => 'heroicon-o-envelope',
        'sort' => 31,
    ]);

    $panel = Panel::make()
        ->id('audit-mail')
        ->default()
        ->plugin(FilamentCloudflareMailSenderPlugin::make());
    Filament::setCurrentPanel($panel);
    Auth::guard('web')->setUser(new TestActor('auditor-1'));

    Route::get('/test-compose-mail', static fn (): string => 'compose')
        ->name(ComposeEmail::getRouteName());
    Route::get('/test-mail-audit', static fn (): string => 'audit')
        ->name(CloudflareMailMessageResource::getRouteBaseName().'.index');
    Route::get('/test-mail-audit/{record}', static fn (): string => 'view')
        ->name(CloudflareMailMessageResource::getRouteBaseName().'.view');
});

test('audit resource is read only and uses configured navigation', function (): void {
    $message = auditResourceMessage();

    expect(CloudflareMailMessageResource::canViewAny())->toBeTrue()
        ->and(CloudflareMailMessageResource::canView($message))->toBeTrue()
        ->and(CloudflareMailMessageResource::canCreate())->toBeFalse()
        ->and(CloudflareMailMessageResource::canEdit($message))->toBeFalse()
        ->and(CloudflareMailMessageResource::canDelete($message))->toBeFalse()
        ->and(CloudflareMailMessageResource::canDeleteAny())->toBeFalse()
        ->and(CloudflareMailMessageResource::canForceDelete($message))->toBeFalse()
        ->and(CloudflareMailMessageResource::canForceDeleteAny())->toBeFalse()
        ->and(CloudflareMailMessageResource::canRestore($message))->toBeFalse()
        ->and(CloudflareMailMessageResource::canRestoreAny())->toBeFalse()
        ->and(CloudflareMailMessageResource::getNavigationLabel())->toBe('Email audit')
        ->and(CloudflareMailMessageResource::getNavigationGroup())->toBe('Operations')
        ->and(CloudflareMailMessageResource::getNavigationIcon())->toBe('heroicon-o-envelope')
        ->and(CloudflareMailMessageResource::getNavigationSort())->toBe(31);
});

test('audit list filters by either trusted actor field', function (): void {
    $matchingType = auditResourceMessage([
        'actor_type' => 'App\\Models\\OperationsAdministrator',
        'actor_id' => 'actor-100',
        'subject' => 'Matched by actor type',
    ]);
    $matchingId = auditResourceMessage([
        'actor_type' => TestActor::class,
        'actor_id' => 'billing-reviewer',
        'subject' => 'Matched by actor ID',
    ]);
    $other = auditResourceMessage([
        'actor_type' => TestActor::class,
        'actor_id' => 'unrelated',
        'subject' => 'Unrelated actor',
    ]);

    Livewire::test(ListCloudflareMailMessages::class)
        ->filterTable('actor', ['value' => 'OperationsAdministrator'])
        ->assertCanSeeTableRecords([$matchingType])
        ->assertCanNotSeeTableRecords([$matchingId, $other]);

    Livewire::test(ListCloudflareMailMessages::class)
        ->filterTable('actor', ['value' => 'billing-reviewer'])
        ->assertCanSeeTableRecords([$matchingId])
        ->assertCanNotSeeTableRecords([$matchingType, $other]);
});

test('audit view safely represents scrubbed bodies and malformed legacy attachment metadata', function (): void {
    $message = auditResourceMessage([
        'markdown_body' => null,
        'html_body' => null,
        'text_body' => null,
    ]);
    DB::table('cloudflare_mail_messages')
        ->where('id', $message->id)
        ->update(['attachments' => json_encode('legacy-invalid-shape', JSON_THROW_ON_ERROR)]);

    Livewire::test(ViewCloudflareMailMessage::class, ['record' => $message->id])
        ->assertOk()
        ->assertSee(__('filament-cloudflare-mail-sender::messages.audit.html'))
        ->assertSee(__('filament-cloudflare-mail-sender::messages.audit.text'))
        ->assertSee(__('filament-cloudflare-mail-sender::messages.audit.body_unavailable'))
        ->assertSee(__('filament-cloudflare-mail-sender::messages.audit.empty_value'));
});

test('audit resource falls back safely for invalid navigation configuration', function (): void {
    config()->set('filament-cloudflare-mail-sender.navigation', [
        'messages_label' => '',
        'group' => 123,
        'icon' => false,
        'sort' => '31',
    ]);

    expect(CloudflareMailMessageResource::getNavigationLabel())->toBe('Sent Emails')
        ->and(CloudflareMailMessageResource::getNavigationGroup())->toBeNull()
        ->and(CloudflareMailMessageResource::getNavigationIcon())->toBeNull()
        ->and(CloudflareMailMessageResource::getNavigationSort())->toBeNull();
});

test('audit list labels accepted status accurately and filters by stored status', function (): void {
    $sent = auditResourceMessage([
        'subject' => 'Accepted message',
        'status' => MessageStatus::Sent,
        'sent_at' => now(),
    ]);
    $failed = auditResourceMessage([
        'subject' => 'Failed message',
        'status' => MessageStatus::Failed,
        'failed_at' => now(),
    ]);

    $list = Livewire::test(ListCloudflareMailMessages::class);
    $list->assertOk();
    $list
        ->assertCanSeeTableRecords([$sent, $failed])
        ->assertTableColumnFormattedStateSet('status', 'Accepted by Cloudflare', $sent)
        ->assertTableActionExists('view', record: $sent)
        ->assertTableActionExists('duplicate', record: $sent)
        ->filterTable('status', MessageStatus::Sent->value)
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$failed]);
});

test('audit view shows safe delivery evidence and accepted not delivered help', function (): void {
    $message = auditResourceMessage([
        'status' => MessageStatus::Sent,
        'to' => ['reader@example.test'],
        'cc' => ['copy@example.test'],
        'bcc' => ['hidden@example.test'],
        'html_body' => '<p>Hello <strong>reader</strong></p>',
        'text_body' => 'Hello reader',
        'failure_message' => '<script>alert(1)</script>',
        'attachments' => [[
            'disk' => 'local',
            'path' => 'mail/report.pdf',
            'name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]],
    ]);

    $view = Livewire::test(ViewCloudflareMailMessage::class, ['record' => $message->id]);
    $view->assertOk();
    $view->assertActionExists('duplicate');
    $view
        ->assertSee('Accepted by Cloudflare')
        ->assertSee('It does not mean the email was delivered to the inbox.')
        ->assertSee('reader@example.test')
        ->assertSee('copy@example.test')
        ->assertSee('hidden@example.test')
        ->assertSeeHtml('<strong>reader</strong>')
        ->assertSee('Hello reader')
        ->assertSee('report.pdf')
        ->assertSee('<script>alert(1)</script>');
});

test('audit list and view deny access through the plugin callback', function (): void {
    $message = auditResourceMessage();
    $panel = Panel::make()
        ->id('restricted-audit')
        ->default()
        ->plugin(FilamentCloudflareMailSenderPlugin::make()
            ->authorizeAuditUsing(static fn (): bool => false));
    Filament::setCurrentPanel($panel);

    Livewire::test(ListCloudflareMailMessages::class)->assertForbidden();
    Livewire::test(ViewCloudflareMailMessage::class, ['record' => $message->id])->assertForbidden();
});

test('duplicate action rechecks audit and compose authorization before redirecting', function (): void {
    $message = auditResourceMessage();

    Livewire::test(ListCloudflareMailMessages::class)
        ->callTableAction('duplicate', $message)
        ->assertRedirect(ComposeEmail::getUrl(['duplicate' => $message->id]));

    expect(CloudflareMailMessage::query()->count())->toBe(1);

    $panel = Panel::make()
        ->id('lost-audit')
        ->default()
        ->plugin(FilamentCloudflareMailSenderPlugin::make()
            ->authorizeAuditUsing(static fn (): bool => false));
    Filament::setCurrentPanel($panel);

    Livewire::test(ListCloudflareMailMessages::class)->assertForbidden();
});

test('mounted audit list and duplicate action recheck changing authorization', function (): void {
    $message = auditResourceMessage();
    $auditAllowed = true;
    $composeAllowed = true;
    $plugin = FilamentCloudflareMailSenderPlugin::make()
        ->authorizeAuditUsing(static function () use (&$auditAllowed): bool {
            return $auditAllowed;
        })
        ->authorizeComposeUsing(static function () use (&$composeAllowed): bool {
            return $composeAllowed;
        });
    Filament::setCurrentPanel(Panel::make()->id('audit-mail')->default()->plugin($plugin));

    $list = Livewire::test(ListCloudflareMailMessages::class);
    $auditAllowed = false;
    $list->filterTable('status', MessageStatus::Draft->value)->assertForbidden();

    $auditAllowed = true;
    $composeAllowed = false;

    expect(function (): void {
        CloudflareMailMessageResource::authorizeDuplicate();
    })
        ->toThrow(HttpException::class);
});

/** @param array<string, mixed> $overrides */
function auditResourceMessage(array $overrides = []): CloudflareMailMessage
{
    return CloudflareMailMessage::query()->create(array_merge([
        'actor_type' => TestActor::class,
        'actor_id' => 'auditor-1',
        'mailer' => 'audit-array',
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender Team',
        'reply_to_address' => 'replies@example.test',
        'reply_to_name' => 'Replies Team',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Audit message',
        'markdown_body' => 'Audit **body**',
        'html_body' => '<p>Audit <strong>body</strong></p>',
        'text_body' => 'Audit body',
        'attachments' => [],
        'status' => MessageStatus::Draft,
    ], $overrides));
}
