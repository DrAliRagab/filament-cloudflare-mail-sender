<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Exceptions;

use DomainException;

final class InvalidRecipientAddress extends DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly string $address,
    ) {
        parent::__construct("The {$field} recipient [{$address}] must be a valid email address.");
    }
}
