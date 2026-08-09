<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

test('quality gates include the package migration stub in their analysis scope', function (): void {
    $files = new Filesystem;
    $packageRoot = dirname(__DIR__, 2);

    $phpstan = $files->get($packageRoot.'/phpstan.neon');
    $rector = $files->get($packageRoot.'/rector.php');

    expect($phpstan)
        ->toContain('database/migrations/create_cloudflare_mail_messages_table.php.stub')
        ->toContain('fileExtensions:')
        ->toContain('- stub')
        ->and($rector)
        ->toContain("__DIR__.'/database/migrations/create_cloudflare_mail_messages_table.php.stub'")
        ->toContain("->withFileExtensions(['php', 'stub'])");
});
