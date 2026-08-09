<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Data\OwnedUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\OwnedDraftManager;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('owned-draft-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'owned-draft-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'mail-attachments');
});

test('cancellation rejects an upload scope for a different draft', function (): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);

    expect(fn () => resolve(OwnedDraftManager::class)->cancel($actor, (string) Str::ulid(), $scope->proof))
        ->toThrow(InvalidMessageSnapshot::class, 'scope does not match');
});

test('cancellation rejects queued or terminal owned records', function (MessageStatus $status): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);
    createOwnedDraftRecord($actor, $scope, $status);

    expect(fn () => resolve(OwnedDraftManager::class)->cancel($actor, $scope->draftId, $scope->proof))
        ->toThrow(InvalidMessageSnapshot::class, 'Only an unsent draft');
})->with([
    'queued' => MessageStatus::Queued,
    'cancelled' => MessageStatus::Cancelled,
]);

test('authoritative scope pruning rejects a different draft identifier', function (): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);

    expect(fn () => resolve(OwnedDraftManager::class)->pruneToAuthoritativeLedger(
        $actor,
        (string) Str::ulid(),
        $scope->proof,
    ))->toThrow(InvalidMessageSnapshot::class, 'scope does not match');
});

test('authoritative scope pruning rejects a missing owned record', function (): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);

    expect(fn () => resolve(OwnedDraftManager::class)->pruneToAuthoritativeLedger(
        $actor,
        $scope->draftId,
        $scope->proof,
    ))->toThrow(InvalidMessageSnapshot::class, 'authoritative owned message');
});

test('authoritative scope pruning keeps only the locked record ledger for every lifecycle state', function (MessageStatus $status): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);
    $keptPath = $scope->directory.'/kept.pdf';
    $strayPath = $scope->directory.'/stray.pdf';
    Storage::disk('owned-draft-attachments')->put($keptPath, "%PDF-1.4\nKept");
    Storage::disk('owned-draft-attachments')->put($strayPath, "%PDF-1.4\nStray");
    createOwnedDraftRecord($actor, $scope, $status, [[
        'disk' => 'owned-draft-attachments',
        'path' => $keptPath,
        'name' => 'kept.pdf',
        'mime_type' => 'application/pdf',
        'size' => 13,
        'sha256' => str_repeat('a', 64),
    ]]);

    resolve(OwnedDraftManager::class)->pruneToAuthoritativeLedger(
        $actor,
        $scope->draftId,
        $scope->proof,
    );

    expect(Storage::disk('owned-draft-attachments')->exists($keptPath))->toBeTrue()
        ->and(Storage::disk('owned-draft-attachments')->exists($strayPath))->toBeFalse();
})->with([
    'draft' => MessageStatus::Draft,
    'queued' => MessageStatus::Queued,
    'terminal' => MessageStatus::Sent,
]);

test('owned draft operations reject invalid disk and actor identity configuration', function (): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', null);
    config()->set('filesystems.default', null);

    expect(fn () => resolve(OwnedDraftManager::class)->deleteUpload(
        $actor,
        $scope->proof,
        $scope->directory.'/report.pdf',
    ))->toThrow(InvalidAttachment::class, 'disk configuration is invalid');

    expect(fn () => resolve(OwnedDraftManager::class)->find(new TestActor(''), $scope->draftId))
        ->toThrow(LogicException::class, 'invalid identifier');
});

test('owned draft pruning is a no-op when attachments are disabled even without a disk', function (): void {
    $actor = new TestActor('composer-1');
    $scope = ownedDraftScope($actor);
    createOwnedDraftRecord($actor, $scope, MessageStatus::Draft);
    config()->set('filament-cloudflare-mail-sender.attachments.enabled', false);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', null);
    config()->set('filesystems.default', null);

    resolve(OwnedDraftManager::class)->pruneToAuthoritativeLedger($actor, $scope->draftId, $scope->proof);

    expect(CloudflareMailMessage::query()->findOrFail($scope->draftId)->status)->toBe(MessageStatus::Draft);
});

function ownedDraftScope(TestActor $actor): OwnedUploadScope
{
    return resolve(AttachmentUploadScope::class)->create($actor);
}

/**
 * @param  list<array{disk: string, path: string, name: string, mime_type: string, size: int, sha256: string}>  $attachments
 */
function createOwnedDraftRecord(
    TestActor $actor,
    OwnedUploadScope $scope,
    MessageStatus $status,
    array $attachments = [],
): CloudflareMailMessage {
    $message = new CloudflareMailMessage;
    $message->forceFill([
        'id' => $scope->draftId,
        'actor_type' => $actor::class,
        'actor_id' => $actor->getAuthIdentifier(),
        'mailer' => 'array',
        'from_address' => 'sender@example.test',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Owned draft',
        'markdown_body' => 'Body',
        'html_body' => '<p>Body</p>',
        'text_body' => 'Body',
        'attachments' => $attachments,
        'status' => $status,
    ])->save();

    return $message;
}
