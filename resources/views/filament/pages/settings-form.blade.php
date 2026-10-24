<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-6">
            {{ __('plaashek.settings.save') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
