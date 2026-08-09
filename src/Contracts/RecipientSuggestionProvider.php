<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Contracts;

use DrAliRagab\FilamentCloudflareMailSender\Data\RecipientSuggestion;

interface RecipientSuggestionProvider
{
    /**
     * @return list<RecipientSuggestion>
     */
    public function suggest(string $search = ''): array;
}
