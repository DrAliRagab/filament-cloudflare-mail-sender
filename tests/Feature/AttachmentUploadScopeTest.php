<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Illuminate\Contracts\Encryption\StringEncrypter;

beforeEach(function (): void {
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'mail-attachments');
});

test('attachment scopes reject undecipherable and malformed proofs', function (): void {
    $scopes = resolve(AttachmentUploadScope::class);
    $actor = new TestActor('composer-1');

    expect(fn () => $scopes->inspect($actor, 'not-an-encrypted-proof'))
        ->toThrow(InvalidAttachment::class, 'scope is invalid');

    $malformedProof = resolve(StringEncrypter::class)->encryptString('{not-json');

    expect(fn () => $scopes->inspect($actor, $malformedProof))
        ->toThrow(InvalidAttachment::class, 'scope is invalid');
});

test('attachment scopes are bound to the exact authenticated actor', function (): void {
    $scopes = resolve(AttachmentUploadScope::class);
    $proof = $scopes->create(new TestActor('composer-1'))->proof;

    expect(fn () => $scopes->inspect(new TestActor('composer-2'), $proof))
        ->toThrow(InvalidAttachment::class, 'does not belong');
});

test('attachment scopes reject unsafe storage root configuration', function (mixed $directory): void {
    config()->set('filament-cloudflare-mail-sender.attachments.directory', $directory);

    expect(fn () => resolve(AttachmentUploadScope::class)->create(new TestActor('composer-1')))
        ->toThrow(InvalidAttachment::class, 'directory configuration is invalid');
})->with([
    'null' => null,
    'empty' => '',
    'absolute' => '/mail-attachments',
    'parent traversal' => 'mail-attachments/../private',
]);

test('attachment scopes reject actors without a stable identifier', function (): void {
    expect(fn () => resolve(AttachmentUploadScope::class)->create(new TestActor('')))
        ->toThrow(InvalidAttachment::class, 'invalid identifier');
});
