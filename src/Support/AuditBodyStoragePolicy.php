<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;

final readonly class AuditBodyStoragePolicy
{
    public function __construct(private bool $storeBodies) {}

    public static function fromConfiguration(): self
    {
        return new self(config('filament-cloudflare-mail-sender.audit.store_body', true) === true);
    }

    public function scrubTerminalBodies(CloudflareMailMessage $message): bool
    {
        if ($this->storeBodies || ! $this->isTerminal($message->status)) {
            return false;
        }

        $message->forceFill([
            'markdown_body' => null,
            'html_body' => null,
            'text_body' => null,
        ])->save();

        return true;
    }

    private function isTerminal(MessageStatus $status): bool
    {
        return in_array($status, [
            MessageStatus::Sent,
            MessageStatus::Failed,
            MessageStatus::Cancelled,
        ], true);
    }
}
