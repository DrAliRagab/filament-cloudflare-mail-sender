<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Attachments;

use DrAliRagab\FilamentCloudflareMailSender\Data\MessageAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Data\RenderedMessageBody;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final readonly class AttachmentPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_EXTENSION_MIME_TYPES = [
        'csv' => ['text/csv', 'text/plain'],
        'jpeg' => ['image/jpeg'],
        'jpg' => ['image/jpeg'],
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'txt' => ['text/plain'],
        'webp' => ['image/webp'],
    ];

    /**
     * @var list<string>
     */
    private const DANGEROUS_EXTENSIONS = [
        '7z', 'apk', 'awk', 'bash', 'bat', 'bz2', 'cjs', 'cmd', 'command', 'com', 'dll', 'exe', 'fish', 'groovy',
        'gz', 'htm', 'html', 'jar', 'js', 'kts', 'lua', 'mjs', 'msi', 'php', 'phar', 'pl', 'ps1', 'psd1', 'psm1',
        'pssc', 'py', 'r', 'rar', 'rb', 'sed', 'sh', 'svg', 'tar', 'tcl', 'tgz', 'vbs', 'xhtml', 'xml', 'xz', 'zip',
        'zsh',
    ];

    /**
     * @var list<string>
     */
    private const DANGEROUS_MIME_TYPES = [
        'application/gzip', 'application/java-archive', 'application/javascript', 'application/json',
        'application/vnd.android.package-archive', 'application/vnd.microsoft.portable-executable',
        'application/x-7z-compressed', 'application/x-bzip2', 'application/x-dosexec', 'application/x-httpd-php',
        'application/x-javascript', 'application/x-msdownload', 'application/x-rar-compressed', 'application/x-sh',
        'application/x-tar', 'application/xhtml+xml', 'application/xml', 'application/zip', 'image/svg+xml',
        'text/html', 'text/javascript', 'text/vbscript', 'text/x-php', 'text/xml',
    ];

    /**
     * @param  list<string>  $allowedMimeTypes
     */
    public function __construct(
        private string $disk,
        private string $directory,
        private int $maxFileBytes,
        private int $maxTotalBytes,
        private array $allowedMimeTypes,
        private FilesystemAdapter $storage,
    ) {}

    public static function fromConfiguration(): self
    {
        $attachments = config('filament-cloudflare-mail-sender.attachments', []);
        $message = config('filament-cloudflare-mail-sender.message', []);
        $attachments = is_array($attachments) ? $attachments : [];
        $message = is_array($message) ? $message : [];

        $disk = $attachments['disk'] ?? config('filesystems.default');
        $maxFileKib = $attachments['max_file_kib'] ?? 4096;
        $maxTotalKib = $message['max_total_kib'] ?? 5120;
        $allowedMimeTypes = $attachments['allowed_mime_types'] ?? [];
        $directory = $attachments['directory'] ?? null;

        if (! is_string($disk) || $disk === '' || ! is_string($directory) || ! self::isSafeDirectory($directory)
            || ! is_int($maxFileKib) || ! is_int($maxTotalKib) || ! is_array($allowedMimeTypes)) {
            throw new InvalidAttachment('The attachment policy configuration is invalid.');
        }

        /** @var list<string> $allowedMimeTypes */
        $allowedMimeTypes = array_values(array_filter($allowedMimeTypes, is_string(...)));
        $storage = Storage::disk($disk);

        return new self(
            disk: $disk,
            directory: trim($directory, '/'),
            maxFileBytes: $maxFileKib * 1024,
            maxTotalBytes: $maxTotalKib * 1024,
            allowedMimeTypes: $allowedMimeTypes,
            storage: $storage,
        );
    }

    /**
     * @param  list<array{path: string, name?: string}>  $attachments
     * @return list<MessageAttachment>
     */
    public function validate(array $attachments, RenderedMessageBody $renderedBody): array
    {
        $validated = [];

        foreach ($attachments as $attachment) {
            $validated[] = $this->readAttachment($attachment);
        }

        $this->assertMessageFitsBudget($validated, $renderedBody);

        return $validated;
    }

    /**
     * @param  list<MessageAttachment>  $attachments
     */
    public function revalidate(array $attachments, RenderedMessageBody $renderedBody): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->disk !== $this->disk) {
                throw new InvalidAttachment('The attachment storage disk changed.');
            }

            $current = $this->readAttachment([
                'path' => $attachment->path,
                'name' => $attachment->name,
            ]);

            if ($current->mimeType !== $attachment->mimeType
                || $current->byteSize !== $attachment->byteSize
                || ! hash_equals($attachment->sha256, $current->sha256)) {
                throw new InvalidAttachment('The attachment metadata changed after the draft was created.');
            }
        }

        $this->assertMessageFitsBudget($attachments, $renderedBody);
    }

    /**
     * @param  array{path: string, name?: string}  $attachment
     */
    private function readAttachment(array $attachment): MessageAttachment
    {
        $path = $attachment['path'];
        $name = $attachment['name'] ?? $path;

        if (! $this->isSafeStoragePath($path)) {
            throw new InvalidAttachment('The attachment path must be inside the configured attachment directory.');
        }

        $name = $this->normalizeFilename($name);
        $pathExtension = $this->assertSafeExtension($path);
        $displayExtension = $this->assertSafeExtension($name);

        if ($pathExtension !== $displayExtension) {
            throw new InvalidAttachment('The attachment extension does not match its display name.');
        }

        if (! $this->storage->exists($path)) {
            throw new InvalidAttachment('The attachment no longer exists.');
        }

        $byteSize = $this->storage->size($path);
        $mimeType = $this->storage->mimeType($path);

        if ($byteSize < 0 || ! is_string($mimeType) || $mimeType === '') {
            throw new InvalidAttachment('The attachment metadata is unavailable.');
        }

        if ($byteSize > $this->maxFileBytes) {
            throw new InvalidAttachment(sprintf(
                'An attachment may not exceed %d KiB.',
                intdiv($this->maxFileBytes, 1024),
            ));
        }

        $this->assertSafeMimeType($mimeType);
        $this->assertExtensionMatchesMimeType($pathExtension, $mimeType);

        return new MessageAttachment(
            disk: $this->disk,
            path: $path,
            name: $name,
            mimeType: $mimeType,
            byteSize: $byteSize,
            sha256: $this->contentDigest($path),
        );
    }

    private function contentDigest(string $path): string
    {
        $stream = $this->storage->readStream($path);

        if (! is_resource($stream)) {
            throw new InvalidAttachment('The attachment content is unavailable for verification.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<MessageAttachment>  $attachments
     */
    private function assertMessageFitsBudget(array $attachments, RenderedMessageBody $renderedBody): void
    {
        $attachmentBytes = array_sum(array_map(
            static fn (MessageAttachment $attachment): int => 4 * intdiv($attachment->byteSize + 2, 3),
            $attachments,
        ));
        $renderedBytes = strlen($renderedBody->html) + strlen($renderedBody->text);

        if (($attachmentBytes + $renderedBytes) > $this->maxTotalBytes) {
            throw new InvalidAttachment(sprintf(
                'The rendered message and base64-expanded attachments may not exceed %d KiB.',
                intdiv($this->maxTotalBytes, 1024),
            ));
        }
    }

    private function assertSafeExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new InvalidAttachment('The attachment extension is not permitted.');
        }

        if (! array_key_exists($extension, self::ALLOWED_EXTENSION_MIME_TYPES)) {
            throw new InvalidAttachment('The attachment extension is not allowed.');
        }

        return $extension;
    }

    private function assertSafeMimeType(string $mimeType): void
    {
        if (in_array($mimeType, self::DANGEROUS_MIME_TYPES, true)) {
            throw new InvalidAttachment('The attachment MIME type is not permitted.');
        }

        if (! in_array($mimeType, $this->allowedMimeTypes, true)) {
            throw new InvalidAttachment('The attachment MIME type is not allowed.');
        }
    }

    private function assertExtensionMatchesMimeType(string $extension, string $mimeType): void
    {
        if (! in_array($mimeType, self::ALLOWED_EXTENSION_MIME_TYPES[$extension], true)) {
            throw new InvalidAttachment('The attachment extension and MIME type are not compatible.');
        }
    }

    private function isSafeStoragePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true)
            && str_starts_with($path, $this->directory.'/');
    }

    private static function isSafeDirectory(string $directory): bool
    {
        $directory = trim($directory, '/');

        return $directory !== ''
            && ! str_starts_with($directory, '/')
            && ! in_array('..', explode('/', str_replace('\\', '/', $directory)), true);
    }

    private function normalizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $basename = pathinfo($name, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $basename) ?? '';
        $basename = preg_replace('/[\s-]+/', '-', $basename) ?? '';
        $basename = trim($basename, '.- ');

        if ($basename === '') {
            $basename = 'attachment';
        }

        $extension = preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?? '';

        return $extension === '' ? $basename : "{$basename}.{$extension}";
    }
}
