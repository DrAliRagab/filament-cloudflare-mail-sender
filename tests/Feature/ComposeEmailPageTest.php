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
use DrAliRagab\FilamentCloudflareMailSender\Support\AuditBodyStoragePolicy;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFactory;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\TestActor;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

beforeEach(function (): void {
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => 'sender@example.test',
        'name' => 'Sender Team',
        'editable' => false,
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

test('compose uses native Filament fields with configured defaults and upload policy', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $component->assertOk();
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
        ->assertSchemaComponentExists('from_address', 'form', static fn (mixed $component): bool => $component instanceof TextInput && $component->isDisabled())
        ->assertSchemaComponentExists('to', 'form', static fn (mixed $component): bool => $component instanceof TagsInput && $component->getSuggestions() === ['Known User <known@example.test>'])
        ->assertSchemaComponentExists('cc', 'form', static fn (mixed $component): bool => $component instanceof TagsInput)
        ->assertSchemaComponentExists('bcc', 'form', static fn (mixed $component): bool => $component instanceof TagsInput)
        ->assertSchemaComponentExists('body', 'form', static fn (mixed $component): bool => $component instanceof MarkdownEditor)
        ->assertSchemaComponentExists('attachments', 'form', static function (mixed $component): bool {
            return $component instanceof FileUpload
                && $component->isMultiple()
                && $component->shouldPreserveFilenames()
                && $component->getDiskName() === 'local'
                && str_starts_with((string) $component->getDirectory(), 'mail-attachments/')
                && $component->getMaxSize() === 2048;
        });
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

test('save draft canonicalizes exact known labels and updates one current record', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'to' => ['Known User <known@example.test>', 'outside@example.test'],
            'cc' => ['KNOWN@example.test'],
        ]))
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $draft = CloudflareMailMessage::query()->sole();

    expect($draft->status)->toBe(MessageStatus::Draft)
        ->and($draft->to)->toBe(['known@example.test', 'outside@example.test'])
        ->and($draft->cc)->toBe([])
        ->and($draft->actor_id)->toBe('composer-1');

    $component
        ->fillForm(['subject' => 'Updated subject'])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    expect(CloudflareMailMessage::query()->count())->toBe(1)
        ->and($draft->refresh()->subject)->toBe('Updated subject');
});

test('invalid arbitrary recipient tags stay on compose without creating a draft', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState(['to' => ['not-an-email']]))
        ->call('saveDraft')
        ->assertHasErrors(['data.to']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('preview uses the delivery renderer and any edit invalidates its proof', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'body' => 'Hello **clinician**',
            'cc' => ['copy@example.test'],
            'bcc' => ['hidden@example.test'],
        ]))
        ->call('previewEmail')
        ->assertSet('preview.from', 'Sender Team <sender@example.test>')
        ->assertSet('preview.reply_to', 'Replies Team <replies@example.test>')
        ->assertSet('preview.to', ['recipient@example.test'])
        ->assertSet('preview.cc', ['copy@example.test'])
        ->assertSet('preview.bcc', ['hidden@example.test'])
        ->assertSet('preview.text', 'Hello clinician')
        ->assertSet('preview.html', fn (string $html): bool => str_contains($html, '<strong>clinician</strong>'));

    $previewProof = $component->get('previewProof');
    expect($previewProof)->toBeString()
        ->and($previewProof === '')->toBeFalse();

    $component
        ->set('data.subject', 'Changed after preview')
        ->assertSet('previewProof', null)
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    Queue::assertNothingPushed();
});

test('preview clearly preserves an absent optional Reply-To', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $component->fillForm(composeValidState([
        'reply_to_address' => null,
        'reply_to_name' => null,
    ]));
    $component->call('previewEmail');
    $component->assertHasNoErrors();
    $component->assertSet('preview.reply_to', null);
});

test('compose requires a Reply-To address whenever a Reply-To name is present', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'reply_to_address' => null,
            'reply_to_name' => 'Support team',
        ]))
        ->call('saveDraft')
        ->assertHasErrors(['data.reply_to_name']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('save and preview expose invalid attachment policy configuration on the attachment field', function (string $action): void {
    $component = Livewire::test(ComposeEmail::class)->fillForm(composeValidState());
    config()->set('filament-cloudflare-mail-sender.attachments.max_file_kib', 'invalid');
    app()->forgetInstance(AttachmentPolicy::class);
    app()->forgetInstance(MessageSnapshotFactory::class);

    $component
        ->call($action)
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
})->with([
    'save' => 'saveDraft',
    'preview' => 'previewEmail',
]);

test('save and preview expose invalid delivery configuration without losing form state', function (string $action): void {
    $component = Livewire::test(ComposeEmail::class)->fillForm(composeValidState());
    config()->set('filament-cloudflare-mail-sender.mailer', null);

    $component->call($action);
    $component->assertHasErrors(['data']);
    $component->assertSet('data.subject', 'Transactional update');

    expect(CloudflareMailMessage::query()->count())->toBe(0);
})->with([
    'save' => 'saveDraft',
    'preview' => 'previewEmail',
]);

test('fresh confirmation queues once and duplicate confirmations remain idempotent', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $component
        ->fillForm(composeValidState())
        ->call('previewEmail')
        ->call('queueEmail');
    $component->assertHasNoErrors();

    $message = CloudflareMailMessage::query()->sole();

    expect($message->status)->toBe(MessageStatus::Queued)
        ->and($message->send_token)->not->toBeNull();

    Queue::assertPushedTimes(SendComposedEmail::class, 1);

    $component->call('queueEmail');
    $component->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->count())->toBe(1);
    Queue::assertPushedTimes(SendComposedEmail::class, 1);
});

test('confirmation is limited to five attempts per actor per minute', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail');

    foreach (range(1, 5) as $_) {
        RateLimiter::hit(composeRateKey('composer-1'), 60);
    }

    $component
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    expect(CloudflareMailMessage::query()->count())->toBe(1)
        ->and(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Draft);
    Queue::assertNothingPushed();
});

test('discard cancels the current draft and clears composer identity', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('saveDraft');

    $draft = CloudflareMailMessage::query()->sole();

    $component
        ->call('discardDraft')
        ->assertSet('currentDraftId', null)
        ->assertSet('previewProof', null);

    expect($draft->refresh()->status)->toBe(MessageStatus::Cancelled)
        ->and($draft->cancelled_at)->not->toBeNull();
});

test('discard keeps a concurrently queued draft and reports the terminal conflict', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('saveDraft');
    $draft = CloudflareMailMessage::query()->sole();
    $draft->forceFill([
        'status' => MessageStatus::Queued,
        'send_token' => 'concurrent-send-token',
    ])->save();

    $component->call('discardDraft');
    $component->assertHasErrors(['data']);
    $component->assertSet('currentDraftId', $draft->id);

    expect($draft->refresh()->status)->toBe(MessageStatus::Queued);
});

test('a losing save cleans only untracked files after the authoritative draft is queued', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $directory = composeUploadDirectory($component);
    $queuedPath = $directory.'/queued.pdf';
    $losingPath = $directory.'/losing-save.pdf';
    $foreignPath = 'mail-attachments/foreign/scope/untouched.pdf';
    Storage::disk('local')->put($queuedPath, "%PDF-1.4\nQueued");
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nForeign");
    $component
        ->fillForm(composeValidState([
            'attachments' => [$queuedPath],
            'attachment_names' => [$queuedPath => 'queued.pdf'],
        ]))
        ->call('saveDraft');

    $queued = CloudflareMailMessage::query()->sole();
    $sendToken = 'concurrent-queue-token';
    $queued->forceFill([
        'status' => MessageStatus::Queued,
        'send_token' => $sendToken,
        'queued_at' => now(),
    ])->save();
    Storage::disk('local')->put($losingPath, "%PDF-1.4\nLosing save");

    $component
        ->fillForm(composeValidState([
            'subject' => 'Losing content',
            'body' => 'Losing body',
            'attachments' => [$losingPath],
            'attachment_names' => [$losingPath => 'losing-save.pdf'],
        ]))
        ->call('saveDraft')
        ->assertHasErrors(['data']);

    $queued->refresh();

    expect(Storage::disk('local')->exists($losingPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($queuedPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($foreignPath))->toBeTrue()
        ->and($queued->subject)->toBe('Transactional update')
        ->and($queued->markdown_body)->toBe('Hello **recipient**')
        ->and($queued->attachments)->toHaveCount(1)
        ->and($queued->attachments[0]['path'])->toBe($queuedPath)
        ->and($queued->status)->toBe(MessageStatus::Queued)
        ->and($queued->send_token)->toBe($sendToken);
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

test('duplicate prefill copies message fields without attachments draft identity or send token', function (): void {
    $source = composeAuditMessage([
        'to' => ['source@example.test'],
        'subject' => 'Source subject',
        'markdown_body' => 'Source body',
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
            'attachments' => [],
            'attachment_names' => [],
        ])
        ->assertSet('currentDraftId', null)
        ->assertSet('previewProof', null);
});

test('duplicate prefill keeps the current configured sender when from editing is locked', function (): void {
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

test('compose actions recheck authorization after the page was mounted', function (): void {
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

    $component->call('saveDraft')->assertForbidden();
    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('preview persists one authoritative draft before confirmation', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail')
        ->assertSet('currentDraftId', fn (?string $id): bool => is_string($id) && $id !== '')
        ->assertSet('previewProof', fn (?string $proof): bool => is_string($proof) && $proof !== '');

    $draft = CloudflareMailMessage::query()->sole();

    expect($draft->status)->toBe(MessageStatus::Draft)
        ->and($component->get('currentDraftId'))->toBe($draft->id);

    $component->call('queueEmail');

    expect(CloudflareMailMessage::query()->count())->toBe(1);
});

test('preview ignores a forged sender when sender editing is locked', function (): void {
    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'from_address' => 'attacker@untrusted.test',
            'from_name' => 'Attacker',
        ]))
        ->call('previewEmail')
        ->assertSet('preview.from', 'Sender Team <sender@example.test>');

    $draft = CloudflareMailMessage::query()->sole();

    expect($draft->from_address)->toBe('sender@example.test')
        ->and($draft->from_name)->toBe('Sender Team');
});

test('queue rejects a forged preview proof', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail');

    $component
        ->set('previewProof', 'forged-client-proof')
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Draft);
    Queue::assertNothingPushed();
});

test('queue rejects a valid proof after the authoritative draft changes', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail');

    CloudflareMailMessage::query()->sole()->update(['subject' => 'Changed concurrently']);

    $component
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Draft);
    Queue::assertNothingPushed();
});

test('queue configuration failures are returned as actionable preview validation', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail');
    config()->set('filament-cloudflare-mail-sender.queue.name', null);

    $component
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    expect(CloudflareMailMessage::query()->sole()->status)->toBe(MessageStatus::Draft);
    Queue::assertNothingPushed();
});

test('queue dispatch failures are actionable and restore the authoritative draft', function (): void {
    $component = Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState())
        ->call('previewEmail');
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('The compose test requires a queue fake.');
    }

    $queue->beforePushing(static function (): never {
        throw new RuntimeException('Queue unavailable.');
    });

    $component
        ->call('queueEmail')
        ->assertHasErrors(['preview']);

    $draft = CloudflareMailMessage::query()->sole();

    expect($draft->status)->toBe(MessageStatus::Draft)
        ->and($draft->send_token)->toBeNull()
        ->and($draft->queued_at)->toBeNull();
});

test('discard cannot cancel a draft owned by another actor', function (): void {
    $otherDraft = composeAuditMessage([
        'actor_type' => TestActor::class,
        'actor_id' => 'other-actor',
    ]);
    $component = Livewire::test(ComposeEmail::class);

    expect(fn () => $component->set('currentDraftId', $otherDraft->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($otherDraft->refresh()->status)->toBe(MessageStatus::Draft)
        ->and($otherDraft->cancelled_at)->toBeNull();
});

test('preview accepts an attachment only from the exact actor-owned page scope', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $path = composeUploadDirectory($component).'/invoice.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nOwned invoice");

    $component
        ->fillForm(composeValidState([
            'attachments' => [$path],
            'attachment_names' => [$path => 'invoice.pdf'],
        ]))
        ->call('previewEmail')
        ->assertHasNoErrors();

    expect(CloudflareMailMessage::query()->sole()->attachments[0]['path'])->toBe($path);
});

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

test('the FileUpload delete boundary refuses a client-supplied foreign-scope path', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $foreignPath = 'mail-attachments/foreign/scope/untouched.pdf';
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nForeign");
    $component->fillForm([
        'attachments' => [$foreignPath],
        'attachment_names' => [$foreignPath => 'untouched.pdf'],
    ]);
    composeAttachmentField($component)->deleteUploadedFile('0');

    expect(Storage::disk('local')->exists($foreignPath))->toBeTrue()
        ->and($component->get('data.attachments'))->toBe([$foreignPath]);
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
        ->call('previewEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
});

test('saving removes only files deleted from the current draft attachment ledger', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $directory = composeUploadDirectory($component);
    $removedPath = $directory.'/removed.pdf';
    $keptPath = $directory.'/kept.pdf';
    Storage::disk('local')->put($removedPath, "%PDF-1.4\nRemoved");
    Storage::disk('local')->put($keptPath, "%PDF-1.4\nKept");

    $component
        ->fillForm(composeValidState([
            'attachments' => [$removedPath, $keptPath],
            'attachment_names' => [$removedPath => 'removed.pdf', $keptPath => 'kept.pdf'],
        ]))
        ->call('saveDraft')
        ->fillForm([
            'attachments' => [$keptPath],
            'attachment_names' => [$keptPath => 'kept.pdf'],
        ])
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Storage::disk('local')->exists($removedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($keptPath))->toBeTrue();
});

test('saving prunes stray files from the verified scope that never entered the draft ledger', function (): void {
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
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Storage::disk('local')->exists($keptPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($strayPath))->toBeFalse();
});

test('discard enumerates the exact verified scope so untracked uploads are cleaned', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $directory = composeUploadDirectory($component);
    $untrackedPath = $directory.'/never-in-form.pdf';
    $foreignPath = 'mail-attachments/foreign/scope/keep.pdf';
    Storage::disk('local')->put($untrackedPath, "%PDF-1.4\nUntracked");
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nForeign");

    $component->call('discardDraft')->assertHasNoErrors();

    expect(Storage::disk('local')->exists($untrackedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($foreignPath))->toBeTrue();
});

test('discard cleans saved and unsaved owned uploads without deleting a foreign path and scrubs cancelled bodies', function (): void {
    config()->set('filament-cloudflare-mail-sender.audit.store_body', false);
    app()->forgetInstance(AuditBodyStoragePolicy::class);
    $component = Livewire::test(ComposeEmail::class);
    $directory = composeUploadDirectory($component);
    $savedPath = $directory.'/saved.pdf';
    $unsavedPath = $directory.'/unsaved.pdf';
    $foreignPath = 'mail-attachments/foreign/scope/keep.pdf';
    Storage::disk('local')->put($savedPath, "%PDF-1.4\nSaved");
    Storage::disk('local')->put($unsavedPath, "%PDF-1.4\nUnsaved");
    Storage::disk('local')->put($foreignPath, "%PDF-1.4\nForeign");

    $component
        ->fillForm(composeValidState([
            'attachments' => [$savedPath],
            'attachment_names' => [$savedPath => 'saved.pdf'],
        ]))
        ->call('saveDraft');

    $draft = CloudflareMailMessage::query()->sole();

    $component
        ->fillForm([
            'attachments' => [$unsavedPath, $foreignPath],
            'attachment_names' => [$unsavedPath => 'unsaved.pdf', $foreignPath => 'keep.pdf'],
        ])
        ->call('discardDraft')
        ->assertHasNoErrors();

    $draft->refresh();

    expect(Storage::disk('local')->exists($savedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($unsavedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($foreignPath))->toBeTrue()
        ->and($draft->status)->toBe(MessageStatus::Cancelled)
        ->and($draft->markdown_body)->toBeNull()
        ->and($draft->html_body)->toBeNull()
        ->and($draft->text_body)->toBeNull();
});

test('a failed discard cleanup keeps the draft and composer retryable until deletion succeeds', function (): void {
    $component = Livewire::test(ComposeEmail::class);
    $path = composeUploadDirectory($component).'/retry.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nRetry");
    $component
        ->fillForm(composeValidState([
            'attachments' => [$path],
            'attachment_names' => [$path => 'retry.pdf'],
        ]))
        ->call('saveDraft');
    $draft = CloudflareMailMessage::query()->sole();
    $realDisk = Storage::disk('local');
    $filesystems = resolve(FilesystemManager::class);
    $filesystems->set('local', new class($realDisk->getDriver(), $realDisk->getAdapter(), $realDisk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    });

    $component->call('discardDraft');
    $component->assertHasErrors(['data.attachments']);
    $component->assertSet('currentDraftId', $draft->id);

    expect($draft->refresh()->status)->toBe(MessageStatus::Draft)
        ->and($realDisk->exists($path))->toBeTrue();

    $filesystems->set('local', $realDisk);
    $component->call('discardDraft');
    $component->assertHasNoErrors();
    $component->assertSet('currentDraftId', null);

    expect($draft->refresh()->status)->toBe(MessageStatus::Cancelled)
        ->and($realDisk->exists($path))->toBeFalse();
});

test('preview rejects arbitrary and other-scope attachment paths under the configured disk', function (string $path): void {
    Storage::disk('local')->put($path, "%PDF-1.4\nSensitive");

    Livewire::test(ComposeEmail::class)
        ->fillForm(composeValidState([
            'attachments' => [$path],
            'attachment_names' => [$path => 'sensitive.pdf'],
        ]))
        ->call('previewEmail')
        ->assertHasErrors(['data.attachments']);

    expect(CloudflareMailMessage::query()->count())->toBe(0);
})->with([
    'outside configured root' => 'private/sensitive.pdf',
    'different upload scope' => 'mail-attachments/another-actor/another-scope/sensitive.pdf',
]);

/** @param array<string, mixed> $overrides
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
