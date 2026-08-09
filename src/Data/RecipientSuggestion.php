<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

final readonly class RecipientSuggestion
{
    public function __construct(
        public string $email,
        public string $label,
    ) {}
}
