<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

test('it persists immutable package-owned mail audit snapshots with a ULID', function (): void {
    $queuedAt = Date::parse('2026-08-09 12:00:00');
    $sendToken = (string) Str::uuid();

    $message = CloudflareMailMessage::query()->create([
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '0189b581-4ef0-7d94-9c1a-6df0dbfd6c7f',
        'mailer' => 'cloudflare',
        'from_address' => 'sender@example.test',
        'from_name' => 'Package Sender',
        'reply_to_address' => 'replies@example.test',
        'reply_to_name' => 'Reply Desk',
        'to' => ['first@example.test'],
        'cc' => ['copy@example.test'],
        'bcc' => ['hidden@example.test'],
        'subject' => 'Your account update',
        'markdown_body' => 'Hello **there**',
        'html_body' => '<p>Hello <strong>there</strong></p>',
        'text_body' => 'Hello there',
        'attachments' => [[
            'disk' => 'local',
            'path' => 'temporary/account.pdf',
            'name' => 'account.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]],
        'status' => MessageStatus::Queued,
        'send_token' => $sendToken,
        'queued_at' => $queuedAt,
    ]);

    $message = CloudflareMailMessage::query()
        ->whereKey($message->getKey())
        ->firstOrFail();

    expect($message->getTable())->toBe('cloudflare_mail_messages')
        ->and($message->id)->toBeString()->toHaveLength(26)
        ->and(Str::isUlid($message->id))->toBeTrue()
        ->and($message->actor_type)->toBe('App\\Models\\User')
        ->and($message->actor_id)->toBe('0189b581-4ef0-7d94-9c1a-6df0dbfd6c7f')
        ->and($message->to)->toBe(['first@example.test'])
        ->and($message->cc)->toBe(['copy@example.test'])
        ->and($message->bcc)->toBe(['hidden@example.test'])
        ->and($message->attachments)->toBe([[
            'disk' => 'local',
            'path' => 'temporary/account.pdf',
            'name' => 'account.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]])
        ->and($message->status)->toBe(MessageStatus::Queued)
        ->and($message->queued_at)->toEqual($queuedAt)
        ->and($message->created_at)->toBeInstanceOf(Carbon::class)
        ->and($message->updated_at)->toBeInstanceOf(Carbon::class);
});

test('it prevents an assigned send token from being changed', function (): void {
    $message = CloudflareMailMessage::query()->create(messageAttributes());
    $message->send_token = (string) Str::uuid();

    expect(fn (): bool => $message->save())
        ->toThrow(LogicException::class, 'immutable');
});

test('it enforces unique send tokens', function (): void {
    $sendToken = (string) Str::uuid();

    CloudflareMailMessage::query()->create(messageAttributes(['send_token' => $sendToken]));

    expect(fn () => CloudflareMailMessage::query()->create(messageAttributes(['send_token' => $sendToken])))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('it exposes the approved queue and audit defaults', function (): void {
    expect(config('filament-cloudflare-mail-sender.queue'))->toBe([
        'connection' => null,
        'name' => 'default',
        'tries' => 3,
        'backoff' => [60, 300, 900],
    ])
        ->and(config('filament-cloudflare-mail-sender.audit'))->toBe([
            'store_body' => true,
            'retention_days' => null,
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function messageAttributes(array $overrides = []): array
{
    return array_merge([
        'mailer' => 'cloudflare',
        'from_address' => 'sender@example.test',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'A message',
        'attachments' => [],
        'status' => MessageStatus::Draft,
        'send_token' => (string) Str::uuid(),
    ], $overrides);
}
