<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFingerprint;
use DrAliRagab\FilamentCloudflareMailSender\Support\PreviewProof;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;

test('preview proofs are bound to the exact actor and require a stable actor identifier', function (): void {
    $message = previewProofMessage();
    $proof = resolve(PreviewProof::class)->issue(new TestActor('composer-1'), $message);

    expect(fn () => resolve(PreviewProof::class)->verify(new TestActor('composer-2'), $proof))
        ->toThrow(InvalidMessageSnapshot::class, 'does not match the authenticated actor');

    expect(fn () => resolve(PreviewProof::class)->issue(new TestActor(''), $message))
        ->toThrow(InvalidMessageSnapshot::class, 'invalid identifier');
});

test('snapshot fingerprints fail closed for data that cannot be represented as JSON', function (): void {
    $message = previewProofMessage();
    $message->subject = "Invalid UTF-8 \xB1\x31";

    expect(fn () => resolve(MessageSnapshotFingerprint::class)->for($message))
        ->toThrow(InvalidMessageSnapshot::class, 'cannot be fingerprinted');
});

function previewProofMessage(): CloudflareMailMessage
{
    return CloudflareMailMessage::query()->create([
        'actor_type' => TestActor::class,
        'actor_id' => 'composer-1',
        'mailer' => 'array',
        'from_address' => 'sender@example.test',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Preview proof',
        'markdown_body' => 'Body',
        'html_body' => '<p>Body</p>',
        'text_body' => 'Body',
        'attachments' => [],
        'status' => MessageStatus::Draft,
    ]);
}
