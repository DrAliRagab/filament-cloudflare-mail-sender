<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

final readonly class VerifiedPreviewProof
{
    public function __construct(
        public string $draftId,
        public string $fingerprint,
    ) {}
}
