<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Data\VerifiedPreviewProof;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;
use JsonException;

final readonly class PreviewProof
{
    public function __construct(
        private StringEncrypter $encrypter,
        private MessageSnapshotFingerprint $fingerprints,
    ) {}

    public function issue(Authenticatable $actor, CloudflareMailMessage $message): string
    {
        $payload = [
            'version' => 1,
            'actor_type' => $actor::class,
            'actor_id' => $this->actorIdentifier($actor),
            'draft_id' => $message->id,
            'fingerprint' => $this->fingerprints->for($message),
        ];

        return $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function verify(Authenticatable $actor, string $proof): VerifiedPreviewProof
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidMessageSnapshot('The email preview proof is invalid. Preview the email again.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['actor_type'] ?? null) !== $actor::class
            || ($payload['actor_id'] ?? null) !== $this->actorIdentifier($actor)
            || ! is_string($payload['draft_id'] ?? null)
            || ! Str::isUlid($payload['draft_id'])
            || ! is_string($payload['fingerprint'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $payload['fingerprint']) !== 1) {
            throw new InvalidMessageSnapshot('The email preview proof does not match the authenticated actor. Preview the email again.');
        }

        return new VerifiedPreviewProof($payload['draft_id'], $payload['fingerprint']);
    }

    private function actorIdentifier(Authenticatable $actor): string
    {
        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new InvalidMessageSnapshot('The authenticated actor has an invalid identifier.');
        }

        return (string) $identifier;
    }
}
