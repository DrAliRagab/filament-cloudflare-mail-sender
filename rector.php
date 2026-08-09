<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/database/migrations/create_cloudflare_mail_messages_table.php.stub',
        __DIR__.'/tests',
    ])
    ->withFileExtensions(['php', 'stub'])
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withoutParallel();
