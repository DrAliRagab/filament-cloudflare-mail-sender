<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Jobs\SendComposedEmail;
use DrAliRagab\FilamentCloudflareMailSender\Mail\ComposedMessage;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\AuditBodyStoragePolicy;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

beforeEach(function (): void {
    Storage::fake('job-attachments');
    Storage::disk('job-attachments')->put('temporary/invoice.pdf', "%PDF-1.4\nInvoice");

    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'job-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'temporary');
    config()->set('filament-cloudflare-mail-sender.audit.store_body', false);
    config()->set('mail.mailers.job-array', ['transport' => 'array']);
    Mail::purge('job-array');
});

test('it uses the configured retry policy', function (): void {
    config()->set('filament-cloudflare-mail-sender.queue.tries', 3);
    config()->set('filament-cloudflare-mail-sender.queue.backoff', [60, 300, 900]);

    $job = new SendComposedEmail('message-id', 'send-token');

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

test('it sends the trusted snapshot once and records Cloudflare acceptance semantics locally', function (): void {
    Mail::fake();
    $message = queuedJobMessage();
    $job = new SendComposedEmail($message->id, (string) $message->send_token);

    handleDeliveryJob($job);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Sent)
        ->and($message->attempt_count)->toBe(1)
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->failure_class)->toBeNull()
        ->and(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeFalse()
        ->and($message->markdown_body)->toBeNull()
        ->and($message->html_body)->toBeNull()
        ->and($message->text_body)->toBeNull();

    Mail::assertSent(ComposedMessage::class, function (ComposedMessage $mail): bool {
        return $mail->usesMailer('job-array')
            && $mail->hasTo('recipient@example.test')
            && $mail->hasCc('copy@example.test')
            && $mail->hasBcc('hidden@example.test')
            && $mail->hasAttachment(
                Attachment::fromStorageDisk('job-attachments', 'temporary/invoice.pdf')
                    ->as('audited-invoice.pdf')
                    ->withMime('application/pdf'),
            );
    });

    handleDeliveryJob($job);

    expect($message->refresh()->attempt_count)->toBe(1);
    Mail::assertSentTimes(ComposedMessage::class, 1);
});

test('it persists a retryable attempt and rethrows without premature cleanup', function (): void {
    installThrowingMailTransport('job-throwing', 'Transient provider failure.');
    $message = queuedJobMessage(['mailer' => 'job-throwing']);
    $job = new SendComposedEmail($message->id, (string) $message->send_token);

    expect(fn () => handleDeliveryJob($job))
        ->toThrow(RuntimeException::class, 'Transient provider failure');

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Sending)
        ->and($message->attempt_count)->toBe(1)
        ->and($message->sent_at)->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeTrue()
        ->and($message->html_body)->toBe('<p>Hello <strong>friend</strong></p>');

    config()->set('mail.mailers.job-throwing', ['transport' => 'array']);
    Mail::purge('job-throwing');

    handleDeliveryJob($job);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->attempt_count)->toBe(2);
});

test('it revalidates attachment metadata immediately before Mail is called', function (): void {
    Mail::fake();
    $message = queuedJobMessage();
    Storage::disk('job-attachments')->put('temporary/invoice.pdf', "%PDF-1.4\nChanged invoice content");

    expect(fn () => handleDeliveryJob(new SendComposedEmail($message->id, (string) $message->send_token)))
        ->toThrow(InvalidAttachment::class, 'changed');

    expect($message->refresh()->status)->toBe(MessageStatus::Sending)
        ->and($message->attempt_count)->toBe(1)
        ->and(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeTrue();
    Mail::assertNothingSent();
});

test('it terminally fails the same token with sanitized bounded details and cleanup', function (): void {
    $secret = 'cf-secret-token-value';
    $longTail = str_repeat('x', 3000);
    config()->set('services.cloudflare.token', $secret);
    installThrowingMailTransport(
        'job-terminal',
        "Authorization: Bearer exposed-bearer; token={$secret}; {$longTail}",
    );

    $message = queuedJobMessage(['mailer' => 'job-terminal']);
    $job = new SendComposedEmail($message->id, (string) $message->send_token);
    $exception = null;

    try {
        handleDeliveryJob($job);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class);

    $job->failed($exception);
    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempt_count)->toBe(1)
        ->and($message->failed_at)->not->toBeNull()
        ->and($message->failure_class)->toBe(RuntimeException::class)
        ->and($message->failure_message)->not->toContain($secret)
        ->and($message->failure_message)->not->toContain('exposed-bearer')
        ->and($message->failure_message)->toContain('[REDACTED]')
        ->and(strlen((string) $message->failure_message))->toBeLessThanOrEqual(2000)
        ->and(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeFalse()
        ->and($message->html_body)->toBeNull();
});

test('it ignores a stale token without sending or changing lifecycle state', function (): void {
    Mail::fake();
    $message = queuedJobMessage();

    handleDeliveryJob(new SendComposedEmail($message->id, (string) Str::uuid()));

    expect($message->refresh()->status)->toBe(MessageStatus::Queued)
        ->and($message->attempt_count)->toBe(0)
        ->and(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeTrue();
    Mail::assertNothingSent();
});

test('it retries cleanup for a terminal same-token job without resending', function (): void {
    Mail::fake();
    $message = queuedJobMessage(['status' => MessageStatus::Sent, 'sent_at' => now()]);

    handleDeliveryJob(new SendComposedEmail($message->id, (string) $message->send_token));

    expect(Storage::disk('job-attachments')->exists('temporary/invoice.pdf'))->toBeFalse()
        ->and($message->refresh()->html_body)->toBeNull()
        ->and($message->attempt_count)->toBe(0);
    Mail::assertNothingSent();
});

test('it throws on a false delete result and retries terminal cleanup without resending', function (): void {
    Mail::fake();
    $message = queuedJobMessage(['status' => MessageStatus::Sent, 'sent_at' => now()]);
    $job = new SendComposedEmail($message->id, (string) $message->send_token);
    $realDisk = Storage::disk('job-attachments');
    $filesystems = resolve(FilesystemManager::class);
    $filesystems->set('job-attachments', new class($realDisk->getDriver(), $realDisk->getAdapter(), $realDisk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    });

    expect(fn () => handleDeliveryJob($job))
        ->toThrow(RuntimeException::class, 'cleanup failed');

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->html_body)->toBe('<p>Hello <strong>friend</strong></p>')
        ->and($realDisk->exists('temporary/invoice.pdf'))->toBeTrue();
    Mail::assertNothingSent();

    $filesystems->set('job-attachments', $realDisk);
    handleDeliveryJob($job);

    expect($realDisk->exists('temporary/invoice.pdf'))->toBeFalse()
        ->and($message->refresh()->html_body)->toBeNull()
        ->and($message->attempt_count)->toBe(0);
    Mail::assertNothingSent();
});

test('a recovered sending record may resubmit after provider acceptance before the prior local commit', function (): void {
    Mail::fake();
    $message = queuedJobMessage(['status' => MessageStatus::Sending]);

    // If Cloudflare accepted the previous worker's request immediately before that worker crashed,
    // the persisted `sending` state cannot prove acceptance. Recovery must resubmit the same token.
    handleDeliveryJob(new SendComposedEmail($message->id, (string) $message->send_token));

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->attempt_count)->toBe(1);
    Mail::assertSentTimes(ComposedMessage::class, 1);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function queuedJobMessage(array $overrides = []): CloudflareMailMessage
{
    return CloudflareMailMessage::query()->create(array_merge([
        'mailer' => 'job-array',
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender',
        'reply_to_address' => 'reply@example.test',
        'reply_to_name' => 'Reply Desk',
        'to' => ['recipient@example.test'],
        'cc' => ['copy@example.test'],
        'bcc' => ['hidden@example.test'],
        'subject' => 'Trusted snapshot',
        'markdown_body' => 'Hello **friend**',
        'html_body' => '<p>Hello <strong>friend</strong></p>',
        'text_body' => 'Hello friend',
        'attachments' => [[
            'disk' => 'job-attachments',
            'path' => 'temporary/invoice.pdf',
            'name' => 'audited-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen("%PDF-1.4\nInvoice"),
            'sha256' => hash('sha256', "%PDF-1.4\nInvoice"),
        ]],
        'status' => MessageStatus::Queued,
        'attempt_count' => 0,
        'send_token' => (string) Str::uuid(),
        'queued_at' => now(),
    ], $overrides));
}

function handleDeliveryJob(SendComposedEmail $job): void
{
    $job->handle(
        resolve(AttachmentPolicy::class),
        resolve(AuditBodyStoragePolicy::class),
    );
}

function installThrowingMailTransport(string $mailer, string $message): void
{
    config()->set("mail.mailers.{$mailer}", ['transport' => 'delivery-throwing']);

    /** @var MailManager $manager */
    $manager = resolve('mail.manager');
    $manager->extend('delivery-throwing', static fn (array $configuration): TransportInterface => new class($message) implements TransportInterface
    {
        public function __construct(private readonly string $exceptionMessage) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new RuntimeException($this->exceptionMessage);
        }

        public function __toString(): string
        {
            return 'delivery-throwing';
        }
    });
    Mail::purge($mailer);
}
