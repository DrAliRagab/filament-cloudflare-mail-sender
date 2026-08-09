<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MessageDispatchFailed;
use DrAliRagab\FilamentCloudflareMailSender\Jobs\SendComposedEmail;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class MessageDeliveryService
{
    public function __construct(
        private DatabaseManager $database,
        private Dispatcher $dispatcher,
    ) {}

    public function queueComposed(CloudflareMailMessage $message): CloudflareMailMessage
    {
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new LogicException('Email queueing must run outside an existing database transaction.');
        }

        $shouldDispatch = false;
        $connection = null;
        $queue = null;

        $lockedMessage = $this->database->transaction(function () use ($message, &$shouldDispatch, &$connection, &$queue): CloudflareMailMessage {
            $locked = CloudflareMailMessage::query()
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== MessageStatus::Draft || $locked->send_token !== null) {
                return $locked;
            }

            $this->validateMailerConfiguration($locked->mailer);
            [$connection, $queue] = $this->queueConfiguration();
            $locked->forceFill([
                'send_token' => (string) Str::uuid(),
                'status' => MessageStatus::Queued,
                'queued_at' => Date::now(),
            ])->save();
            $shouldDispatch = true;

            return $locked;
        });

        if (! $shouldDispatch) {
            return $lockedMessage;
        }

        if (! is_string($connection) || ! is_string($queue)) {
            throw new InvalidMessageSnapshot('The queued message is missing its queue configuration.');
        }

        $token = $lockedMessage->send_token;

        if ($token === null) {
            throw new InvalidMessageSnapshot('The queued message is missing its send token.');
        }

        $job = (new SendComposedEmail($lockedMessage->id, $token))
            ->onConnection($connection)
            ->onQueue($queue)
            ->afterCommit();

        try {
            $this->dispatcher->dispatch($job);
        } catch (Throwable $exception) {
            $this->restoreDraftAfterDispatchFailure($lockedMessage->id, $token);

            throw new MessageDispatchFailed('The email could not be added to the queue. Try again.', previous: $exception);
        }

        return $lockedMessage;
    }

    private function validateMailerConfiguration(string $mailer): void
    {
        $mailerConfiguration = config("mail.mailers.{$mailer}");

        if (! is_array($mailerConfiguration) || ($mailerConfiguration['transport'] ?? null) !== 'cloudflare') {
            return;
        }

        $accountId = $mailerConfiguration['account_id'] ?? config('services.cloudflare.account_id');
        $token = $mailerConfiguration['token']
            ?? $mailerConfiguration['key']
            ?? config('services.cloudflare.token', config('services.cloudflare.key'));

        if (! is_string($accountId) || trim($accountId) === '' || ! is_string($token) || trim($token) === '') {
            throw new InvalidMessageSnapshot('The Cloudflare mail transport requires an account ID and token.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function queueConfiguration(): array
    {
        $connection = config('filament-cloudflare-mail-sender.queue.connection');

        if (! is_string($connection) || $connection === '') {
            if ($connection !== null && $connection !== '') {
                throw new InvalidMessageSnapshot('The configured queue connection and name are invalid.');
            }

            $connection = config('queue.default');
        }

        $queue = config('filament-cloudflare-mail-sender.queue.name');

        if (! is_string($connection) || $connection === '' || ! is_string($queue) || $queue === '') {
            throw new InvalidMessageSnapshot('The configured queue connection and name are invalid.');
        }

        return [$connection, $queue];
    }

    private function restoreDraftAfterDispatchFailure(string $messageId, string $sendToken): void
    {
        $this->database->transaction(static function () use ($messageId, $sendToken): void {
            $locked = CloudflareMailMessage::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== MessageStatus::Queued || $locked->send_token !== $sendToken) {
                return;
            }

            CloudflareMailMessage::query()
                ->whereKey($messageId)
                ->where('status', MessageStatus::Queued->value)
                ->where('send_token', $sendToken)
                ->update([
                    'status' => MessageStatus::Draft->value,
                    'send_token' => null,
                    'queued_at' => null,
                    'updated_at' => Date::now(),
                ]);
        });
    }
}
