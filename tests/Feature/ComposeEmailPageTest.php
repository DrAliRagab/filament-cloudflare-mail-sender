<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\RecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Data\RecipientSuggestion;
use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Pages\ComposeEmail;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\Jobs\SendComposedEmail;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFactory;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

beforeEach(function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'sender@example.test',
        'name' => 'Sender Team',
        'editable' => true,
        'allowed_domains' => ['example.test'],
    ]);
    config()->set('filament-cloudflare-mail-sender.reply_to', [
        'address' => 'replies@example.test',
        'name' => 'Replies Team',
    ]);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', 'local');
    config()->set('filament-cloudflare-mail-sender.attachments.directory', 'mail-attachments');
    config()->set('filament-cloudflare-mail-sender.attachments.max_file_kib', 2048);
    config()->set('filament-cloudflare-mail-sender.mailer', 'ui-array');
    config()->set('mail.mailers.ui-array', ['transport' => 'array']);
    config()->set('filament-cloudflare-mail-sender.queue.connection', 'sync');
    config()->set('filament-cloudflare-mail-sender.queue.name', 'mail-ui');
    Storage::fake('local');
    Queue::fake();
    RateLimiter::clear(composeRateKey('composer-1'));

    app()->instance(RecipientSuggestionProvider::class, new class implements RecipientSuggestionProvider
    {
        public function suggest(string $search = ''): array
        {
            return [
                new RecipientSuggestion('known@example.test', 'Known User <known@example.test>'),
            ];
        }
    });

    $panel = Panel::make()
        ->id('mail')
        ->default()
        ->plugin(FilamentCloudflareMailSenderPlugin::make());
    Filament::setCurrentPanel($panel);
    Auth::guard('web')->setUser(new TestActor('composer-1'));
});

test('compose exposes native fields and a single send action without draft or preview ceremony', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $component->assertOk();
    $component->assertSee(__('filament-cloudflare-mail-sender::messages.actions.send'));
    $component->assertDontSee('Save draft');
    $component->assertDontSee('Preview email');
    $component->assertDontSee('Discard draft');
    $component->assertDontSee('Queue email');
    $component->assertDontSee('New tag');
    $component
        ->assertSchemaStateSet([
            'from_address' => 'sender@example.test',
            'from_name' => 'Sender Team',
            'reply_to_address' => 'replies@example.test',
            'reply_to_name' => 'Replies Team',
            'to' => [],
            'cc' => [],
            'bcc' => [],
        ])
        ->assertSchemaComponentExists('from_address', 'form', static fn (mixed $component): bool => $component instanceof TextInput
            && ! $component->isDisabled()
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.from_address'))
        ->assertSchemaComponentExists('from_name', 'form', static fn (mixed $component): bool => $component instanceof TextInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.from_name'))
        ->assertSchemaComponentExists('reply_to_address', 'form', static fn (mixed $component): bool => $component instanceof TextInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.reply_to_address'))
        ->assertSchemaComponentExists('reply_to_name', 'form', static fn (mixed $component): bool => $component instanceof TextInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.reply_to_name'))
        ->assertSchemaComponentExists('subject', 'form', static fn (mixed $component): bool => $component instanceof TextInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.subject'))
        ->assertSchemaComponentExists('to', 'form', static fn (mixed $component): bool => $component instanceof TagsInput
            && $component->getSuggestions() === ['Known User <known@example.test>']
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.recipient'))
        ->assertSchemaComponentExists('cc', 'form', static fn (mixed $component): bool => $component instanceof TagsInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.recipient'))
        ->assertSchemaComponentExists('bcc', 'form', static fn (mixed $component): bool => $component instanceof TagsInput
            && $component->getPlaceholder() === __('filament-cloudflare-mail-sender::messages.placeholders.recipient'))
        ->assertSchemaComponentExists('body', 'form', static fn (mixed $component): bool => $component instanceof MarkdownEditor)
        ->assertSchemaComponentExists('attachments', 'form', static function (mixed $component): bool {
            return $component instanceof FileUpload
                && $component->isMultiple()
                && $component->getDiskName() === 'local'
                && str_starts_with((string) $component->getDirectory(), 'mail-attachments/');
        });
});

test('send uses a confirmed Filament action and notifies when the email is queued', function (): void {
    $component = Livewire::test(ComposeEmail::class)->fillForm(composeValidState());

    $component->callAction(TestAction::make('send')->schemaComponent('send-actions'));
    $component->assertHasNoErrors();
    $component->assertNotified(__('filament-cloudflare-mail-sender::messages.notifications.email_queued'));

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendComposedEmail::class);
});

test('send persists one audit row, dispatches once, and resets the composer', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $initialProof = $component->get('uploadScopeProof');

    $component
        ->fillForm(composeValidState([
            'to' => ['Known User <known@example.test>', 'outside@example.test'],
            'cc' => ['KNOWN@example.test'],
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    $message = CloudflareMailMessage::query()->sole();

    expect($message->status)->toBe(MessageStatus::Queued)
        ->and($message->to)->toBe(['known@example.test', 'outside@example.test'])
        ->and($message->cc)->toBe([])
        ->and($message->actor_id)->toBe('composer-1')
        ->and($message->send_token)->not->toBeNull()
        ->and($component->get('uploadScopeProof'))->not->toBe($initialProof)
        ->and($component->get('data.subject'))->toBe('')
        ->and($component->get('data.to'))->toBe([]);

    Queue::assertPushedTimes(SendComposedEmail::class, 1);
});

test('send persists an edited from address and name when sender editing is enabled', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'billing@example.test',
            'from_name' => 'Billing Desk',
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    $message = CloudflareMailMessage::query()->sole();

    expect($message->from_address)->toBe('billing@example.test')
        ->and($message->from_name)->toBe('Billing Desk')
        ->and($message->status)->toBe(MessageStatus::Queued);
});

test('send rejects an edited from address outside allowed sending domains', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'attacker@untrusted.test',
            'from_name' => 'Attacker',
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('send rejects a blank from address while sender editing is enabled', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => '',
            'from_name' => 'Nameless',
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data.from_address']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send rejects an invalid from address while sender editing is enabled', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'not-an-email',
            'from_name' => 'Broken',
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data.from_address']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send can clear from name while keeping a valid editable from address', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'billing@example.test',
            'from_name' => null,
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->sole()->from_name)->toBeNull();
});

test('send ignores a forged sender when sender editing is locked', function (): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', false);

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'attacker@untrusted.test',
            'from_name' => 'Attacker',
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    $message = CloudflareMailMessage::query()->sole();

    expect($message->from_address)->toBe('sender@example.test')
        ->and($message->from_name)->toBe('Sender Team');
});

test('invalid arbitrary recipient tags stay on compose without creating a message', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState(['to' => ['not-an-email']]))
        ->call('sendEmail')
        ->assertHasErrors(['data.to']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('send requires a Reply-To address whenever a Reply-To name is present', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'reply_to_address' => null,
            'reply_to_name' => 'Support team',
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data.reply_to_name']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('duplicate prefill copies message fields without attachments or send token', function (): void {
    $source = composeAuditMessage([
        'to' => ['source@example.test'],
        'subject' => 'Source subject',
        'markdown_body' => 'Source body',
        'from_address' => 'historical@example.test',
        'from_name' => 'Historical Sender',
        'attachments' => [[
            'disk' => 'local',
            'path' => 'deleted.pdf',
            'name' => 'deleted.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'sha256' => str_repeat('a', 64),
        ]],
        'send_token' => 'source-token',
        'status' => MessageStatus::Sent,
    ]);

    Livewire::withQueryParams(['duplicate' => $source->id]);
    Livewire::test(ComposeEmail::class)
        ->assertSchemaStateSet([
            'to' => ['source@example.test'],
            'subject' => 'Source subject',
            'body' => 'Source body',
            'from_address' => 'historical@example.test',
            'from_name' => 'Historical Sender',
            'attachments' => [],
            'attachment_names' => [],
        ]);
});

test('duplicate prefill keeps the configured sender when from editing is locked', function (): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', false);

    $source = composeAuditMessage([
        'from_address' => 'historical@old-domain.test',
        'from_name' => 'Historical Sender',
    ]);

    Livewire::withQueryParams(['duplicate' => $source->id]);
    Livewire::test(ComposeEmail::class)
        ->assertSchemaStateSet([
            'from_address' => 'sender@example.test',
            'from_name' => 'Sender Team',
        ]);
});

test('locked compose from fields fall back to Laravel mail.from when package from is empty', function (): void {
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

    Livewire::test(ComposeEmail::class)
        ->assertSchemaStateSet([
            'from_address' => 'ops@example.test',
            'from_name' => 'Operations',
        ])
        ->assertSchemaComponentExists('from_address', 'form', static fn (mixed $component): bool => $component instanceof TextInput && $component->isDisabled());
});

test('compose access uses the plugin callback on every Livewire request', function (): void {
    $panel = Panel::make()
        ->id('restricted-mail')
        ->default()
        ->plugin(FilamentCloudflareMailSenderPlugin::make()
            ->authorizeComposeUsing(static fn (): bool => false));
    Filament::setCurrentPanel($panel);

    Livewire::test(ComposeEmail::class)->assertForbidden();
});

test('send actions recheck authorization after the page was mounted', function (): void {
    $allowed = true;
    $plugin = FilamentCloudflareMailSenderPlugin::make()
        ->authorizeComposeUsing(static function () use (&$allowed): bool {
            return $allowed;
        });
    $panel = Panel::make()->id('changing-auth')->default()->plugin($plugin);
    Filament::setCurrentPanel($panel);

    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState());

    $allowed = false;

    $component->call('sendEmail')->assertForbidden();
    expect(CloudflareMailMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('send is limited to five attempts per actor per minute', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState());

    foreach (range(1, 5) as $_) {
        RateLimiter::hit(composeRateKey('composer-1'), 60);
    }

    $component
        ->call('sendEmail')
        ->assertHasErrors(['send']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a second send after success uses a new scope and does not double-dispatch the first message', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $component->fillForm(composeValidState());
    $component->call('sendEmail');
    $component->assertHasNoErrors();

    $first = CloudflareMailMessage::query()->sole();
    $firstToken = $first->send_token;

    $component->fillForm(composeValidState(['subject' => 'Second message']));
    $component->call('sendEmail');
    $component->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->count())->toBe(2)
        ->and($first->refresh()->send_token)->toBe($firstToken);

    Queue::assertPushedTimes(SendComposedEmail::class, 2);
});

test('send exposes invalid attachment policy configuration on the attachment field', function (): void {
    $component = Livewire::test(ComposeEmail::class)->fillForm(composeValidState());
    config()->set('filament-cloudflare-mail-sender.attachments.max_file_kib', 'invalid');
    app()->forgetInstance(AttachmentPolicy::class);
    app()->forgetInstance(MessageSnapshotFactory::class);

    $component
        ->call('sendEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send exposes invalid delivery configuration without losing form state', function (): void {
    $component = Livewire::test(ComposeEmail::class)->fillForm(composeValidState());
    config()->set('filament-cloudflare-mail-sender.mailer', null);

    $component->call('sendEmail');
    $component->assertHasErrors(['data']);
    $component->assertSet('data.subject', 'Transactional update');

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send exposes invalid queue configuration as an actionable error', function (): void {
    config()->set('filament-cloudflare-mail-sender.queue.name', null);

    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState());
    $component->call('sendEmail');
    $component->assertHasErrors(['send']);
    $component->assertNotified(__('filament-cloudflare-mail-sender::messages.notifications.email_not_queued'));

    expect(CloudflareMailMessage::query()->count())->toBe(1)
        ->and(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Draft)
        ->and(CloudflareMailMessage::query()->sole()->send_token)->toBeNull();
    Queue::assertNothingPushed();
});

test('send queues successfully when the published package queue connection is null', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('filament-cloudflare-mail-sender.queue.connection', null);

    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState());
    $component->call('sendEmail');
    $component->assertHasNoErrors();
    $component->assertNotified(__('filament-cloudflare-mail-sender::messages.notifications.email_queued'));

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendComposedEmail::class);
});

test('send restores the draft and reports when queue dispatch fails', function (): void {
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('The compose test requires a queue fake.');
    }

    $queue->beforePushing(static function (): never {
        throw new RuntimeException('The queue backend is unavailable.');
    });

    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState());
    $component->call('sendEmail');
    $component->assertHasErrors(['send']);
    $component->assertNotified(__('filament-cloudflare-mail-sender::messages.notifications.email_not_queued'));

    $message = CloudflareMailMessage::query()->sole();

    expect($message->status)->toBe(MessageStatus::Draft)
        ->and($message->send_token)->toBeNull();
});

test('send succeeds when attachments are disabled even if the attachment disk is unset', function (): void {
    config()->set('filament-cloudflare-mail-sender.attachments.enabled', false);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', null);
    app()->forgetInstance(AttachmentPolicy::class);
    app()->forgetInstance(MessageSnapshotFactory::class);

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('sendEmail')
        ->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Queued)
        ->and(CloudflareMailMessage::query()->sole()->attachments)->toBe([]);
});

test('send rejects forged attachment paths when attachments are disabled', function (): void {
    config()->set('filament-cloudflare-mail-sender.attachments.enabled', false);
    config()->set('filament-cloudflare-mail-sender.attachments.disk', null);
    app()->forgetInstance(AttachmentPolicy::class);
    app()->forgetInstance(MessageSnapshotFactory::class);

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->set('data.attachments', ['mail-attachments/forged.pdf'])
        ->set('data.attachment_names', ['mail-attachments/forged.pdf' => 'forged.pdf'])
        ->call('sendEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send accepts an attachment only from the exact actor-owned page scope', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $path = composeUploadDirectory($component).'/invoice.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nOwned invoice");

    $component
        ->fillForm(composeValidState([
            'attachments' => [$path],
            'attachment_names' => [$path => 'invoice.pdf'],
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->sole()->attachments[0]['path'])->toBe($path);
});

test('send prunes stray files from the verified scope that never entered the message ledger', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $directory = composeUploadDirectory($component);
    $keptPath = $directory.'/kept.pdf';
    $strayPath = $directory.'/removed-before-validation.pdf';
    Storage::disk('local')->put($keptPath, "%PDF-1.4\nKept");
    Storage::disk('local')->put($strayPath, "%PDF-1.4\nStray");

    $component
        ->fillForm(composeValidState([
            'attachments' => [$keptPath],
            'attachment_names' => [$keptPath => 'kept.pdf'],
        ]))
        ->call('sendEmail')
        ->assertHasNoErrors();

    expect(Storage::disk('local')->exists($keptPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($strayPath))->toBeFalse();
});

test('one compose page cannot claim another page upload scope for the same actor', function (): void {
    $firstPage = Livewire::test(ComposeEmail::class);
    $foreignPath = composeUploadDirectory($firstPage).'/private.pdf';
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nPrivate");

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'attachments' => [$foreignPath],
            'attachment_names' => [$foreignPath => 'private.pdf'],
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('send rejects arbitrary and other-scope attachment paths under the configured disk', function (string $path): void {
    Storage::disk('local')->put($path, "%PDF-1.4\nSensitive");

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'attachments' => [$path],
            'attachment_names' => [$path => 'sensitive.pdf'],
        ]))
        ->call('sendEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
})->with([
    'outside configured root' => 'private/sensitive.pdf',
    'different upload scope' => 'mail-attachments/another-actor/another-scope/sensitive.pdf',
]);

test('the FileUpload delete callback removes an unpersisted file only from its verified page scope', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $ownedPath = composeUploadDirectory($component).'/removed-before-save.pdf';
    $foreignPath = 'mail-attachments/foreign/scope/untouched.pdf';
    Storage::disk('local')->put($ownedPath, "%PDF-1.4\nOwned");
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nForeign");

    $component->fillForm([
        'attachments' => [$ownedPath],
        'attachment_names' => [$ownedPath => 'removed-before-save.pdf'],
    ]);
    composeAttachmentField($component)->deleteUploadedFile('0');

    expect(Storage::disk('local')->exists($ownedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($foreignPath))->toBeTrue();
});

test('compose navigation falls back safely for invalid package configuration', function (): void {
    config()->set('filament-cloudflare-mail-sender.navigation', [
        'compose_label' => '',
        'group' => 123,
        'icon' => false,
        'sort' => '31',
    ]);

    expect(ComposeEmail::getNavigationLabel())->toBe('Compose Email')
        ->and(ComposeEmail::getNavigationGroup())->toBeNull()
        ->and(ComposeEmail::getNavigationIcon())->toBeNull()
        ->and(ComposeEmail::getNavigationSort())->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function composeValidState(array $overrides = []): array
{
    return array_merge([
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender Team',
        'reply_to_address' => 'replies@example.test',
        'reply_to_name' => 'Replies Team',
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Transactional update',
        'body' => 'Hello **recipient**',
        'attachments' => [],
        'attachment_names' => [],
    ], $overrides);
}

function composeRateKey(string $identifier): string
{
    return 'filament-cloudflare-mail-sender:confirm:'.hash('sha256', TestActor::class.':'.$identifier);
}

/** @param Testable<ComposeEmail> $component */
function composeUploadDirectory(Testable $component): string
{
    $proof = $component->get('uploadScopeProof');

    if (! is_string($proof)) {
        throw new LogicException('The compose upload scope proof is unavailable.');
    }

    return resolve(AttachmentUploadScope::class)
        ->inspect(new TestActor('composer-1'), $proof)
        ->directory;
}

/** @param Testable<ComposeEmail> $component */
function composeAttachmentField(Testable $component): FileUpload
{
    $field = $component->instance()->getSchema('form')?->getComponent('attachments');

    if (! $field instanceof FileUpload) {
        throw new LogicException('The compose attachment field is unavailable.');
    }

    return $field;
}

/** @param array<string, mixed> $overrides */
function composeAuditMessage(array $overrides = []): CloudflareMailMessage
{
    return CloudflareMailMessage::query()->create(array_merge([
        'actor_type' => TestActor::class,
        'actor_id' => 'composer-1',
        'mailer' => 'ui-array',
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender Team',
        'reply_to_address' => null,
        'reply_to_name' => null,
        'to' => ['recipient@example.test'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Audit message',
        'markdown_body' => 'Audit body',
        'html_body' => '<p>Audit body</p>',
        'text_body' => 'Audit body',
        'attachments' => [],
        'status' => MessageStatus::Draft,
    ], $overrides));
}
