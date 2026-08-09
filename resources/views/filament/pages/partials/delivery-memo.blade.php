<x-filament::section>
    <x-slot name="heading">{{ __('filament-cloudflare-mail-sender::messages.preview.heading') }}</x-slot>
    <x-slot name="description">{{ __('filament-cloudflare-mail-sender::messages.preview.description') }}</x-slot>

    <dl class="grid gap-4 sm:grid-cols-2">
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.from_address') }}</dt><dd>{{ $preview['from'] }}</dd></div>
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.reply_to_address') }}</dt><dd>{{ $preview['reply_to'] ?? '—' }}</dd></div>
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.to') }}</dt><dd>{{ implode(', ', $preview['to']) }}</dd></div>
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.cc') }}</dt><dd>{{ $preview['cc'] === [] ? '—' : implode(', ', $preview['cc']) }}</dd></div>
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.bcc') }}</dt><dd>{{ $preview['bcc'] === [] ? '—' : implode(', ', $preview['bcc']) }}</dd></div>
        <div><dt class="text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.fields.subject') }}</dt><dd>{{ $preview['subject'] }}</dd></div>
    </dl>

    <div class="mt-6 space-y-6">
        <section>
            <h3 class="mb-2 text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.preview.html') }}</h3>
            <div class="prose max-w-none rounded-lg border border-gray-200 p-4 dark:prose-invert dark:border-white/10">{!! $preview['html'] !!}</div>
        </section>
        <section>
            <h3 class="mb-2 text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.preview.text') }}</h3>
            <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm dark:bg-white/5">{{ $preview['text'] }}</pre>
        </section>
        <section>
            <h3 class="mb-2 text-sm font-medium text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.preview.attachments') }}</h3>
            @forelse ($preview['attachments'] as $attachment)
                <div class="flex justify-between gap-4 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                    <span>{{ $attachment['name'] }}</span>
                    <span class="text-gray-500">{{ $attachment['mime_type'] }} · {{ Number::fileSize($attachment['size']) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('filament-cloudflare-mail-sender::messages.preview.no_attachments') }}</p>
            @endforelse
        </section>
    </div>

    <div class="mt-6">
        <x-filament::button type="button" wire:click="queueEmail" wire:confirm="{{ __('filament-cloudflare-mail-sender::messages.actions.queue_confirmation') }}">
            {{ __('filament-cloudflare-mail-sender::messages.actions.queue') }}
        </x-filament::button>
    </div>
</x-filament::section>
