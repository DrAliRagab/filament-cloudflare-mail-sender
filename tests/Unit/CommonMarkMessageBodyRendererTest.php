<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Rendering\CommonMarkMessageBodyRenderer;

test('it renders HTML and readable plain text from one Markdown body', function (): void {
    $rendered = (new CommonMarkMessageBodyRenderer)->render(<<<'MARKDOWN'
# Account update

Hello **friend**.

[View your account](https://example.test/account)
MARKDOWN);

    expect($rendered->html)->toContain('<h1>Account update</h1>')
        ->and($rendered->html)->toContain('<strong>friend</strong>')
        ->and($rendered->html)->toContain('href="https://example.test/account"')
        ->and($rendered->text)->toBe("Account update\n\nHello friend.\n\nView your account");
});

test('it strips raw HTML and unsafe links from rendered output', function (): void {
    $rendered = (new CommonMarkMessageBodyRenderer)->render(<<<'MARKDOWN'
<script>alert('unsafe')</script>

[Do not follow](javascript:alert('unsafe'))
MARKDOWN);

    expect($rendered->html)->not->toContain('<script>')
        ->and($rendered->html)->not->toContain('href="javascript:')
        ->and($rendered->text)->not->toContain('<script>')
        ->and($rendered->text)->toContain('Do not follow');
});
