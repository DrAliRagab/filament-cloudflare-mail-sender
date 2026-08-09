<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages;

use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource;
use Filament\Resources\Pages\ListRecords;

final class ListCloudflareMailMessages extends ListRecords
{
    protected static string $resource = CloudflareMailMessageResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(CloudflareMailMessageResource::canViewAny(), 403);
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }
}
