<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;

final class MessageStatusTransitions
{
    public static function canTransition(MessageStatus $from, MessageStatus $to): bool
    {
        return match ($from) {
            MessageStatus::Draft => in_array($to, [MessageStatus::Queued, MessageStatus::Cancelled], true),
            MessageStatus::Queued => in_array($to, [MessageStatus::Sending, MessageStatus::Cancelled], true),
            MessageStatus::Sending => in_array($to, [MessageStatus::Sent, MessageStatus::Failed], true),
            MessageStatus::Sent, MessageStatus::Failed, MessageStatus::Cancelled => false,
        };
    }
}
