<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\MessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Data\ComposedMessageData;
use DrAliRagab\FilamentCloudflareMailSender\Data\MessageAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class MessageSnapshotFactory
{
    public function __construct(
        private MessageBodyRenderer $renderer,
        private AttachmentPolicy $attachmentPolicy,
    ) {}

    public function createDraft(ComposedMessageData $data): CloudflareMailMessage
    {
        return CloudflareMailMessage::query()->create($this->draftAttributes($data));
    }

    public function saveOwnedDraft(string $draftId, ComposedMessageData $data): CloudflareMailMessage
    {
        if (! Str::isUlid($draftId)) {
            throw new InvalidMessageSnapshot('The compose draft identifier is invalid.');
        }

        $attributes = $this->draftAttributes($data);
        $draft = new CloudflareMailMessage;
        $draft->setAttribute($draft->getKeyName(), $draftId);
        $draft->fill($attributes);
        $timestamp = $draft->freshTimestamp();
        $draft->setCreatedAt($timestamp);
        $draft->setUpdatedAt($timestamp);

        return DB::transaction(function () use ($attributes, $draft, $draftId): CloudflareMailMessage {
            $inserted = CloudflareMailMessage::query()->insertOrIgnore($draft->getAttributes());
            $winner = CloudflareMailMessage::query()->lockForUpdate()->find($draftId);

            if ($winner === null) {
                throw new InvalidMessageSnapshot('The compose draft could not be persisted.');
            }

            return $inserted === 1
                ? $winner
                : $this->updateDraftWithAttributes($winner, $attributes);
        });
    }

    public function updateDraft(CloudflareMailMessage $message, ComposedMessageData $data): CloudflareMailMessage
    {
        $attributes = $this->draftAttributes($data);

        return DB::transaction(function () use ($attributes, $message): CloudflareMailMessage {
            $authoritative = CloudflareMailMessage::query()->lockForUpdate()->find($message->id);

            if ($authoritative === null) {
                throw new InvalidMessageSnapshot('The compose draft no longer exists.');
            }

            return $this->updateDraftWithAttributes($authoritative, $attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function updateDraftWithAttributes(CloudflareMailMessage $message, array $attributes): CloudflareMailMessage
    {
        if ($message->status !== MessageStatus::Draft || $message->send_token !== null) {
            throw new LogicException('Only an unsent draft can be updated.');
        }

        if ($message->actor_type !== $attributes['actor_type'] || $message->actor_id !== $attributes['actor_id']) {
            throw new InvalidMessageSnapshot('The authenticated actor does not own this draft.');
        }

        $message->fill($attributes)->save();

        return $message->refresh();
    }

    /** @return array<string, mixed> */
    private function draftAttributes(ComposedMessageData $data): array
    {
        $renderedBody = $this->renderer->render($data->markdown);
        $attachments = $this->attachmentPolicy->validate($data->attachments, $renderedBody);
        [$actorType, $actorId] = $this->snapshotActor($data->actor);
        $delivery = $this->deliverySettings($data);

        return [
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'mailer' => $delivery['mailer'],
            'from_address' => $delivery['from_address'],
            'from_name' => $delivery['from_name'],
            'reply_to_address' => $delivery['reply_to_address'],
            'reply_to_name' => $delivery['reply_to_name'],
            'to' => $data->recipients->to(),
            'cc' => $data->recipients->cc(),
            'bcc' => $data->recipients->bcc(),
            'subject' => $data->subject,
            'markdown_body' => $data->markdown,
            'html_body' => $renderedBody->html,
            'text_body' => $renderedBody->text,
            'attachments' => array_map(
                static fn (MessageAttachment $attachment): array => $attachment->toAuditMetadata(),
                $attachments,
            ),
            'status' => MessageStatus::Draft,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function snapshotActor(?Authenticatable $actor): array
    {
        if ($actor === null) {
            return [null, null];
        }

        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new InvalidMessageSnapshot('The authenticated actor must have a string or integer identifier.');
        }

        return [$actor::class, (string) $identifier];
    }

    /**
     * @return array{mailer: string, from_address: string, from_name: string|null, reply_to_address: string|null, reply_to_name: string|null}
     */
    private function deliverySettings(ComposedMessageData $data): array
    {
        $mailer = config('filament-cloudflare-mail-sender.mailer');
        $from = config('filament-cloudflare-mail-sender.from');
        $replyTo = config('filament-cloudflare-mail-sender.reply_to');

        if (! is_string($mailer) || $mailer === '' || ! is_array($from) || ! is_array($replyTo)) {
            throw new InvalidMessageSnapshot('The configured delivery settings are invalid.');
        }

        $configuredFromAddress = $from['address'] ?? null;
        $configuredFromName = $from['name'] ?? null;

        if ($configuredFromAddress === null || $configuredFromAddress === '') {
            $configuredFromAddress = ConfiguredSender::address();
        }

        if ($configuredFromName === null || $configuredFromName === '') {
            $configuredFromName = ConfiguredSender::name();
        }

        $fromEditable = ConfiguredSender::editable();
        $fromAddress = ($data->useDeliveryOverrides && $fromEditable)
            ? $data->fromAddress
            : $configuredFromAddress;
        $fromName = $this->nullableString(($data->useDeliveryOverrides && $fromEditable)
            ? $data->fromName
            : $configuredFromName);
        $replyToAddress = $this->nullableString($data->useDeliveryOverrides
            ? $data->replyToAddress
            : ($replyTo['address'] ?? null));
        $replyToName = $this->nullableString($data->useDeliveryOverrides
            ? $data->replyToName
            : ($replyTo['name'] ?? null));

        if (! is_string($fromAddress) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || ($replyToAddress !== null && filter_var($replyToAddress, FILTER_VALIDATE_EMAIL) === false)
            || ($replyToAddress === null && $replyToName !== null)) {
            throw new InvalidMessageSnapshot('The configured delivery settings are invalid.');
        }

        if ($fromEditable && $data->useDeliveryOverrides) {
            $this->assertAllowedFromDomain($fromAddress, $from);
        }

        return [
            'mailer' => $mailer,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'reply_to_address' => $replyToAddress,
            'reply_to_name' => $replyToName,
        ];
    }

    /**
     * @param  array<mixed>  $from
     */
    private function assertAllowedFromDomain(string $fromAddress, array $from): void
    {
        $allowedDomains = $from['allowed_domains'] ?? [];

        if (! is_array($allowedDomains)) {
            throw new InvalidMessageSnapshot('The configured delivery settings are invalid.');
        }

        $allowedDomains = array_values(array_filter(
            array_map(
                static fn (mixed $allowedDomain): string => is_string($allowedDomain) ? strtolower(trim($allowedDomain)) : '',
                $allowedDomains,
            ),
            static fn (string $allowedDomain): bool => $allowedDomain !== '',
        ));

        if ($allowedDomains === []) {
            $defaultAddress = ConfiguredSender::address();
            $defaultDomain = is_string($defaultAddress) && str_contains($defaultAddress, '@')
                ? strtolower((string) substr(strrchr($defaultAddress, '@') ?: '', 1))
                : '';

            $allowedDomains = $defaultDomain === '' ? [] : [$defaultDomain];
        }

        $domain = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));

        if ($domain === '' || ! in_array($domain, $allowedDomains, true)) {
            throw new InvalidMessageSnapshot('The sender must use an allowed sending domain.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidMessageSnapshot('The configured delivery settings are invalid.');
        }

        return $value;
    }
}
