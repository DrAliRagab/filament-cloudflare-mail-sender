<?php

declare(strict_types=1);

$defaultGuard = config('auth.defaults.guard');
$guard = is_string($defaultGuard) ? $defaultGuard : null;
$provider = is_string($guard) ? config("auth.guards.{$guard}.provider") : null;
$recipientModel = is_string($provider) ? config("auth.providers.{$provider}.model") : null;
$configuredFromAddress = config('mail.from.address');
$configuredFromDomain = is_string($configuredFromAddress) && str_contains($configuredFromAddress, '@')
    ? strtolower((string) substr(strrchr($configuredFromAddress, '@') ?: '', 1))
    : null;

return [
    'mailer' => 'cloudflare',

    'from' => [
        'address' => config('mail.from.address'),
        'name' => config('mail.from.name'),
        'editable' => false,
        'allowed_domains' => $configuredFromDomain === null ? [] : [$configuredFromDomain],
    ],

    'reply_to' => [
        'address' => null,
        'name' => null,
    ],

    'recipients' => [
        'model' => $recipientModel,
        'email_column' => 'email',
        'name_column' => 'name',
        'suggestion_limit' => 250,
    ],

    'queue' => [
        'connection' => config('queue.default'),
        'name' => 'default',
        'tries' => 3,
        'backoff' => [60, 300, 900],
    ],

    'attachments' => [
        'enabled' => true,
        'disk' => config('filesystems.default'),
        'directory' => 'filament-cloudflare-mail-sender/attachments',
        'max_file_kib' => 4096,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/csv',
            'text/plain',
        ],
    ],

    'message' => [
        'max_total_kib' => 5120,
    ],

    'audit' => [
        'store_body' => true,
        'retention_days' => null,
    ],

    'navigation' => [
        'compose_label' => 'Compose Email',
        'messages_label' => 'Sent Emails',
        'group' => 'Email',
        'icon' => 'heroicon-o-envelope',
        'sort' => null,
    ],
];
