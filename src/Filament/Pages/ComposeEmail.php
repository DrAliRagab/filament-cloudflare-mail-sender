<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Filament\Pages;

use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentPolicy;
use DrAliRagab\FilamentCloudflareMailSender\Attachments\AttachmentUploadScope;
use DrAliRagab\FilamentCloudflareMailSender\Contracts\RecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Data\ComposedMessageData;
use DrAliRagab\FilamentCloudflareMailSender\Data\RecipientSuggestion;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidAttachment;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidMessageSnapshot;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidRecipientAddress;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MessageDispatchFailed;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MissingToRecipient;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\RecipientLimitExceeded;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\Support\ConfiguredSender;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageDeliveryService;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageSnapshotFactory;
use DrAliRagab\FilamentCloudflareMailSender\Support\OwnedDraftManager;
use DrAliRagab\FilamentCloudflareMailSender\Support\RecipientAddressSet;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;

final class ComposeEmail extends Page
{
    protected string $view = 'filament-cloudflare-mail-sender::filament.pages.compose-email';

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public string $uploadScopeProof;

    /** @var array<string, string> */
    private array $suggestionEmailsByLabel = [];

    public static function canAccess(): bool
    {
        return self::plugin()->canCompose(self::actor());
    }

    public function mount(): void
    {
        $this->authorizeCompose();

        $actor = $this->requiredActor();
        $this->uploadScopeProof = resolve(AttachmentUploadScope::class)->create($actor)->proof;

        $this->formSchema()->fill($this->initialState());

        $duplicateId = request()->query('duplicate');

        if (is_string($duplicateId) && $duplicateId !== '') {
            $this->fillFromDuplicate($duplicateId);
        }
    }

    public function hydrate(): void
    {
        $this->authorizeCompose();
    }

    public function form(Schema $schema): Schema
    {
        $attachmentConfig = config('filament-cloudflare-mail-sender.attachments', []);
        $attachmentConfig = is_array($attachmentConfig) ? $attachmentConfig : [];
        $fromEditable = ConfiguredSender::editable();
        $attachmentsEnabled = AttachmentPolicy::enabled();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('filament-cloudflare-mail-sender::messages.compose.sections.delivery'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('from_address')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.from_address'))
                            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.from_address'))
                            ->email()
                            ->required()
                            ->disabled(! $fromEditable)
                            ->dehydrated(),
                        TextInput::make('from_name')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.from_name'))
                            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.from_name'))
                            ->disabled(! $fromEditable)
                            ->dehydrated(),
                        TextInput::make('reply_to_address')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.reply_to_address'))
                            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.reply_to_address'))
                            ->email()
                            ->nullable(),
                        TextInput::make('reply_to_name')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.reply_to_name'))
                            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.reply_to_name'))
                            ->nullable(),
                    ]),
                Section::make(__('filament-cloudflare-mail-sender::messages.compose.sections.recipients'))
                    ->schema([
                        $this->recipientInput('to')
                            ->required()
                            ->helperText(fn (): string => trans_choice(
                                'filament-cloudflare-mail-sender::messages.compose.unique_recipients',
                                $this->uniqueRecipientCount(),
                                ['count' => $this->uniqueRecipientCount()],
                            )),
                        Section::make(__('filament-cloudflare-mail-sender::messages.compose.cc_bcc'))
                            ->collapsible()
                            ->collapsed(fn (Get $get): bool => blank($get('cc')) && blank($get('bcc')))
                            ->schema([
                                $this->recipientInput('cc'),
                                $this->recipientInput('bcc'),
                            ]),
                    ]),
                Section::make(__('filament-cloudflare-mail-sender::messages.compose.sections.message'))
                    ->schema([
                        TextInput::make('subject')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.subject'))
                            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.subject'))
                            ->required()
                            ->maxLength(255),
                        MarkdownEditor::make('body')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.body'))
                            ->required(),
                    ]),
                Section::make(__('filament-cloudflare-mail-sender::messages.compose.sections.attachments'))
                    ->visible($attachmentsEnabled)
                    ->schema([
                        FileUpload::make('attachments')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.attachments'))
                            ->disk(AttachmentPolicy::configuredDisk() ?? 'local')
                            ->directory(fn (): string => $this->ownedUploadDirectory())
                            ->preventFilePathTampering(
                                allowFilePathUsing: fn (string $file): bool => $this->isOwnedUploadPath($file),
                            )
                            ->deleteUploadedFileUsing(function (mixed $file): void {
                                if (is_string($file)) {
                                    $this->deleteOwnedUpload($file);
                                }
                            })
                            ->acceptedFileTypes($this->stringListConfig($attachmentConfig, 'allowed_mime_types'))
                            ->maxSize($this->integerConfig($attachmentConfig, 'max_file_kib'))
                            ->multiple()
                            ->preserveFilenames()
                            ->storeFileNamesIn('attachment_names'),
                    ]),
                Actions::make([
                    $this->sendAction(),
                ])->key('send-actions'),
            ]);
    }

    public function sendEmail(): void
    {
        $this->authorizeCompose();
        $this->resetErrorBag();

        $actor = $this->requiredActor();
        $rateKey = 'filament-cloudflare-mail-sender:confirm:'.hash('sha256', $actor::class.':'.$this->actorIdentifier($actor));

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->failSend(__('filament-cloudflare-mail-sender::messages.validation.rate_limited'));
        }

        RateLimiter::hit($rateKey, 60);

        try {
            $message = $this->persistAuthoritativeDraft();
            resolve(MessageDeliveryService::class)->queueComposed($message);
        } catch (InvalidAttachment $exception) {
            throw ValidationException::withMessages(['data.attachments' => $exception->getMessage()]);
        } catch (MessageDispatchFailed $exception) {
            $this->failSend($exception->getMessage());
        } catch (InvalidMessageSnapshot|LogicException $exception) {
            $field = str_contains($exception->getMessage(), 'queue')
                || str_contains($exception->getMessage(), 'Cloudflare mail transport')
                ? 'send'
                : 'data';

            if ($field === 'send') {
                $this->failSend($exception->getMessage());
            }

            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        Notification::make()
            ->title(__('filament-cloudflare-mail-sender::messages.notifications.email_queued'))
            ->success()
            ->send();

        $this->resetComposer();
    }

    public function sendAction(): Action
    {
        return Action::make('send')
            ->label(__('filament-cloudflare-mail-sender::messages.actions.send'))
            ->requiresConfirmation()
            ->modalHeading(__('filament-cloudflare-mail-sender::messages.actions.send'))
            ->modalDescription(__('filament-cloudflare-mail-sender::messages.actions.send_confirmation'))
            ->modalSubmitActionLabel(__('filament-cloudflare-mail-sender::messages.actions.send'))
            ->action(function (): void {
                $this->sendEmail();
            });
    }

    private function failSend(string $message): never
    {
        Notification::make()
            ->title(__('filament-cloudflare-mail-sender::messages.notifications.email_not_queued'))
            ->body($message)
            ->danger()
            ->send();

        throw ValidationException::withMessages(['send' => $message]);
    }

    public function uniqueRecipientCount(): int
    {
        try {
            return $this->recipientSet()->count();
        } catch (InvalidRecipientAddress|MissingToRecipient|RecipientLimitExceeded) {
            return 0;
        }
    }

    /** @return list<string> */
    public function duplicateRecipientWarnings(): array
    {
        try {
            return array_map(
                static fn ($duplicate): string => __('filament-cloudflare-mail-sender::messages.compose.duplicate_recipient', [
                    'email' => $duplicate->email,
                    'kept' => strtoupper($duplicate->keptIn),
                    'discarded' => strtoupper($duplicate->discardedFrom),
                ]),
                $this->recipientSet()->duplicates(),
            );
        } catch (InvalidRecipientAddress|MissingToRecipient|RecipientLimitExceeded) {
            return [];
        }
    }

    public static function getNavigationLabel(): string
    {
        return self::navigationString('compose_label', __('filament-cloudflare-mail-sender::messages.navigation.compose'));
    }

    public static function getNavigationGroup(): ?string
    {
        return self::nullableNavigationString('group');
    }

    public static function getNavigationIcon(): ?string
    {
        return self::nullableNavigationString('icon');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('filament-cloudflare-mail-sender.navigation.sort');

        return is_int($sort) ? $sort : null;
    }

    private function recipientInput(string $field): TagsInput
    {
        return TagsInput::make($field)
            ->label(__('filament-cloudflare-mail-sender::messages.fields.'.$field))
            ->placeholder(__('filament-cloudflare-mail-sender::messages.placeholders.recipient'))
            ->suggestions($this->recipientSuggestionLabels())
            ->live();
    }

    /** @return list<string> */
    private function recipientSuggestionLabels(): array
    {
        $suggestions = resolve(RecipientSuggestionProvider::class)->suggest();
        $this->suggestionEmailsByLabel = [];

        foreach ($suggestions as $suggestion) {
            $this->suggestionEmailsByLabel[$suggestion->label] = $suggestion->email;
        }

        return array_map(static fn (RecipientSuggestion $suggestion): string => $suggestion->label, $suggestions);
    }

    /** @param list<mixed> $tags
     * @return list<string>
     */
    public function canonicalizeRecipientTags(array $tags): array
    {
        if ($this->suggestionEmailsByLabel === []) {
            $this->recipientSuggestionLabels();
        }

        return array_map(function (mixed $tag): string {
            $tag = is_string($tag) ? trim($tag) : '';

            return $this->suggestionEmailsByLabel[$tag] ?? $tag;
        }, $tags);
    }

    private function recipientSet(): RecipientAddressSet
    {
        return RecipientAddressSet::from(
            $this->canonicalizeRecipientTags($this->listState('to')),
            $this->canonicalizeRecipientTags($this->listState('cc')),
            $this->canonicalizeRecipientTags($this->listState('bcc')),
        );
    }

    private function validatedMessageData(): ComposedMessageData
    {
        /** @var array<string, mixed> $state */
        $state = $this->formSchema()->getState();

        try {
            $recipients = RecipientAddressSet::from(
                $this->canonicalizeRecipientTags($this->listValue($state['to'] ?? [])),
                $this->canonicalizeRecipientTags($this->listValue($state['cc'] ?? [])),
                $this->canonicalizeRecipientTags($this->listValue($state['bcc'] ?? [])),
            );
        } catch (InvalidRecipientAddress|MissingToRecipient|RecipientLimitExceeded $exception) {
            throw ValidationException::withMessages(['data.to' => $exception->getMessage()]);
        }

        $subject = is_string($state['subject'] ?? null) ? trim($state['subject']) : '';
        $body = is_string($state['body'] ?? null) ? $state['body'] : '';
        $fromAddress = is_string($state['from_address'] ?? null) ? trim($state['from_address']) : null;
        $fromName = $this->nullableStateString($state['from_name'] ?? null);
        $replyToAddress = $this->nullableStateString($state['reply_to_address'] ?? null);
        $replyToName = $this->nullableStateString($state['reply_to_name'] ?? null);

        if ($replyToAddress === null && $replyToName !== null) {
            throw ValidationException::withMessages([
                'data.reply_to_name' => __('filament-cloudflare-mail-sender::messages.validation.reply_to_name_requires_address'),
            ]);
        }

        $actor = $this->requiredActor();
        $attachments = $this->attachmentState($state);

        if (! AttachmentPolicy::enabled()) {
            $forgedAttachments = $this->data['attachments'] ?? null;

            if ($attachments !== [] || (is_array($forgedAttachments) && $forgedAttachments !== [])) {
                throw ValidationException::withMessages([
                    'data.attachments' => 'Attachments are disabled.',
                ]);
            }
        } else {
            try {
                foreach ($attachments as $attachment) {
                    resolve(AttachmentUploadScope::class)->assertOwnsPath(
                        $actor,
                        $this->uploadScopeProof,
                        $attachment['path'],
                    );
                }
            } catch (InvalidAttachment $exception) {
                throw ValidationException::withMessages(['data.attachments' => $exception->getMessage()]);
            }
        }

        return new ComposedMessageData(
            actor: $actor,
            recipients: $recipients,
            subject: $subject,
            markdown: $body,
            attachments: $attachments,
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyToAddress: $replyToAddress,
            replyToName: $replyToName,
            useDeliveryOverrides: true,
        );
    }

    /** @param array<string, mixed> $state
     * @return list<array{path: string, name?: string}>
     */
    private function attachmentState(array $state): array
    {
        $paths = $this->listValue($state['attachments'] ?? []);
        $names = $state['attachment_names'] ?? [];
        $names = is_array($names) ? $names : [];

        return array_map(static function (string $path) use ($names): array {
            $name = $names[$path] ?? null;

            return is_string($name) && $name !== '' ? ['path' => $path, 'name' => $name] : ['path' => $path];
        }, $paths);
    }

    /** @return array<string, mixed> */
    private function initialState(): array
    {
        return [
            'from_address' => ConfiguredSender::address(),
            'from_name' => ConfiguredSender::name(),
            'reply_to_address' => config('filament-cloudflare-mail-sender.reply_to.address'),
            'reply_to_name' => config('filament-cloudflare-mail-sender.reply_to.name'),
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'subject' => '',
            'body' => '',
            'attachments' => [],
            'attachment_names' => [],
        ];
    }

    private function fillFromDuplicate(string $messageId): void
    {
        if (! self::plugin()->canViewAudit(self::actor())) {
            abort(403);
        }

        $message = CloudflareMailMessage::query()->findOrFail($messageId);
        $fromEditable = ConfiguredSender::editable();
        $this->formSchema()->fill([
            'from_address' => $fromEditable ? $message->from_address : ConfiguredSender::address(),
            'from_name' => $fromEditable ? $message->from_name : ConfiguredSender::name(),
            'reply_to_address' => $message->reply_to_address,
            'reply_to_name' => $message->reply_to_name,
            'to' => $message->to,
            'cc' => $message->cc,
            'bcc' => $message->bcc,
            'subject' => $message->subject,
            'body' => $message->markdown_body ?? '',
            'attachments' => [],
            'attachment_names' => [],
        ]);
    }

    private function resetComposer(): void
    {
        $this->uploadScopeProof = resolve(AttachmentUploadScope::class)->create($this->requiredActor())->proof;
        $this->formSchema()->fill($this->initialState());
    }

    private function persistAuthoritativeDraft(): CloudflareMailMessage
    {
        $actor = $this->requiredActor();
        $scope = resolve(AttachmentUploadScope::class)->inspect($actor, $this->uploadScopeProof);
        $data = $this->validatedMessageData();
        $manager = resolve(OwnedDraftManager::class);

        try {
            return DB::transaction(function () use ($actor, $scope, $data, $manager): CloudflareMailMessage {
                $draft = resolve(MessageSnapshotFactory::class)->saveOwnedDraft($scope->draftId, $data);
                $manager->pruneToAuthoritativeLedger($actor, $scope->draftId, $scope->proof);

                return $draft;
            });
        } catch (LogicException $exception) {
            if ($exception instanceof InvalidAttachment || $exception instanceof InvalidMessageSnapshot) {
                throw $exception;
            }

            $manager->pruneToAuthoritativeLedger($actor, $scope->draftId, $scope->proof);

            throw $exception;
        }
    }

    public function ownedUploadDirectory(): string
    {
        return resolve(AttachmentUploadScope::class)
            ->inspect($this->requiredActor(), $this->uploadScopeProof)
            ->directory;
    }

    public function isOwnedUploadPath(string $path): bool
    {
        try {
            resolve(AttachmentUploadScope::class)->assertOwnsPath(
                $this->requiredActor(),
                $this->uploadScopeProof,
                $path,
            );

            return true;
        } catch (InvalidAttachment) {
            return false;
        }
    }

    public function deleteOwnedUpload(string $path): void
    {
        $this->authorizeCompose();

        try {
            resolve(OwnedDraftManager::class)->deleteUpload(
                $this->requiredActor(),
                $this->uploadScopeProof,
                $path,
            );
        } catch (InvalidAttachment $exception) {
            throw ValidationException::withMessages(['data.attachments' => $exception->getMessage()]);
        }
    }

    private function authorizeCompose(): void
    {
        abort_unless(self::plugin()->canCompose(self::actor()), 403);
    }

    private static function plugin(): FilamentCloudflareMailSenderPlugin
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            abort(500);
        }

        $plugin = $panel->getPlugin('filament-cloudflare-mail-sender');

        if (! $plugin instanceof FilamentCloudflareMailSenderPlugin) {
            abort(500);
        }

        return $plugin;
    }

    private static function actor(): ?Authenticatable
    {
        return Filament::auth()->user();
    }

    private function requiredActor(): Authenticatable
    {
        $actor = self::actor();

        if ($actor === null) {
            abort(403);
        }

        return $actor;
    }

    private function formSchema(): Schema
    {
        $schema = $this->getSchema('form');

        if ($schema === null) {
            throw new LogicException('The compose form schema is unavailable.');
        }

        return $schema;
    }

    private function actorIdentifier(Authenticatable $actor): string
    {
        $identifier = $actor->getAuthIdentifier();

        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new InvalidMessageSnapshot('The authenticated actor must have a string or integer identifier.');
        }

        return (string) $identifier;
    }

    /** @return list<string> */
    private function listState(string $key): array
    {
        return $this->listValue($this->data[$key] ?? []);
    }

    /** @return list<string> */
    private function listValue(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function nullableStateString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<mixed> $config */
    private function integerConfig(array $config, string $key): int
    {
        $value = $config[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /** @param array<mixed> $config
     * @return list<string>
     */
    private function stringListConfig(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function navigationString(string $key, string $default): string
    {
        return self::nullableNavigationString($key) ?? $default;
    }

    private static function nullableNavigationString(string $key): ?string
    {
        $value = config("filament-cloudflare-mail-sender.navigation.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
