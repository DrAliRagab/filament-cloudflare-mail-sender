<x-filament-panels::page>
    <form wire:submit="previewEmail" class="space-y-6">
        {{ $this->form }}

        @foreach ($this->duplicateRecipientWarnings() as $warning)
            <x-filament::callout color="warning">
                {{ $warning }}
            </x-filament::callout>
        @endforeach

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="button" color="gray" wire:click="saveDraft">
                {{ __('filament-cloudflare-mail-sender::messages.actions.save_draft') }}
            </x-filament::button>
            <x-filament::button type="submit">
                {{ __('filament-cloudflare-mail-sender::messages.actions.preview') }}
            </x-filament::button>
            <x-filament::button type="button" color="danger" outlined wire:click="discardDraft">
                {{ __('filament-cloudflare-mail-sender::messages.actions.discard') }}
            </x-filament::button>
        </div>
    </form>

    @if ($preview !== null)
        @include('filament-cloudflare-mail-sender::filament.pages.partials.delivery-memo', ['preview' => $preview])
    @endif
</x-filament-panels::page>
