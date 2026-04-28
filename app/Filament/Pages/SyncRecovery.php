<?php

namespace App\Filament\Pages;

use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SyncRecovery extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationLabel = 'Sync Recovery';
    protected static ?string $title = 'Sync Recovery';
    protected static ?string $navigationGroup = 'Maintenance';
    protected static string $view = 'filament.pages.sync-recovery';

    /**
        * Show the stats widget above the actions.
        */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\SyncFailureStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requeueExhausted')
                ->label('Requeue max-attempt failures')
                ->icon('heroicon-o-bolt')
                ->requiresConfirmation()
                ->color('danger')
                ->action(function () {
                    $result = [
                        'points' => $this->requeueExhaustedFailures(Point::class),
                        'demerits' => $this->requeueExhaustedFailures(Demerit::class),
                        'interventions' => $this->requeueExhaustedFailures(Intervention::class),
                    ];

                    $body = collect($result)
                        ->filter(fn ($count) => $count > 0)
                        ->map(fn ($count, $label) => ucfirst($label) . ": {$count}")
                        ->implode(', ');

                    Notification::make()
                        ->title('Max-attempt failures requeued')
                        ->body($body ?: 'No max-attempt failures found.')
                        ->success()
                        ->send();
                }),
            Action::make('requeuePoints')
                ->label('Requeue failed Points')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->color('primary')
                ->action(function () {
                    $result = $this->requeueFailures(Point::class);
                    $this->notifyResult('Points', $result);
                }),
            Action::make('requeueDemerits')
                ->label('Requeue failed Demerits')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->color('warning')
                ->action(function () {
                    $result = $this->requeueFailures(Demerit::class);
                    $this->notifyResult('Demerits', $result);
                }),
            Action::make('requeueInterventions')
                ->label('Requeue failed Interventions')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->color('success')
                ->action(function () {
                    $result = $this->requeueFailures(Intervention::class);
                    $this->notifyResult('Interventions', $result);
                }),
        ];
    }

    /**
     * Reset failed sync statuses back to REQUIRES_* so the normal processor can retry.
     * @return array<string,int> counts updated per operation type
     */
    private function requeueFailures(string $modelClass): array
    {
        $map = [
            'create' => [
                $modelClass::STATUS_FAILED_CREATION ?? null,
                $modelClass::STATUS_REQUIRES_CREATE ?? null,
            ],
            'update' => [
                $modelClass::STATUS_FAILED_UPDATE ?? null,
                $modelClass::STATUS_REQUIRES_UPDATE ?? null,
            ],
            'delete' => [
                $modelClass::STATUS_FAILED_DELETION ?? null,
                $modelClass::STATUS_REQUIRES_DELETION ?? null,
            ],
        ];

        $updated = ['create' => 0, 'update' => 0, 'delete' => 0];

        foreach ($map as $type => [$from, $to]) {
            if ($from === null || $to === null) {
                continue;
            }

            $count = $modelClass::where('sync_status', $from)->update([
                'sync_status'         => $to,
                'synced_at'           => null,
                'missing_runs'        => 0,
                'sync_attempts'       => 0,
                'sync_last_failed_at' => null,
                'sync_next_retry_at'  => null,
                'sync_last_error_status' => null,
                'sync_last_error_body' => null,
            ]);

            $updated[$type] = (int) $count;
        }

        return $updated;
    }

    private function requeueExhaustedFailures(string $modelClass): int
    {
        $maxAttempts = max(1, (int) config('services.arbor.sync.max_attempts', 10));
        $failedStatuses = [
            $modelClass::STATUS_FAILED_CREATION,
            $modelClass::STATUS_FAILED_UPDATE,
            $modelClass::STATUS_FAILED_DELETION,
        ];

        return (int) $modelClass::query()
            ->whereIn('sync_status', $failedStatuses)
            ->where('sync_attempts', '>=', $maxAttempts)
            ->update([
                'sync_status' => \Illuminate\Support\Facades\DB::raw(
                    'CASE ' .
                    'WHEN sync_status = ' . (int) $modelClass::STATUS_FAILED_CREATION . ' THEN ' . (int) $modelClass::STATUS_REQUIRES_CREATE . ' ' .
                    'WHEN sync_status = ' . (int) $modelClass::STATUS_FAILED_UPDATE . ' THEN ' . (int) $modelClass::STATUS_REQUIRES_UPDATE . ' ' .
                    'WHEN sync_status = ' . (int) $modelClass::STATUS_FAILED_DELETION . ' THEN ' . (int) $modelClass::STATUS_REQUIRES_DELETION . ' ' .
                    'ELSE sync_status END'
                ),
                'synced_at'           => null,
                'missing_runs'        => 0,
                'sync_attempts'       => 0,
                'sync_last_failed_at' => null,
                'sync_next_retry_at'  => null,
                'sync_last_error_status' => null,
                'sync_last_error_body' => null,
            ]);
    }

    private function notifyResult(string $label, array $counts): void
    {
        $total = array_sum($counts);
        $body = collect($counts)
            ->filter(fn ($v) => $v > 0)
            ->map(fn ($v, $k) => ucfirst($k) . ": {$v}")
            ->implode(', ');

        Notification::make()
            ->title("{$label} requeued")
            ->body($body ?: 'No failed records to requeue.')
            ->success()
            ->send();
    }
}
