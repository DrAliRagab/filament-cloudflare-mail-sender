<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Data;

final readonly class MessageAttachment
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $name,
        public string $mimeType,
        public int $byteSize,
        public string $sha256,
    ) {}

    /**
     * @return array{disk: string, path: string, name: string, mime_type: string, size: int, sha256: string}
     */
    public function toAuditMetadata(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->byteSize,
            'sha256' => $this->sha256,
        ];
    }
}
