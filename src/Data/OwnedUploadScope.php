<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

final readonly class OwnedUploadScope
{
    public function __construct(
        public string $proof,
        public string $draftId,
        public string $directory,
    ) {}
}
