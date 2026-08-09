<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use JsonException;

final readonly class MessageSnapshotFingerprint
{
    public function for(CloudflareMailMessage $message): string
    {
        try {
            return hash('sha256', json_encode([
                'mailer' => $message->mailer,
                'from' => [$message->from_address, $message->from_name],
                'reply_to' => [$message->reply_to_address, $message->reply_to_name],
                'to' => $message->to,
                'cc' => $message->cc,
                'bcc' => $message->bcc,
                'subject' => $message->subject,
                'markdown' => $message->markdown_body,
                'html' => $message->html_body,
                'text' => $message->text_body,
                'attachments' => $message->attachments,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InvalidMessageSnapshot('The message snapshot cannot be fingerprinted.', previous: $exception);
        }
    }
}
