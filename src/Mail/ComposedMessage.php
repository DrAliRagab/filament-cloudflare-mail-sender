<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Mail;

use DrAliRagab\FilamentCloudflareMailSender\Data\MessageAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ComposedMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @var list<MessageAttachment>
     */
    private array $snapshotAttachments;

    private string $fromAddress;

    private ?string $fromName;

    private ?string $replyToAddress;

    private ?string $replyToName;

    private string $snapshotSubject;

    private string $htmlBody;

    private string $textBody;

    public function __construct(CloudflareMailMessage $snapshot)
    {
        if ($snapshot->html_body === null || $snapshot->text_body === null) {
            throw new InvalidMessageSnapshot('The persisted delivery body is unavailable.');
        }

        $this->fromAddress = $snapshot->from_address;
        $this->fromName = $snapshot->from_name;
        $this->replyToAddress = $snapshot->reply_to_address;
        $this->replyToName = $snapshot->reply_to_name;
        $this->snapshotSubject = $snapshot->subject;
        $this->htmlBody = $snapshot->html_body;
        $this->textBody = $snapshot->text_body;
        $this->snapshotAttachments = array_map(
            $this->attachmentFromMetadata(...),
            $snapshot->attachments,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress === null
                ? []
                : [new Address($this->replyToAddress, $this->replyToName)],
            subject: $this->snapshotSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
            text: 'filament-cloudflare-mail-sender::mail.plain-text',
            with: ['textBody' => $this->textBody],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (MessageAttachment $attachment): Attachment => Attachment::fromStorageDisk(
                $attachment->disk,
                $attachment->path,
            )->as($attachment->name)->withMime($attachment->mimeType),
            $this->snapshotAttachments,
        );
    }

    /**
     * @param  array{disk: string, path: string, name: string, mime_type: string, size: int, sha256: string}  $metadata
     */
    private function attachmentFromMetadata(array $metadata): MessageAttachment
    {
        return new MessageAttachment(
            disk: $metadata['disk'],
            path: $metadata['path'],
            name: $metadata['name'],
            mimeType: $metadata['mime_type'],
            byteSize: $metadata['size'],
            sha256: $metadata['sha256'],
        );
    }
}
