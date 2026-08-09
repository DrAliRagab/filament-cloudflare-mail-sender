<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @foreach ($this->duplicateRecipientWarnings() as $warning)
            <x-filament::callout color="warning">
                {{ $warning }}
            </x-filament::callout>
        @endforeach
    </div>
</x-filament-panels::page>
