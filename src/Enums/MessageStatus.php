<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Enums;

enum MessageStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Sent => 'Accepted by Cloudflare',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
