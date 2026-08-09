<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Exceptions;

use DomainException;

final class MissingToRecipient extends DomainException
{
    public function __construct()
    {
        parent::__construct('At least one To recipient is required.');
    }

    public function field(): string
    {
        return 'to';
    }
}
