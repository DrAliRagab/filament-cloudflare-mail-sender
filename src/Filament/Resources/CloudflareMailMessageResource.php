<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Filament\Resources;

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Pages\ComposeEmail;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages\ListCloudflareMailMessages;
use DrAliRagab\FilamentCloudflareMailSender\Filament\Resources\CloudflareMailMessageResource\Pages\ViewCloudflareMailMessage;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use DrAliRagab\FilamentCloudflareMailSender\Models\CloudflareMailMessage;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Component;

final class CloudflareMailMessageResource extends Resource
{
    protected static ?string $model = CloudflareMailMessage::class;

    public static function canViewAny(): bool
    {
        return self::plugin()->canViewAudit(self::actor());
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof CloudflareMailMessage && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('filament-cloudflare-mail-sender::messages.fields.subject'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('filament-cloudflare-mail-sender::messages.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (MessageStatus $state): string => $state->label())
                    ->color(static fn (MessageStatus $state): string => match ($state) {
                        MessageStatus::Draft => 'gray',
                        MessageStatus::Queued, MessageStatus::Sending => 'info',
                        MessageStatus::Sent => 'success',
                        MessageStatus::Failed => 'danger',
                        MessageStatus::Cancelled => 'warning',
                    }),
                TextColumn::make('actor_id')
                    ->label(__('filament-cloudflare-mail-sender::messages.fields.actor'))
                    ->formatStateUsing(static fn (?string $state, CloudflareMailMessage $record): string => $record->actor_type === null
                        ? ($state ?? '—')
                        : class_basename($record->actor_type).' · '.($state ?? '—'))
                    ->searchable(['actor_type', 'actor_id']),
                TextColumn::make('created_at')
                    ->label(__('filament-cloudflare-mail-sender::messages.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('filament-cloudflare-mail-sender::messages.fields.status'))
                    ->options(self::statusOptions()),
                Filter::make('actor')
                    ->form([
                        TextInput::make('value')
                            ->label(__('filament-cloudflare-mail-sender::messages.fields.actor')),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || trim($value) === '') {
                            return $query;
                        }

                        $value = trim($value);

                        return $query->where(static function (Builder $query) use ($value): void {
                            $query->where('actor_type', 'like', "%{$value}%")
                                ->orWhere('actor_id', 'like', "%{$value}%");
                        });
                    }),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('filament-cloudflare-mail-sender::messages.filters.created_from')),
                        DatePicker::make('until')->label(__('filament-cloudflare-mail-sender::messages.filters.created_until')),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $fromValue = $data['from'] ?? null;
                        $untilValue = $data['until'] ?? null;
                        $from = is_string($fromValue) && $fromValue !== '' ? $fromValue : null;
                        $until = is_string($untilValue) && $untilValue !== '' ? $untilValue : null;

                        return $query
                            ->when($from !== null, static fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $from))
                            ->when($until !== null, static fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $until));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                self::duplicateAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-cloudflare-mail-sender::messages.audit.sections.lifecycle'))
                ->columns(2)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('filament-cloudflare-mail-sender::messages.fields.status'))
                        ->badge()
                        ->formatStateUsing(static fn (MessageStatus $state): string => $state->label())
                        ->belowContent(static fn (MessageStatus $state): ?string => $state === MessageStatus::Sent
                            ? self::translation('filament-cloudflare-mail-sender::messages.audit.accepted_help')
                            : null),
                    TextEntry::make('actor_id')
                        ->label(__('filament-cloudflare-mail-sender::messages.fields.actor')),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('queued_at')->dateTime()->placeholder('—'),
                    TextEntry::make('sent_at')->dateTime()->placeholder('—'),
                    TextEntry::make('failed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('attempt_count'),
                ]),
            Section::make(__('filament-cloudflare-mail-sender::messages.audit.sections.envelope'))
                ->columns(2)
                ->schema([
                    TextEntry::make('from_address')->label(__('filament-cloudflare-mail-sender::messages.fields.from_address')),
                    TextEntry::make('from_name')->label(__('filament-cloudflare-mail-sender::messages.fields.from_name'))->placeholder('—'),
                    TextEntry::make('reply_to_address')->label(__('filament-cloudflare-mail-sender::messages.fields.reply_to_address'))->placeholder('—'),
                    TextEntry::make('reply_to_name')->label(__('filament-cloudflare-mail-sender::messages.fields.reply_to_name'))->placeholder('—'),
                    TextEntry::make('to')->label(__('filament-cloudflare-mail-sender::messages.fields.to'))->listWithLineBreaks(),
                    TextEntry::make('cc')->label(__('filament-cloudflare-mail-sender::messages.fields.cc'))->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('bcc')->label(__('filament-cloudflare-mail-sender::messages.fields.bcc'))->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('subject')->label(__('filament-cloudflare-mail-sender::messages.fields.subject'))->columnSpanFull(),
                ]),
            Section::make(__('filament-cloudflare-mail-sender::messages.audit.sections.body'))
                ->schema([
                    TextEntry::make('html_body')
                        ->label(__('filament-cloudflare-mail-sender::messages.preview.html'))
                        ->formatStateUsing(static fn (?string $state): string|HtmlString => $state === null || $state === ''
                            ? self::translation('filament-cloudflare-mail-sender::messages.audit.body_unavailable')
                            : new HtmlString($state)),
                    TextEntry::make('text_body')
                        ->label(__('filament-cloudflare-mail-sender::messages.preview.text'))
                        ->placeholder(__('filament-cloudflare-mail-sender::messages.audit.body_unavailable')),
                    TextEntry::make('markdown_body')
                        ->label(__('filament-cloudflare-mail-sender::messages.audit.markdown_source'))
                        ->placeholder(__('filament-cloudflare-mail-sender::messages.audit.body_unavailable')),
                ]),
            Section::make(__('filament-cloudflare-mail-sender::messages.audit.sections.attachments'))
                ->schema([
                    TextEntry::make('attachments')
                        ->label(__('filament-cloudflare-mail-sender::messages.fields.attachments'))
                        ->listWithLineBreaks()
                        ->formatStateUsing(static function (mixed $state): string {
                            if (! is_array($state)) {
                                return '—';
                            }

                            $name = is_string($state['name'] ?? null) ? $state['name'] : '—';
                            $mimeType = is_string($state['mime_type'] ?? null) ? $state['mime_type'] : '—';
                            $size = is_int($state['size'] ?? null) ? ' · '.Number::fileSize($state['size']) : '';

                            return "{$name} · {$mimeType}{$size}";
                        })
                        ->placeholder(__('filament-cloudflare-mail-sender::messages.preview.no_attachments')),
                ]),
            Section::make(__('filament-cloudflare-mail-sender::messages.audit.sections.failure'))
                ->schema([
                    TextEntry::make('failure_class')->placeholder('—'),
                    TextEntry::make('failure_message')->placeholder('—'),
                ])
                ->visible(static fn (CloudflareMailMessage $record): bool => $record->failure_class !== null || $record->failure_message !== null),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCloudflareMailMessages::route('/'),
            'view' => ViewCloudflareMailMessage::route('/{record}'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return self::navigationString('messages_label', __('filament-cloudflare-mail-sender::messages.navigation.messages'));
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

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('filament-cloudflare-mail-sender::messages.actions.duplicate'))
            ->icon('heroicon-o-document-duplicate')
            ->action(static function (CloudflareMailMessage $record, Component $livewire): void {
                self::authorizeDuplicate();
                $livewire->redirect(ComposeEmail::getUrl(['duplicate' => $record->id]));
            });
    }

    public static function authorizeDuplicate(): void
    {
        $actor = self::actor();
        $plugin = self::plugin();

        abort_unless($plugin->canViewAudit($actor) && $plugin->canCompose($actor), 403);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (MessageStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    private static function plugin(): FilamentCloudflareMailSenderPlugin
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        abort_if($panel === null, 500);
        $plugin = $panel->getPlugin('filament-cloudflare-mail-sender');

        abort_unless($plugin instanceof FilamentCloudflareMailSenderPlugin, 500);

        return $plugin;
    }

    private static function actor(): ?Authenticatable
    {
        return Filament::auth()->user();
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

    private static function translation(string $key): string
    {
        $translation = __($key);

        return is_string($translation) ? $translation : $key;
    }
}
