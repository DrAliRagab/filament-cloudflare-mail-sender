<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class UuidRecipientUser extends Model
{
    protected $table = 'uuid_recipient_users';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
