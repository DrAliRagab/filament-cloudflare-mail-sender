<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

final class TestActor implements Authenticatable
{
    public function __construct(private readonly string $identifier) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->identifier;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
