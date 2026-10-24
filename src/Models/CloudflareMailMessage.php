<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Models;

use Carbon\CarbonInterface;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $mailer
 * @property string $from_address
 * @property string|null $from_name
 * @property string|null $reply_to_address
 * @property string|null $reply_to_name
 * @property array<int, string> $to
 * @property array<int, string> $cc
 * @property array<int, string> $bcc
 * @property string $subject
 * @property string|null $markdown_body
 * @property string|null $html_body
 * @property string|null $text_body
 * @property list<array{disk: string, path: string, name: string, mime_type: string, size: int, sha256: string}> $attachments
 * @property MessageStatus $status
 * @property int $attempt_count
 * @property string|null $send_token
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $failure_class
 * @property string|null $failure_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class CloudflareMailMessage extends Model
{
    use HasUlids;

    /**
     * @var string
     */
    protected $table = 'cloudflare_mail_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_type',
        'actor_id',
        'mailer',
        'from_address',
        'from_name',
        'reply_to_address',
        'reply_to_name',
        'to',
        'cc',
        'bcc',
        'subject',
        'markdown_body',
        'html_body',
        'text_body',
        'attachments',
        'status',
        'attempt_count',
        'send_token',
        'queued_at',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'failure_class',
        'failure_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'status' => MessageStatus::class,
            'attempt_count' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $message): void {
            if ($message->isDirty('send_token') && $message->getOriginal('send_token') !== null) {
                throw new LogicException('The send token is immutable after it has been assigned.');
            }
        });
    }
}
