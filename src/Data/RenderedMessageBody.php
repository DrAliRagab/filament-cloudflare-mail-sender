<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

final readonly class RenderedMessageBody
{
    public function __construct(
        public string $html,
        public string $text,
    ) {}
}
