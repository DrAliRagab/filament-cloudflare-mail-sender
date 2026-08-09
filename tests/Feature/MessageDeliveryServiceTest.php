<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MessageDispatchFailed;
use DrAliRagab\FilamentCloudflareMailSender\Jobs\SendComposedEmail;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;

beforeEach(function (): void {
    config()->set('mail.mailers.delivery-array', ['transport' => 'array']);
    config()->set('filament-cloudflare-mail-sender.queue.connection', 'sync');
    config()->set('filament-cloudflare-mail-sender.queue.name', 'cloudflare-mail');
});

test('it atomically queues a composed message and dispatches one configured job after the transaction', function (): void {
    Queue::fake();
    $transactionLevels = [];
    deliveryQueueFake()->beforePushing(function () use (&$transactionLevels): void {
        $transactionLevels[] = DB::transactionLevel();
    });

    $message = deliveryDraft();

    resolve(MessageDeliveryService::class)->queueComposed($message);

    $message->refresh();
    $token = $message->send_token;

    if (! is_string($token) || $token === '') {
        throw new LogicException('The queued message must have a non-empty send token.');
    }

    expect($message->status)->toBe(MessageStatus::Queued)
        ->and($message->queued_at)->not->toBeNull()
        ->and($transactionLevels)->toBe([0]);

    Queue::assertPushedOn('cloudflare-mail', SendComposedEmail::class, function (SendComposedEmail $job) use ($message, $token): bool {
        return $job->messageId === $message->id
            && $job->sendToken === $token
            && $job->connection === 'sync'
            && $job->afterCommit === true;
    });
});

test('it leaves the first immutable token and dispatch count unchanged on duplicate queue attempts', function (): void {
    Queue::fake();
    $message = deliveryDraft();
    $service = resolve(MessageDeliveryService::class);

    $service->queueComposed($message);
    $firstToken = $message->refresh()->send_token;
    config()->set('mail.mailers.delivery-array', ['transport' => 'cloudflare']);
    config()->set('services.cloudflare', []);
    $service->queueComposed($message);

    expect($message->refresh()->send_token)->toBe($firstToken)
        ->and($message->status)->toBe(MessageStatus::Queued);

    Queue::assertPushedTimes(SendComposedEmail::class, 1);
});

test('it fails before mutation when queueing runs inside a host transaction', function (): void {
    Queue::fake();
    $message = deliveryDraft();
    DB::beginTransaction();

    try {
        expect(fn (): CloudflareMailMessage => resolve(MessageDeliveryService::class)->queueComposed($message))
            ->toThrow(LogicException::class, 'outside an existing database transaction');
    } finally {
        DB::rollBack();
    }

    expect($message->refresh()->status)->toBe(MessageStatus::Draft)
        ->and($message->send_token)->toBeNull()
        ->and($message->queued_at)->toBeNull();
    Queue::assertNothingPushed();
});

test('it validates the authoritative locked row instead of a stale caller model', function (): void {
    Queue::fake();
    config()->set('mail.mailers.delivery-cloudflare', ['transport' => 'cloudflare']);
    config()->set('services.cloudflare', []);
    $staleCaller = deliveryDraft(['mailer' => 'delivery-cloudflare']);

    CloudflareMailMessage::query()->whereKey($staleCaller->id)->update(['mailer' => 'delivery-array']);

    resolve(MessageDeliveryService::class)->queueComposed($staleCaller);

    expect($staleCaller->refresh()->status)->toBe(MessageStatus::Queued)
        ->and($staleCaller->send_token)->not->toBeNull();
    Queue::assertPushed(SendComposedEmail::class);
});

test('it restores the same draft when the queue push fails', function (): void {
    Queue::fake();
    deliveryQueueFake()->beforePushing(static function (): never {
        throw new RuntimeException('The queue backend is unavailable.');
    });

    $message = deliveryDraft();

    expect(fn (): CloudflareMailMessage => resolve(MessageDeliveryService::class)->queueComposed($message))
        ->toThrow(MessageDispatchFailed::class, 'could not be added');

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Draft)
        ->and($message->send_token)->toBeNull()
        ->and($message->queued_at)->toBeNull();
});

test('it validates native Cloudflare credentials before queueing', function (): void {
    Queue::fake();
    config()->set('mail.mailers.delivery-cloudflare', ['transport' => 'cloudflare']);
    config()->set('services.cloudflare', []);

    $message = deliveryDraft(['mailer' => 'delivery-cloudflare']);

    expect(fn (): CloudflareMailMessage => resolve(MessageDeliveryService::class)->queueComposed($message))
        ->toThrow(InvalidMessageSnapshot::class, 'account ID and token');

    expect($message->refresh()->status)->toBe(MessageStatus::Draft)
        ->and($message->send_token)->toBeNull();

    Queue::assertNothingPushed();
});

test('it permits non-Cloudflare test transports without provider credentials', function (): void {
    Queue::fake();
    config()->set('services.cloudflare', []);

    $message = deliveryDraft();

    resolve(MessageDeliveryService::class)->queueComposed($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendComposedEmail::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function deliveryDraft(array $overrides = []): CloudflareMailMessage
{
    return CloudflareMailMessage::query()->create(array_merge([
        'mailer' => 'delivery-array',
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender',
        'reply_to_address' => null,
        'reply_to_name' => null,
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Queue this message',
        'markdown_body' => 'Hello',
        'html_body' => '<p>Hello</p>',
        'text_body' => 'Hello',
        'attachments' => [],
        'status' => MessageStatus::Draft,
    ], $overrides));
}

function deliveryQueueFake(): QueueFake
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new LogicException('The delivery service test requires Laravel\'s queue fake.');
    }

    return $queue;
}
