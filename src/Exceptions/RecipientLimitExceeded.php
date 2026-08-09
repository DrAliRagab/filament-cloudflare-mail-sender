<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Exceptions;

use DomainException;

final class RecipientLimitExceeded extends DomainException
{
    public function __construct(
        public readonly int $count,
        public readonly int $limit = 50,
    ) {
        parent::__construct("Cloudflare Email Sending accepts at most {$limit} unique recipients; {$count} were provided.");
    }
}
