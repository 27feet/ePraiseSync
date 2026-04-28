<x-filament-panels::page>
    <div class="space-y-3">
        @foreach($rows as $row)
            <div class="border rounded p-3 flex items-center justify-between">
                <div>
                    <div class="font-semibold">{{ $row['label'] }}</div>
                    <div class="text-sm text-gray-600">
                        Status: {{ $row['disabled'] ? 'Disabled' : 'Enabled' }}
                        @if($row['last_status'])
                            · Last: {{ $row['last_status'] }} ({{ $row['last_seen_at'] ?? 'n/a' }})
                        @endif
                    </div>
                </div>
                <div class="flex gap-2">
                    <x-filament::button wire:click="toggle('{{ $row['name'] }}')" color="{{ $row['disabled'] ? 'success' : 'danger' }}">
                        {{ $row['disabled'] ? 'Enable' : 'Disable' }}
                    </x-filament::button>
                    <x-filament::button wire:click="runNow('{{ $row['name'] }}')" color="primary">
                        Run now
                    </x-filament::button>
                </div>
            </div>
        @endforeach
    </div>
    <p class="mt-4 text-sm text-gray-600">
        Heartbeats update when tasks start/finish. Toggle disable to skip scheduled runs; use “Run now” to trigger manually.
    </p>
</x-filament-panels::page>
