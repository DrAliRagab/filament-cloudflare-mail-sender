<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use LogicException;

final readonly class OwnedDraftManager
{
    public function __construct(
        private DatabaseManager $database,
        private AttachmentUploadScope $uploadScopes,
        private AuditBodyStoragePolicy $bodyStoragePolicy,
    ) {}

    public function find(Authenticatable $actor, string $draftId): ?CloudflareMailMessage
    {
        return $this->ownedQuery($actor, $draftId)->first();
    }

    public function cancel(Authenticatable $actor, string $draftId, string $scopeProof): bool
    {
        return $this->database->transaction(function () use ($actor, $draftId, $scopeProof): bool {
            $scope = $this->uploadScopes->inspect($actor, $scopeProof);

            if ($scope->draftId !== $draftId) {
                throw new InvalidMessageSnapshot('The attachment scope does not match this draft.');
            }

            $draft = $this->ownedQuery($actor, $draftId)->lockForUpdate()->first();

            if ($draft !== null && ($draft->status !== MessageStatus::Draft || $draft->send_token !== null)) {
                throw new InvalidMessageSnapshot('Only an unsent draft can be discarded.');
            }

            $this->deleteOwnedPaths($actor, $scopeProof, $this->scopeFiles($scope->directory));

            if ($draft === null) {
                return false;
            }

            $draft->forceFill([
                'status' => MessageStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();
            $this->bodyStoragePolicy->scrubTerminalBodies($draft);

            return true;
        });
    }

    public function pruneToAuthoritativeLedger(
        Authenticatable $actor,
        string $draftId,
        string $scopeProof,
    ): void {
        $this->database->transaction(function () use ($actor, $draftId, $scopeProof): void {
            $scope = $this->uploadScopes->inspect($actor, $scopeProof);

            if ($scope->draftId !== $draftId) {
                throw new InvalidMessageSnapshot('The attachment scope does not match this draft.');
            }

            $draft = $this->ownedQuery($actor, $draftId)->lockForUpdate()->first();

            if ($draft === null) {
                throw new InvalidMessageSnapshot('Only an authoritative owned message attachment scope can be pruned.');
            }

            $keptPaths = array_map(
                static fn (array $attachment): string => $attachment['path'],
                $draft->attachments,
            );
            $this->deleteOwnedPaths(
                $actor,
                $scopeProof,
                array_values(array_diff($this->scopeFiles($scope->directory), $keptPaths)),
            );
        });
    }

    public function deleteUpload(Authenticatable $actor, string $scopeProof, string $path): void
    {
        $this->uploadScopes->assertOwnsPath($actor, $scopeProof, $path);
        $this->deleteOwnedPaths($actor, $scopeProof, [$path]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteOwnedPaths(Authenticatable $actor, string $scopeProof, array $paths): void
    {
        $storage = $this->storage();

        foreach ($paths as $path) {
            try {
                $this->uploadScopes->assertOwnsPath($actor, $scopeProof, $path);
            } catch (InvalidAttachment) {
                continue;
            }

            if ($storage->exists($path) && ! $storage->delete($path)) {
                throw new InvalidAttachment('An attachment could not be removed from temporary storage.');
            }
        }
    }

    /** @return list<string> */
    private function scopeFiles(string $directory): array
    {
        return array_values($this->storage()->allFiles($directory));
    }

    private function storage(): FilesystemAdapter
    {
        $disk = config('filament-cloudflare-mail-sender.attachments.disk');

        if (! is_string($disk) || $disk === '') {
            throw new InvalidAttachment('The attachment disk configuration is invalid.');
        }

        return Storage::disk($disk);
    }

    /** @return Builder<CloudflareMailMessage> */
    private function ownedQuery(Authenticatable $actor, string $draftId): Builder
    {
        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new LogicException('The authenticated actor has an invalid identifier.');
        }

        return CloudflareMailMessage::query()
            ->whereKey($draftId)
            ->where('actor_type', $actor::class)
            ->where('actor_id', (string) $identifier);
    }
}
