<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Jobs;

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Data\MessageAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Data\RenderedMessageBody;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Mail\ComposedMessage;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\AuditBodyStoragePolicy;
use DrAliRagab\FilamentCloudflareMailSender\Support\FailureSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SendComposedEmail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * @var list<int>
     */
    private array $retryBackoff;

    public function __construct(
        public readonly string $messageId,
        public readonly string $sendToken,
    ) {
        $this->tries = $this->configuredTries();
        $this->retryBackoff = $this->configuredBackoff();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->retryBackoff;
    }

    public function handle(
        AttachmentPolicy $attachmentPolicy,
        AuditBodyStoragePolicy $auditBodyStoragePolicy,
    ): void {
        $failure = null;
        $terminalMessage = null;

        DB::transaction(function () use ($attachmentPolicy, &$failure, &$terminalMessage): void {
            $message = CloudflareMailMessage::query()
                ->whereKey($this->messageId)
                ->lockForUpdate()
                ->first();

            if (! $message instanceof CloudflareMailMessage || $message->send_token !== $this->sendToken) {
                return;
            }

            if ($this->isTerminal($message->status)) {
                $terminalMessage = $message;

                return;
            }

            if (! in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true)) {
                return;
            }

            $message->forceFill([
                'status' => MessageStatus::Sending,
                'attempt_count' => $message->attempt_count + 1,
            ])->save();

            try {
                $attachments = $this->attachmentsFromSnapshot($message);
                $attachmentPolicy->revalidate(
                    $attachments,
                    new RenderedMessageBody(
                        html: $message->html_body ?? '',
                        text: $message->text_body ?? '',
                    ),
                );

                Mail::mailer($message->mailer)
                    ->to($message->to)
                    ->cc($message->cc)
                    ->bcc($message->bcc)
                    ->send(new ComposedMessage($message));
            } catch (Throwable $exception) {
                $failure = $exception;

                return;
            }

            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => Date::now(),
                'failure_class' => null,
                'failure_message' => null,
                'failed_at' => null,
            ])->save();
            $terminalMessage = $message;
        });

        if ($terminalMessage instanceof CloudflareMailMessage) {
            $this->cleanupTerminalMessage($terminalMessage, $auditBodyStoragePolicy);
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $sanitized = resolve(FailureSanitizer::class)->sanitize($exception);
        $terminalMessage = null;

        DB::transaction(function () use ($sanitized, &$terminalMessage): void {
            $message = CloudflareMailMessage::query()
                ->whereKey($this->messageId)
                ->lockForUpdate()
                ->first();

            if (! $message instanceof CloudflareMailMessage || $message->send_token !== $this->sendToken || $message->status === MessageStatus::Sent) {
                return;
            }

            if (in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true)) {
                $message->forceFill([
                    'status' => MessageStatus::Failed,
                    'failed_at' => Date::now(),
                    'failure_class' => $sanitized['class'],
                    'failure_message' => $sanitized['message'],
                ])->save();
            }

            if ($this->isTerminal($message->status)) {
                $terminalMessage = $message;
            }
        });

        if ($terminalMessage instanceof CloudflareMailMessage) {
            $this->cleanupTerminalMessage($terminalMessage, resolve(AuditBodyStoragePolicy::class));
        }
    }

    /**
     * @return list<MessageAttachment>
     */
    private function attachmentsFromSnapshot(CloudflareMailMessage $message): array
    {
        return array_map(
            static fn (array $metadata): MessageAttachment => new MessageAttachment(
                disk: $metadata['disk'],
                path: $metadata['path'],
                name: $metadata['name'],
                mimeType: $metadata['mime_type'],
                byteSize: $metadata['size'],
                sha256: $metadata['sha256'],
            ),
            $message->attachments,
        );
    }

    private function cleanupTerminalMessage(
        CloudflareMailMessage $message,
        AuditBodyStoragePolicy $auditBodyStoragePolicy,
    ): void {
        foreach ($this->attachmentsFromSnapshot($message) as $attachment) {
            if (! Storage::disk($attachment->disk)->delete($attachment->path)) {
                throw new RuntimeException('Temporary attachment cleanup failed.');
            }
        }

        $auditBodyStoragePolicy->scrubTerminalBodies($message);
    }

    private function isTerminal(MessageStatus $status): bool
    {
        return in_array($status, [
            MessageStatus::Sent,
            MessageStatus::Failed,
            MessageStatus::Cancelled,
        ], true);
    }

    private function configuredTries(): int
    {
        $tries = config('filament-cloudflare-mail-sender.queue.tries', 3);

        return is_int($tries) && $tries > 0 ? $tries : 3;
    }

    /**
     * @return list<int>
     */
    private function configuredBackoff(): array
    {
        $backoff = config('filament-cloudflare-mail-sender.queue.backoff', [60, 300, 900]);

        if (! is_array($backoff)) {
            return [60, 300, 900];
        }

        $backoff = array_values(array_filter($backoff, static fn (mixed $seconds): bool => is_int($seconds) && $seconds >= 0));

        return $backoff === [] ? [60, 300, 900] : $backoff;
    }
}
