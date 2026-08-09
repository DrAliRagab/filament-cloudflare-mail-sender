<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

use DrAliRagab\FilamentCloudflareMailSender\Support\RecipientAddressSet;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ComposedMessageData
{
    /**
     * @param  list<array{path: string, name?: string}>  $attachments
     */
    public function __construct(
        public ?Authenticatable $actor,
        public RecipientAddressSet $recipients,
        public string $subject,
        public string $markdown,
        public array $attachments,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
        public bool $useDeliveryOverrides = false,
    ) {}
}
