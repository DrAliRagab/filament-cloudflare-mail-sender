<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Data\RenderedMessageBody;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('message-attachments');

    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'message-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'package-mail');
});

test('it permits configured safe attachment types and stores only metadata', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nInvoice");

    $attachments = AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '<p>Hello</p>', text: 'Hello'),
    );

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->disk)->toBe('message-attachments')
        ->and($attachments[0]->path)->toBe(attachmentPath('invoice.pdf'))
        ->and($attachments[0]->name)->toBe('invoice.pdf')
        ->and($attachments[0]->mimeType)->toBe('application/pdf')
        ->and($attachments[0]->byteSize)->toBe(strlen("%PDF-1.4\nInvoice"))
        ->and($attachments[0]->sha256)->toBe(hash('sha256', "%PDF-1.4\nInvoice"))
        ->and($attachments[0]->toAuditMetadata())->toBe([
            'disk' => 'message-attachments',
            'path' => attachmentPath('invoice.pdf'),
            'name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen("%PDF-1.4\nInvoice"),
            'sha256' => hash('sha256', "%PDF-1.4\nInvoice"),
        ]);
});

test('it rejects safe files outside the configured package attachment root', function (): void {
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'package-mail');
    storeAttachmentFile('private/invoice.pdf', "%PDF-1.4\nPrivate");
    storeAttachmentFile('package-mail/invoice.pdf', "%PDF-1.4\nOwned");

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => 'private/invoice.pdf', 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'configured attachment directory');

    expect(AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => 'package-mail/invoice.pdf', 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toHaveCount(1);
});

test('it normalizes attachment filenames to safe display names', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nInvoice");

    $attachments = AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => "../../Quarterly <report>\n2026.PDF"]],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    );

    expect($attachments[0]->name)->toBe('Quarterly-report-2026.pdf');
});

test('it rejects dangerous filename extensions even when storage reports an allowed MIME type', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nInvoice");

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => 'invoice.html']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'extension');
});

test('it rejects dangerous MIME types even when the filename extension is allowed', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), '<!doctype html><title>not a PDF</title>');

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'MIME type');
});

test('it rejects common script extensions even when storage reports allowed plain text', function (string $extension): void {
    $path = attachmentPath("script.{$extension}");
    Storage::disk('message-attachments')->put($path, 'echo harmless text');

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => $path, 'name' => $path]],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'extension');
})->with([
    'Python' => 'py',
    'Perl' => 'pl',
    'Ruby' => 'rb',
    'macOS command script' => 'command',
    'PowerShell module' => 'psm1',
    'PowerShell data module' => 'psd1',
    'PowerShell session configuration' => 'pssc',
    'shell script' => 'fish',
]);

test('it rejects unapproved shell and unknown extensions even when storage reports allowed plain text', function (string $filename): void {
    $path = attachmentPath($filename);
    Storage::disk('message-attachments')->put($path, 'echo harmless text');

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => $path, 'name' => $filename]],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'extension');
})->with([
    'Korn shell' => 'script.ksh',
    'C shell' => 'script.csh',
    'unknown extension' => 'document.markdown',
    'no extension' => 'attachment',
]);

test('it rejects an allowed extension when its storage MIME type belongs to a different approved family', function (): void {
    storeAttachmentFile(attachmentPath('document.txt'), "%PDF-1.4\nNot plain text");

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('document.txt'), 'name' => 'document.txt']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, 'compatible');
});

test('it rejects a file larger than the configured 4096 KiB raw limit', function (): void {
    Storage::disk('message-attachments')->put(attachmentPath('large.txt'), str_repeat('a', (4096 * 1024) + 1));

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('large.txt'), 'name' => 'large.txt']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    ))->toThrow(InvalidAttachment::class, '4096 KiB');
});

test('it rejects a rendered message exceeding the 5120 KiB budget after base64 attachment expansion', function (): void {
    Storage::disk('message-attachments')->put(attachmentPath('large.txt'), str_repeat('a', 3_932_160));

    expect(fn (): array => AttachmentPolicy::fromConfiguration()->validate(
        attachments: [['path' => attachmentPath('large.txt'), 'name' => 'large.txt']],
        renderedBody: new RenderedMessageBody(html: 'x', text: ''),
    ))->toThrow(InvalidAttachment::class, '5120 KiB');
});

test('it revalidates persisted attachment metadata against the configured disk before delivery', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nInvoice");

    $policy = AttachmentPolicy::fromConfiguration();
    $attachments = $policy->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    );

    Storage::disk('message-attachments')->put(attachmentPath('invoice.pdf'), '%PDF-1.4 replaced with a longer invoice');

    expect(function () use ($policy, $attachments): void {
        $policy->revalidate(
            attachments: $attachments,
            renderedBody: new RenderedMessageBody(html: '', text: ''),
        );
    })->toThrow(InvalidAttachment::class, 'changed');
});

test('it rejects a same-size same-MIME attachment whose content digest changed', function (): void {
    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nInvoice");

    $policy = AttachmentPolicy::fromConfiguration();
    $attachments = $policy->validate(
        attachments: [['path' => attachmentPath('invoice.pdf'), 'name' => 'invoice.pdf']],
        renderedBody: new RenderedMessageBody(html: '', text: ''),
    );

    storeAttachmentFile(attachmentPath('invoice.pdf'), "%PDF-1.4\nReceipt");

    expect(function () use ($policy, $attachments): void {
        $policy->revalidate(
            attachments: $attachments,
            renderedBody: new RenderedMessageBody(html: '', text: ''),
        );
    })->toThrow(InvalidAttachment::class, 'changed');
});

function storeAttachmentFile(string $path, string $contents): void
{
    $file = UploadedFile::fake()->createWithContent($path, $contents);

    Storage::disk('message-attachments')->putFileAs('', $file, $path);
}

function attachmentPath(string $path): string
{
    return 'package-mail/'.$path;
}
