<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Mail\ComposedMessage;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Storage::fake('delivery-attachments');
    Storage::disk('delivery-attachments')->put('temporary/invoice.pdf', "%PDF-1.4\nInvoice");

    config()->set('mail.mailers.delivery-array', ['transport' => 'array']);
    Mail::purge('delivery-array');
});

test('it emits the exact persisted headers bodies recipients and attachments', function (): void {
    $snapshot = CloudflareMailMessage::query()->create([
        'mailer' => 'delivery-array',
        'from_address' => 'sender@example.test',
        'from_name' => 'Snapshot Sender',
        'reply_to_address' => 'reply@example.test',
        'reply_to_name' => 'Reply Desk',
        'to' => ['first@example.test', 'second@example.test'],
        'cc' => ['copy@example.test'],
        'bcc' => ['hidden@example.test'],
        'subject' => 'Persisted subject',
        'markdown_body' => 'This is the source.',
        'html_body' => '<p>Exact <strong>HTML</strong> &amp; content.</p>',
        'text_body' => "Exact text & content.\nSecond line.",
        'attachments' => [[
            'disk' => 'delivery-attachments',
            'path' => 'temporary/invoice.pdf',
            'name' => 'audited-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen("%PDF-1.4\nInvoice"),
            'sha256' => hash('sha256', "%PDF-1.4\nInvoice"),
        ]],
        'status' => MessageStatus::Queued,
    ]);

    Mail::mailer($snapshot->mailer)
        ->to($snapshot->to)
        ->cc($snapshot->cc)
        ->bcc($snapshot->bcc)
        ->send(new ComposedMessage($snapshot));

    $mailer = resolve(MailManager::class)->driver('delivery-array');
    $transport = $mailer->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        throw new LogicException('The delivery test mailer must use Laravel\'s array transport.');
    }

    $sentMessage = $transport->messages()->sole();

    if (! $sentMessage instanceof SentMessage) {
        throw new LogicException('Laravel\'s array transport must retain a sent message.');
    }

    $email = $sentMessage->getOriginalMessage();

    if (! $email instanceof Email) {
        throw new LogicException('The composed message must produce a Symfony email.');
    }

    expect($email->getFrom()[0]->getAddress())->toBe('sender@example.test')
        ->and($email->getFrom()[0]->getName())->toBe('Snapshot Sender')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('reply@example.test')
        ->and($email->getReplyTo()[0]->getName())->toBe('Reply Desk')
        ->and(array_map(static fn ($address): string => $address->getAddress(), $email->getTo()))
        ->toBe(['first@example.test', 'second@example.test'])
        ->and(array_map(static fn ($address): string => $address->getAddress(), $email->getCc()))
        ->toBe(['copy@example.test'])
        ->and(array_map(static fn ($address): string => $address->getAddress(), $email->getBcc()))
        ->toBe(['hidden@example.test'])
        ->and($email->getSubject())->toBe('Persisted subject')
        ->and($email->getHtmlBody())->toBe('<p>Exact <strong>HTML</strong> &amp; content.</p>')
        ->and($email->getTextBody())->toBe("Exact text & content.\nSecond line.");

    $attachment = $email->getAttachments()[0];

    expect($email->getAttachments())->toHaveCount(1)
        ->and($attachment->getFilename())->toBe('audited-invoice.pdf')
        ->and($attachment->getMediaType())->toBe('application')
        ->and($attachment->getMediaSubtype())->toBe('pdf')
        ->and($attachment->getBody())->toBe("%PDF-1.4\nInvoice");
});
