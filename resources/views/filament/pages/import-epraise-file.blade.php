<x-filament-panels::page>
    <x-filament::section heading="Import a saved ePraise feed">
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Upload a JSON feed and preview its changes before importing. Existing ePraise IDs are used to
            match records. Records missing from the file are left alone. Sending changes to Arbor uses the usual writeback process.
        </p>

        {{ $this->form }}

        <div class="mt-4 flex flex-wrap gap-3">
            <x-filament::button wire:click="preview" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass"
                :disabled="($run['status'] ?? null) === 'running'">
                Preview file
            </x-filament::button>
            <x-filament::button wire:click="import" wire:loading.attr="disabled" color="success" icon="heroicon-o-arrow-up-tray"
                :disabled="! $run || $run['status'] !== 'completed' || $run['apply']">
                Import previewed file
            </x-filament::button>
        </div>
        <p wire:loading wire:target="preview,import" class="mt-3 text-sm text-gray-600 dark:text-gray-400" role="status">
            Preparing file…
        </p>
    </x-filament::section>

    @if ($run)
        <x-filament::section :heading="($run['apply'] ? 'Import' : 'Preview') . ' — ' . ucfirst($run['type'])">
            <div @if ($run['status'] === 'running') wire:poll.1s="processBatch" @endif>
                <p class="text-sm" role="status" aria-live="polite">
                    {{ ucfirst($run['status']) }}: {{ number_format($run['processed']) }} of {{ number_format($run['total']) }} records processed.
                </p>
                <progress class="mt-3 w-full" max="{{ $run['total'] }}" value="{{ $run['processed'] }}" aria-label="Records processed"></progress>
                @if ($run['status'] === 'running')
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Keep this page open to continue. If you leave or reload, return here to resume from the last saved batch.
                    </p>
                @endif
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    @foreach (['new' => 'New', 'changed' => 'Changed', 'unchanged' => 'Unchanged', 'restored' => 'Restored', 'queued_skipped' => 'Skipped (queued jobs)'] as $key => $label)
                        <div>
                            <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-xl font-semibold">{{ number_format($run['counts'][$key]) }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($run['error'])
                    <p class="mt-4 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $run['error'] }}</p>
                @endif
                @if ($run['counts']['queued_skipped'])
                    <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                        Some records already have queued jobs. Let those finish, then preview and import the file again.
                    </p>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Before you import">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            In <a href="{{ \App\Filament\Pages\TaskMonitorPage::getUrl() }}" class="font-medium underline">Task Monitor</a>,
            pause Fetch Logged Items and Writeback, and let existing jobs finish before importing.
            Afterwards, re-enable Writeback to send changes to Arbor. Keep Fetch Logged Items paused while API responses
            may be incomplete: its amendment scan can mark missing records for deletion.
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            Preview makes no changes. Import applies the saved file used by that preview, even if you change the upload above.
            Choose Preview file again to use a different file or record type.
        </p>
    </x-filament::section>
</x-filament-panels::page>
