<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Console\Commands;

use Carbon\CarbonInterface;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class PruneCloudflareMailMessages extends Command
{
    protected $signature = 'cloudflare-mail:prune';

    protected $description = 'Prune expired terminal Cloudflare mail audit records and their attachments';

    public function handle(): int
    {
        $retentionDays = config('filament-cloudflare-mail-sender.audit.retention_days');

        if ($retentionDays === null) {
            $this->components->info('Cloudflare mail audit pruning is disabled.');

            return self::SUCCESS;
        }

        if (! is_int($retentionDays) || $retentionDays < 1) {
            $this->components->error('Cloudflare mail audit retention must be null or a positive integer.');

            return self::INVALID;
        }

        $disk = config('filament-cloudflare-mail-sender.attachments.disk');
        $root = config('filament-cloudflare-mail-sender.attachments.directory');

        if (! is_string($disk) || $disk === '' || ! is_string($root) || ! $this->isSafeRelativeRoot($root)) {
            $this->components->error('Cloudflare mail attachment cleanup configuration is invalid.');

            return self::INVALID;
        }

        $cutoff = Date::now()->subDays($retentionDays);
        $pruned = 0;
        $failed = 0;

        $this->expiredQuery($cutoff)->eachById(function (CloudflareMailMessage $candidate) use ($cutoff, $disk, $root, &$pruned, &$failed): void {
            try {
                if ($this->prune($candidate->id, $cutoff, $disk, $root)) {
                    $pruned++;
                }
            } catch (Throwable) {
                $failed++;
                $this->components->warn("Cloudflare mail record {$candidate->id} could not be pruned and was retained for retry.");
            }
        });

        $this->components->info("Pruned {$pruned} expired Cloudflare mail audit record(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<CloudflareMailMessage> */
    private function expiredQuery(CarbonInterface $cutoff): Builder
    {
        return CloudflareMailMessage::query()->where(function (Builder $query) use ($cutoff): void {
            $query
                ->where(function (Builder $query) use ($cutoff): void {
                    $query->where('status', MessageStatus::Sent->value)
                        ->where('sent_at', '<', $cutoff);
                })
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->where('status', MessageStatus::Failed->value)
                        ->where('failed_at', '<', $cutoff);
                })
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->where('status', MessageStatus::Cancelled->value)
                        ->where('cancelled_at', '<', $cutoff);
                });
        });
    }

    private function prune(string $id, CarbonInterface $cutoff, string $configuredDisk, string $configuredRoot): bool
    {
        return DB::transaction(function () use ($id, $cutoff, $configuredDisk, $configuredRoot): bool {
            $message = CloudflareMailMessage::query()->lockForUpdate()->find($id);

            if ($message === null || ! $this->isExpired($message, $cutoff)) {
                return false;
            }

            $filesystem = Storage::disk($configuredDisk);

            foreach ($message->attachments as $attachment) {
                if ($attachment['disk'] !== $configuredDisk || ! $this->isPathWithinRoot($attachment['path'], $configuredRoot)) {
                    throw new RuntimeException('Refusing to clean an attachment outside the configured package root.');
                }

                if ($filesystem->exists($attachment['path']) && ! $filesystem->delete($attachment['path'])) {
                    throw new RuntimeException('The attachment could not be deleted.');
                }
            }

            if (! $message->delete()) {
                throw new RuntimeException('The audit record could not be deleted.');
            }

            return true;
        });
    }

    private function isExpired(CloudflareMailMessage $message, CarbonInterface $cutoff): bool
    {
        return match ($message->status) {
            MessageStatus::Sent => $message->sent_at?->lt($cutoff) ?? false,
            MessageStatus::Failed => $message->failed_at?->lt($cutoff) ?? false,
            MessageStatus::Cancelled => $message->cancelled_at?->lt($cutoff) ?? false,
            MessageStatus::Draft, MessageStatus::Queued, MessageStatus::Sending => false,
        };
    }

    private function isSafeRelativeRoot(string $root): bool
    {
        return $root !== ''
            && ! str_starts_with($root, '/')
            && ! str_contains($root, '\\')
            && ! in_array('..', explode('/', $root), true);
    }

    private function isPathWithinRoot(string $path, string $root): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true)
            && str_starts_with($path, rtrim($root, '/').'/');
    }
}
