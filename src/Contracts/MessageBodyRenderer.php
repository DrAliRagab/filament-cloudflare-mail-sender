<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Contracts;

use DrAliRagab\FilamentCloudflareMailSender\Data\RenderedMessageBody;

interface MessageBodyRenderer
{
    public function render(string $markdown): RenderedMessageBody;
}
