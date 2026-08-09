<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class IntegerRecipientUser extends Model
{
    protected $table = 'integer_recipient_users';

    protected $guarded = [];

    public $timestamps = false;
}
