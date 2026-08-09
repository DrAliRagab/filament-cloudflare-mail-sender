<x-filament-panels::page>
    <form wire:submit="sendEmail" class="space-y-6">
        {{ $this->form }}

        @foreach ($this->duplicateRecipientWarnings() as $warning)
            <x-filament::callout color="warning">
                {{ $warning }}
            </x-filament::callout>
        @endforeach

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" wire:confirm="{{ __('filament-cloudflare-mail-sender::messages.actions.send_confirmation') }}">
                {{ __('filament-cloudflare-mail-sender::messages.actions.send') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
