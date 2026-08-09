<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Attachments;

use DrAliRagab\FilamentCloudflareMailSender\Data\OwnedUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;
use JsonException;

final readonly class AttachmentUploadScope
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function create(Authenticatable $actor): OwnedUploadScope
    {
        $draftId = (string) Str::ulid();
        $payload = [
            'version' => 1,
            'actor_type' => $actor::class,
            'actor_id' => $this->actorIdentifier($actor),
            'draft_id' => $draftId,
        ];
        $proof = $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        return new OwnedUploadScope($proof, $draftId, $this->directoryFor($payload));
    }

    public function inspect(Authenticatable $actor, string $proof): OwnedUploadScope
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidAttachment('The attachment upload scope is invalid.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['actor_type'] ?? null) !== $actor::class
            || ($payload['actor_id'] ?? null) !== $this->actorIdentifier($actor)
            || ! is_string($payload['draft_id'] ?? null)
            || ! Str::isUlid($payload['draft_id'])) {
            throw new InvalidAttachment('The attachment upload scope does not belong to the authenticated actor.');
        }

        return new OwnedUploadScope($proof, $payload['draft_id'], $this->directoryFor($payload));
    }

    public function assertOwnsPath(Authenticatable $actor, string $proof, string $path): void
    {
        $scope = $this->inspect($actor, $proof);

        if (! str_starts_with($path, $scope->directory.'/')) {
            throw new InvalidAttachment('The attachment does not belong to this compose page.');
        }
    }

    /** @param array{actor_type: string, actor_id: string, draft_id: string, version: int} $payload */
    private function directoryFor(array $payload): string
    {
        $root = config('filament-cloudflare-mail-sender.attachments.directory');

        if (! is_string($root)
            || trim($root, '/') === ''
            || str_starts_with($root, '/')
            || in_array('..', explode('/', str_replace('\\', '/', $root)), true)) {
            throw new InvalidAttachment('The attachment directory configuration is invalid.');
        }

        $actorHash = substr(hash('sha256', $payload['actor_type'].':'.$payload['actor_id']), 0, 32);

        return trim($root, '/').'/'.$actorHash.'/'.$payload['draft_id'];
    }

    private function actorIdentifier(Authenticatable $actor): string
    {
        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new InvalidAttachment('The authenticated actor has an invalid identifier.');
        }

        return (string) $identifier;
    }
}
