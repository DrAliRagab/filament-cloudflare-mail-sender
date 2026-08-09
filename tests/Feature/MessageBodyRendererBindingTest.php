<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Contracts\MessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Rendering\CommonMarkMessageBodyRenderer;

test('the package resolves one renderer contract for preview and delivery', function (): void {
    expect(resolve(MessageBodyRenderer::class))->toBeInstanceOf(CommonMarkMessageBodyRenderer::class);
});
