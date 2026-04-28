<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-4">
        <x-filament::button wire:click="sync" icon="heroicon-o-arrow-path" color="primary">
            Run setup sync
        </x-filament::button>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        Pulls reference data from Arbor (years/terms/calendar, classes, staff, point & behaviour categories) and ePraise (classes, students, teachers), and refreshes local ePraise category tables. Arbor students fetch remains optional/commented if you want to enable it.
    </p>
</x-filament-panels::page>
