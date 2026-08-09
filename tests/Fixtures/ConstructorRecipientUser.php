<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class ConstructorRecipientUser extends Model
{
    public function __construct(public string $required)
    {
        parent::__construct();
    }
}
