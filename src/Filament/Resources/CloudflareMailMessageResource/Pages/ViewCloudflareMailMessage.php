<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages;

use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewCloudflareMailMessage extends ViewRecord
{
    protected static string $resource = CloudflareMailMessageResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [CloudflareMailMessageResource::duplicateAction()];
    }
}
