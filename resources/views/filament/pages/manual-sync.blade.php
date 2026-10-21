<x-filament-panels::page>
    <div>
        <x-filament::button tag="a" :href="\App\Filament\Pages\ImportEpraiseFile::getUrl()" color="gray" icon="heroicon-o-arrow-up-tray">
            Import a saved ePraise file
        </x-filament::button>
    </div>

    {{ $this->form }}

    <div class="flex flex-wrap gap-3 mt-4">
        <x-filament::button wire:click="syncPoints" color="primary" icon="heroicon-o-sparkles">
            Sync Points
        </x-filament::button>
        <x-filament::button wire:click="syncDemerits" color="warning" icon="heroicon-o-exclamation-triangle">
            Sync Demerits
        </x-filament::button>
        <x-filament::button wire:click="syncInterventions" color="success" icon="heroicon-o-bolt">
            Sync Interventions
        </x-filament::button>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        ePraise does not support a “to” date; this runs a refresh from the selected date onward.
        Include amendments will mark deletions/updates as needed for writeback.
    </p>
</x-filament-panels::page>
