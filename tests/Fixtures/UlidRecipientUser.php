<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class UlidRecipientUser extends Model
{
    protected $table = 'ulid_recipient_users';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
