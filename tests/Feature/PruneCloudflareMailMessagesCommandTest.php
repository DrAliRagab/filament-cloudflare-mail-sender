<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;

beforeEach(function (): void {
    Date::setTestNow('2026-08-09 12:00:00');
    Storage::fake('prune-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'prune-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'filament-cloudflare-mail-sender/attachments');
});

afterEach(function (): void {
    Date::setTestNow();
    Date::useDefault();
});

test('the prune command is registered for console use and null retention is a no-op', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', null);
    $message = pruneMessage(MessageStatus::Sent, Date::now()->subYear());

    expect(Artisan::all())->toHaveKey('cloudflare-mail:prune');

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('disabled');

    expect($message->fresh())->not->toBeNull();
});

test('it prunes only terminal records strictly older than their terminal cutoff', function (bool $immutable): void {
    $dateClass = $immutable ? CarbonImmutable::class : Carbon::class;
    Date::use($dateClass);
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', 30);
    $cutoff = Date::now()->subDays(30);

    expect($cutoff)->toBeInstanceOf($dateClass);

    $expiredSent = pruneMessage(MessageStatus::Sent, $cutoff->copy()->subSecond());
    $expiredFailed = pruneMessage(MessageStatus::Failed, $cutoff->copy()->subDay());
    $expiredCancelled = pruneMessage(MessageStatus::Cancelled, $cutoff->copy()->subYear());
    $boundarySent = pruneMessage(MessageStatus::Sent, $cutoff);
    $boundaryFailed = pruneMessage(MessageStatus::Failed, $cutoff);
    $boundaryCancelled = pruneMessage(MessageStatus::Cancelled, $cutoff);
    $recentFailed = pruneMessage(MessageStatus::Failed, $cutoff->copy()->addSecond());
    $draft = pruneMessage(MessageStatus::Draft, $cutoff->copy()->subYear());
    $queued = pruneMessage(MessageStatus::Queued, $cutoff->copy()->subYear());
    $sending = pruneMessage(MessageStatus::Sending, $cutoff->copy()->subYear());

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::SUCCESS);

    expect($expiredSent->fresh())->toBeNull()
        ->and($expiredFailed->fresh())->toBeNull()
        ->and($expiredCancelled->fresh())->toBeNull()
        ->and($boundarySent->fresh())->not->toBeNull()
        ->and($boundaryFailed->fresh())->not->toBeNull()
        ->and($boundaryCancelled->fresh())->not->toBeNull()
        ->and($recentFailed->fresh())->not->toBeNull()
        ->and($draft->fresh())->not->toBeNull()
        ->and($queued->fresh())->not->toBeNull()
        ->and($sending->fresh())->not->toBeNull();
})->with([
    'mutable dates' => [false],
    'immutable dates' => [true],
]);

test('it deletes root-owned attachments before pruning and accepts already missing files', function (bool $immutable): void {
    $dateClass = $immutable ? CarbonImmutable::class : Carbon::class;
    Date::use($dateClass);
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', 1);
    $existingPath = pruneAttachmentPath('existing.pdf');
    $missingPath = pruneAttachmentPath('missing.pdf');
    Storage::disk('prune-attachments')->put($existingPath, 'existing');

    $withExisting = pruneMessage(MessageStatus::Sent, Date::now()->subDays(2), [$existingPath]);
    $withMissing = pruneMessage(MessageStatus::Failed, Date::now()->subDays(2), [$missingPath]);

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::SUCCESS);

    expect(Storage::disk('prune-attachments')->exists($existingPath))->toBeFalse()
        ->and($withExisting->fresh())->toBeNull()
        ->and($withMissing->fresh())->toBeNull();
})->with([
    'mutable dates' => [false],
    'immutable dates' => [true],
]);

test('it refuses to delete or prune attachment metadata outside the configured package root', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', 1);
    $outsidePath = 'other-package/private.pdf';
    Storage::disk('prune-attachments')->put($outsidePath, 'private');
    $message = pruneMessage(MessageStatus::Cancelled, Date::now()->subDays(2), [$outsidePath]);

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::FAILURE);

    expect(Storage::disk('prune-attachments')->exists($outsidePath))->toBeTrue()
        ->and($message->fresh())->not->toBeNull();
});

test('a false attachment delete keeps the record and a later command retry can finish', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', 1);
    $path = pruneAttachmentPath('retry.pdf');
    $realDisk = Storage::disk('prune-attachments');
    $realDisk->put($path, 'retry');
    $message = pruneMessage(MessageStatus::Sent, Date::now()->subDays(2), [$path]);
    $filesystems = resolve(FilesystemManager::class);
    $filesystems->set('prune-attachments', new class($realDisk->getDriver(), $realDisk->getAdapter(), $realDisk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    });

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::FAILURE);

    expect($message->fresh())->not->toBeNull()
        ->and($realDisk->exists($path))->toBeTrue();

    $filesystems->set('prune-attachments', $realDisk);
    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::SUCCESS);

    expect($message->fresh())->toBeNull()
        ->and($realDisk->exists($path))->toBeFalse();
});

test('a filesystem delete error keeps the record retryable', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', 1);
    $path = pruneAttachmentPath('throws.pdf');
    $realDisk = Storage::disk('prune-attachments');
    $realDisk->put($path, 'throws');
    $message = pruneMessage(MessageStatus::Failed, Date::now()->subDays(2), [$path]);
    resolve(FilesystemManager::class)->set('prune-attachments', new class($realDisk->getDriver(), $realDisk->getAdapter(), $realDisk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            $path = is_string($paths) ? $paths : ($paths[0] ?? 'unknown');

            throw UnableToDeleteFile::atLocation($path, 'Simulated test failure.');
        }
    });

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::FAILURE);

    expect($message->fresh())->not->toBeNull()
        ->and($realDisk->exists($path))->toBeTrue();
});

test('it rejects invalid retention and attachment cleanup configuration without pruning', function (mixed $retention, mixed $disk, mixed $directory): void {
    config()->set('filament-cloudflare-mail-sender.audit.retention_days', $retention);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', $disk);
    config()->set('filament-cloudflare-mail-sender.attachments.directory', $directory);
    $message = pruneMessage(MessageStatus::Sent, Date::now()->subYear());

    expect(Artisan::call('cloudflare-mail:prune'))->toBe(Command::INVALID);

    expect($message->fresh())->not->toBeNull();
})->with([
    'zero retention' => [0, 'prune-attachments', 'filament-cloudflare-mail-sender/attachments'],
    'negative retention' => [-1, 'prune-attachments', 'filament-cloudflare-mail-sender/attachments'],
    'string retention' => ['30', 'prune-attachments', 'filament-cloudflare-mail-sender/attachments'],
    'empty disk' => [30, '', 'filament-cloudflare-mail-sender/attachments'],
    'absolute root' => [30, 'prune-attachments', '/filament-cloudflare-mail-sender/attachments'],
    'traversing root' => [30, 'prune-attachments', 'filament-cloudflare-mail-sender/../attachments'],
]);

/**
 * @param  list<string>  $attachmentPaths
 */
function pruneMessage(MessageStatus $status, DateTimeInterface $terminalAt, array $attachmentPaths = []): CloudflareMailMessage
{
    $terminalTimestamps = match ($status) {
        MessageStatus::Sent => ['sent_at' => $terminalAt],
        MessageStatus::Failed => ['failed_at' => $terminalAt],
        MessageStatus::Cancelled => ['cancelled_at' => $terminalAt],
        MessageStatus::Draft, MessageStatus::Queued, MessageStatus::Sending => [],
    };

    return CloudflareMailMessage::query()->create([
        'mailer' => 'array',
        'from_address' => 'sender@example.test',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Prune candidate',
        'attachments' => array_map(static fn (string $path): array => [
            'disk' => 'prune-attachments',
            'path' => $path,
            'name' => basename($path),
            'mime_type' => 'application/pdf',
            'size' => 1,
            'sha256' => str_repeat('a', 64),
        ], $attachmentPaths),
        'status' => $status,
        ...$terminalTimestamps,
    ]);
}

function pruneAttachmentPath(string $filename): string
{
    return 'filament-cloudflare-mail-sender/attachments/actor-hash/draft-ulid/'.$filename;
}
