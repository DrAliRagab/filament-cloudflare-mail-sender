<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\MessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Data\ComposedMessageData;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\AuditBodyStoragePolicy;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFactory;
use DrAliRagab\FilamentCloudflareMailSender\Support\RecipientAddressSet;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('snapshot-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'snapshot-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'drafts');
    config()->set('filament-cloudflare-mail-sender.mailer', 'cloudflare');
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'sender@example.test',
        'name' => 'Sender Name',
        'editable' => false,
    ]);
    config()->set('filament-cloudflare-mail-sender.reply_to', [
        'address' => 'replies@example.test',
        'name' => 'Replies Team',
    ]);

    $file = UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\nStatement");
    Storage::disk('snapshot-attachments')->putFileAs('drafts', $file, 'statement.pdf');
});

test('it persists an immutable pre-queue snapshot of all delivery fields', function (): void {
    $data = snapshotMessageData();
    $factory = new MessageSnapshotFactory(
        renderer: resolve(MessageBodyRenderer::class),
        attachmentPolicy: AttachmentPolicy::fromConfiguration(),
    );

    $message = $factory->createDraft($data)->refresh();

    expect($message)->toBeInstanceOf(CloudflareMailMessage::class)
        ->and($message->status)->toBe(MessageStatus::Draft)
        ->and($message->send_token)->toBeNull()
        ->and($message->actor_type)->toBe(TestActor::class)
        ->and($message->actor_id)->toBe('01J4M4H7Y1M2P3Q4R5S6T7U8V9')
        ->and($message->mailer)->toBe('cloudflare')
        ->and($message->from_address)->toBe('sender@example.test')
        ->and($message->from_name)->toBe('Sender Name')
        ->and($message->reply_to_address)->toBe('replies@example.test')
        ->and($message->reply_to_name)->toBe('Replies Team')
        ->and($message->to)->toBe(['Primary@Example.test'])
        ->and($message->cc)->toBe(['Copy@Example.test'])
        ->and($message->bcc)->toBe(['Hidden@Example.test'])
        ->and($message->subject)->toBe('Your statement')
        ->and($message->markdown_body)->toBe('Hello **friend**')
        ->and($message->html_body)->toContain('<strong>friend</strong>')
        ->and($message->text_body)->toBe('Hello friend')
        ->and($message->attachments)->toBe([[
            'disk' => 'snapshot-attachments',
            'path' => 'drafts/statement.pdf',
            'name' => 'statement.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen("%PDF-1.4\nStatement"),
            'sha256' => hash('sha256', "%PDF-1.4\nStatement"),
        ]])
        ->and($message->attachments[0])->not->toHaveKey('content')
        ->and($message->attachments[0])->not->toHaveKey('credentials');
});

test('it derives the actor and delivery settings from trusted identity and package configuration', function (): void {
    config()->set('filament-cloudflare-mail-sender.mailer', 'configured-cloudflare');
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'configured-sender@example.test',
        'name' => 'Configured Sender',
        'editable' => false,
    ]);
    config()->set('filament-cloudflare-mail-sender.reply_to', [
        'address' => 'configured-replies@example.test',
        'name' => 'Configured Replies',
    ]);

    $data = new ComposedMessageData(
        actor: new TestActor('trusted-actor-id'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Configuration governs this snapshot',
        markdown: 'Hello',
        attachments: [],
    );

    $message = (new MessageSnapshotFactory(
        renderer: resolve(MessageBodyRenderer::class),
        attachmentPolicy: AttachmentPolicy::fromConfiguration(),
    ))->createDraft($data);

    expect($message->actor_type)->toBe(TestActor::class)
        ->and($message->actor_id)->toBe('trusted-actor-id')
        ->and($message->mailer)->toBe('configured-cloudflare')
        ->and($message->from_address)->toBe('configured-sender@example.test')
        ->and($message->from_name)->toBe('Configured Sender')
        ->and($message->reply_to_address)->toBe('configured-replies@example.test')
        ->and($message->reply_to_name)->toBe('Configured Replies');
});

test('it accepts an editable sender only on a configured sending domain', function (): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', true);
    config()->set('filament-cloudflare-mail-sender.from.allowed_domains', ['example.test']);

    $data = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Editable sender',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@example.test',
        fromName: 'Billing',
        replyToAddress: 'support@example.test',
        replyToName: 'Support',
        useDeliveryOverrides: true,
    );

    $message = resolve(MessageSnapshotFactory::class)->createDraft($data)->refresh();

    expect($message->from_address)->toBe('billing@example.test')
        ->and($message->from_name)->toBe('Billing')
        ->and($message->reply_to_address)->toBe('support@example.test')
        ->and($message->reply_to_name)->toBe('Support');
});

test('it rejects an editable sender outside configured sending domains', function (): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', true);
    config()->set('filament-cloudflare-mail-sender.from.allowed_domains', ['example.test']);

    $data = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Editable sender',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@other.test',
        fromName: 'Billing',
        useDeliveryOverrides: true,
    );

    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft($data))
        ->toThrow(InvalidMessageSnapshot::class, 'allowed sending domain');
});

test('locked sender configuration ignores delivery overrides from callers', function (): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', false);

    $data = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Locked sender',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'attacker@untrusted.test',
        fromName: 'Attacker',
        useDeliveryOverrides: true,
    );

    $message = resolve(MessageSnapshotFactory::class)->createDraft($data)->refresh();

    expect($message->from_address)->toBe('sender@example.test')
        ->and($message->from_name)->toBe('Sender Name');
});

test('editable sender with empty allowed domains derives the default sender domain', function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'sender@example.test',
        'name' => 'Sender Name',
        'editable' => true,
        'allowed_domains' => [],
    ]);

    $allowed = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Derived allow-list',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@example.test',
        fromName: 'Billing',
        useDeliveryOverrides: true,
    );
    $rejected = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Derived allow-list',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@other.test',
        fromName: 'Billing',
        useDeliveryOverrides: true,
    );

    $message = resolve(MessageSnapshotFactory::class)->createDraft($allowed)->refresh();

    expect($message->from_address)->toBe('billing@example.test')
        ->and(fn () => resolve(MessageSnapshotFactory::class)->createDraft($rejected))
        ->toThrow(InvalidMessageSnapshot::class, 'allowed sending domain');
});

test('editable sender with no default address and empty allowed domains rejects overrides', function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => null,
        'name' => null,
        'editable' => true,
        'allowed_domains' => [],
    ]);
    config()->set('mail.from.address', null);

    $data = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'No allow-list',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@example.test',
        fromName: 'Billing',
        useDeliveryOverrides: true,
    );

    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft($data))
        ->toThrow(InvalidMessageSnapshot::class, 'allowed sending domain');
});

test('it rejects an untrusted actor value before an audit identity can be spoofed', function (): void {
    $reflection = new ReflectionClass(ComposedMessageData::class);

    expect(fn (): object => $reflection->newInstanceArgs([
        'App\\Models\\Administrator:1',
        RecipientAddressSet::from(['recipient@example.test'], [], []),
        'Spoof attempt',
        'Hello',
        [],
    ]))->toThrow(TypeError::class);
});

test('it uses a server-issued draft ULID as the uniqueness boundary across repeated preview saves', function (): void {
    $draftId = (string) Str::ulid();
    $factory = resolve(MessageSnapshotFactory::class);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $first = $factory->saveOwnedDraft($draftId, snapshotMessageData());
    $second = $factory->saveOwnedDraft($draftId, snapshotMessageData());

    expect($first->id)->toBe($draftId)
        ->and($second->id)->toBe($draftId)
        ->and(CloudflareMailMessage::query()->count())->toBe(1)
        ->and(array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'insert or ignore'),
        )))->toHaveCount(2);
});

test('an insert-ignore collision never overwrites a queued winner', function (): void {
    $draftId = (string) Str::ulid();
    $factory = resolve(MessageSnapshotFactory::class);
    $winner = $factory->saveOwnedDraft($draftId, snapshotMessageData());
    $winner->forceFill([
        'subject' => 'Immutable queued winner',
        'status' => MessageStatus::Queued,
        'send_token' => (string) Str::uuid(),
        'queued_at' => now(),
    ])->save();

    expect(fn (): CloudflareMailMessage => $factory->saveOwnedDraft($draftId, replacementSnapshotMessageData()))
        ->toThrow(LogicException::class, 'Only an unsent draft');

    expect($winner->refresh()->subject)->toBe('Immutable queued winner')
        ->and($winner->markdown_body)->toBe('Hello **friend**')
        ->and($winner->attachments)->toHaveCount(1)
        ->and($winner->attachments[0]['path'])->toBe('drafts/statement.pdf')
        ->and($winner->status)->toBe(MessageStatus::Queued)
        ->and(CloudflareMailMessage::query()->count())->toBe(1);
});

test('a stale draft model cannot overwrite content after the authoritative row is queued', function (): void {
    $draftId = (string) Str::ulid();
    $factory = resolve(MessageSnapshotFactory::class);
    $staleDraft = $factory->saveOwnedDraft($draftId, snapshotMessageData());
    $authoritativeDraft = CloudflareMailMessage::query()->findOrFail($draftId);
    $sendToken = (string) Str::uuid();
    $authoritativeDraft->forceFill([
        'status' => MessageStatus::Queued,
        'send_token' => $sendToken,
        'queued_at' => now(),
    ])->save();

    expect(fn (): CloudflareMailMessage => $factory->updateDraft($staleDraft, replacementSnapshotMessageData()))
        ->toThrow(LogicException::class, 'Only an unsent draft');

    $authoritativeDraft->refresh();

    expect($authoritativeDraft->subject)->toBe('Your statement')
        ->and($authoritativeDraft->markdown_body)->toBe('Hello **friend**')
        ->and($authoritativeDraft->attachments)->toHaveCount(1)
        ->and($authoritativeDraft->attachments[0]['path'])->toBe('drafts/statement.pdf')
        ->and($authoritativeDraft->status)->toBe(MessageStatus::Queued)
        ->and($authoritativeDraft->send_token)->toBe($sendToken);
});

test('it rejects malformed deterministic compose identifiers', function (): void {
    expect(fn () => resolve(MessageSnapshotFactory::class)->saveOwnedDraft(
        'not-a-ulid',
        snapshotDataForActor(new TestActor('composer-1')),
    ))->toThrow(InvalidMessageSnapshot::class, 'draft identifier is invalid');
});

test('the public update boundary rejects queued records', function (): void {
    $message = resolve(MessageSnapshotFactory::class)->createDraft(
        snapshotDataForActor(new TestActor('composer-1')),
    );
    $message->forceFill([
        'status' => MessageStatus::Queued,
        'send_token' => (string) Str::uuid(),
    ])->save();

    expect(fn () => resolve(MessageSnapshotFactory::class)->updateDraft(
        $message->refresh(),
        snapshotDataForActor(new TestActor('composer-1')),
    ))->toThrow(LogicException::class, 'Only an unsent draft');
});

test('draft updates reject a different trusted actor', function (): void {
    $message = resolve(MessageSnapshotFactory::class)->createDraft(
        snapshotDataForActor(new TestActor('composer-1')),
    );

    expect(fn () => resolve(MessageSnapshotFactory::class)->updateDraft(
        $message,
        snapshotDataForActor(new TestActor('composer-2')),
    ))->toThrow(InvalidMessageSnapshot::class, 'does not own this draft');
});

test('snapshots preserve an explicitly absent actor for non-panel callers', function (): void {
    $message = resolve(MessageSnapshotFactory::class)->createDraft(snapshotDataForActor(null));

    expect($message->actor_type)->toBeNull()
        ->and($message->actor_id)->toBeNull();
});

test('snapshots reject actors without a stable identifier', function (): void {
    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft(
        snapshotDataForActor(new TestActor('')),
    ))->toThrow(InvalidMessageSnapshot::class, 'string or integer identifier');
});

test('snapshots reject invalid top-level delivery configuration', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft(
        snapshotDataForActor(new TestActor('composer-1')),
    ))->toThrow(InvalidMessageSnapshot::class, 'configured delivery settings are invalid');
})->with([
    'missing mailer' => ['filament-cloudflare-mail-sender.mailer', null],
    'empty mailer' => ['filament-cloudflare-mail-sender.mailer', ''],
    'invalid From configuration' => ['filament-cloudflare-mail-sender.from', 'sender@example.test'],
    'invalid Reply-To configuration' => ['filament-cloudflare-mail-sender.reply_to', 'reply@example.test'],
]);

test('snapshots fall back to Laravel mail.from when package from address and name are empty', function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => null,
        'name' => null,
        'editable' => false,
        'allowed_domains' => [],
    ]);
    config()->set('mail.from', [
        'address' => 'ops@example.test',
        'name' => 'Operations',
    ]);

    $message = resolve(MessageSnapshotFactory::class)
        ->createDraft(snapshotDataForActor(new TestActor('composer-1')))
        ->refresh();

    expect($message->from_address)->toBe('ops@example.test')
        ->and($message->from_name)->toBe('Operations');
});

test('snapshots reject invalid configured sender and reply-to combinations', function (array $from, array $replyTo): void {
    config()->set('filament-cloudflare-mail-sender.from', $from);
    config()->set('filament-cloudflare-mail-sender.reply_to', $replyTo);

    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft(
        snapshotDataForActor(new TestActor('composer-1')),
    ))->toThrow(InvalidMessageSnapshot::class, 'configured delivery settings are invalid');
})->with([
    'invalid sender address' => [
        ['address' => 'not-an-email', 'name' => null, 'editable' => false],
        ['address' => null, 'name' => null],
    ],
    'invalid Reply-To address' => [
        ['address' => 'sender@example.test', 'name' => null, 'editable' => false],
        ['address' => 'not-an-email', 'name' => null],
    ],
    'Reply-To name without address' => [
        ['address' => 'sender@example.test', 'name' => null, 'editable' => false],
        ['address' => null, 'name' => 'Support'],
    ],
    'non-string From name' => [
        ['address' => 'sender@example.test', 'name' => ['Sender'], 'editable' => false],
        ['address' => null, 'name' => null],
    ],
    'non-string Reply-To name' => [
        ['address' => 'sender@example.test', 'name' => null, 'editable' => false],
        ['address' => null, 'name' => ['Support']],
    ],
]);

test('editable sender configuration requires a list of allowed domains', function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'sender@example.test',
        'name' => 'Sender',
        'editable' => true,
        'allowed_domains' => 'example.test',
    ]);

    $data = new ComposedMessageData(
        actor: new TestActor('composer-1'),
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Editable sender',
        markdown: 'Hello',
        attachments: [],
        fromAddress: 'billing@example.test',
        fromName: 'Billing',
        useDeliveryOverrides: true,
    );

    expect(fn () => resolve(MessageSnapshotFactory::class)->createDraft($data))
        ->toThrow(InvalidMessageSnapshot::class, 'configured delivery settings are invalid');
});

test('it preserves delivery bodies while a message is non-terminal when audit body storage is disabled', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.store_body', false);

    $message = createSnapshotDraft();
    $policy = AuditBodyStoragePolicy::fromConfiguration();

    expect($policy->scrubTerminalBodies($message))->toBeFalse()
        ->and($message->markdown_body)->toBe('Hello **friend**')
        ->and($message->html_body)->toContain('<strong>friend</strong>')
        ->and($message->text_body)->toBe('Hello friend');
});

test('it scrubs delivery bodies only after a message reaches a terminal state when audit body storage is disabled', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.store_body', false);

    $message = createSnapshotDraft();
    $message->update(['status' => MessageStatus::Sent]);

    $policy = AuditBodyStoragePolicy::fromConfiguration();

    expect($policy->scrubTerminalBodies($message))->toBeTrue();

    $message->refresh();

    expect($message->markdown_body)->toBeNull()
        ->and($message->html_body)->toBeNull()
        ->and($message->text_body)->toBeNull();
});

function createSnapshotDraft(): CloudflareMailMessage
{
    return (new MessageSnapshotFactory(
        renderer: resolve(MessageBodyRenderer::class),
        attachmentPolicy: AttachmentPolicy::fromConfiguration(),
    ))->createDraft(snapshotMessageData());
}

function snapshotMessageData(): ComposedMessageData
{
    return new ComposedMessageData(
        actor: new TestActor('01J4M4H7Y1M2P3Q4R5S6T7U8V9'),
        recipients: RecipientAddressSet::from(
            to: [' Primary@Example.test '],
            cc: ['Copy@Example.test'],
            bcc: ['Hidden@Example.test'],
        ),
        subject: 'Your statement',
        markdown: 'Hello **friend**',
        attachments: [['path' => 'drafts/statement.pdf', 'name' => 'statement.pdf']],
    );
}

function snapshotDataForActor(?Authenticatable $actor): ComposedMessageData
{
    return new ComposedMessageData(
        actor: $actor,
        recipients: RecipientAddressSet::from(['recipient@example.test'], [], []),
        subject: 'Security boundary',
        markdown: 'Hello',
        attachments: [],
    );
}

function replacementSnapshotMessageData(): ComposedMessageData
{
    return new ComposedMessageData(
        actor: new TestActor('01J4M4H7Y1M2P3Q4R5S6T7U8V9'),
        recipients: RecipientAddressSet::from(['replacement@example.test'], [], []),
        subject: 'Stale overwrite attempt',
        markdown: 'Replacement body',
        attachments: [],
    );
}
