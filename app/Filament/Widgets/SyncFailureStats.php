<?php

namespace App\Filament\Widgets;

use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SyncFailureStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            $this->statFor(Point::class, 'create', 'Points failed create'),
            $this->statFor(Point::class, 'update', 'Points failed update'),
            $this->statFor(Point::class, 'delete', 'Points failed delete'),
            $this->maxedStatFor(Point::class, 'Points max attempts'),
            $this->statFor(Demerit::class, 'create', 'Demerits failed create'),
            $this->statFor(Demerit::class, 'update', 'Demerits failed update'),
            $this->statFor(Demerit::class, 'delete', 'Demerits failed delete'),
            $this->maxedStatFor(Demerit::class, 'Demerits max attempts'),
            $this->statFor(Intervention::class, 'create', 'Interventions failed create'),
            $this->statFor(Intervention::class, 'update', 'Interventions failed update'),
            $this->statFor(Intervention::class, 'delete', 'Interventions failed delete'),
            $this->maxedStatFor(Intervention::class, 'Interventions max attempts'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }

    private function statFor(string $modelClass, string $type, string $label): Stat
    {
        $statusField = match ($type) {
            'create' => 'STATUS_FAILED_CREATION',
            'update' => 'STATUS_FAILED_UPDATE',
            'delete' => 'STATUS_FAILED_DELETION',
            default => null,
        };

        $status = $statusField && defined("{$modelClass}::{$statusField}")
            ? constant("{$modelClass}::{$statusField}")
            : null;

        $count = $status === null ? 0 : $modelClass::where('sync_status', $status)->count();

        $color = $count > 0 ? 'danger' : 'success';

        return Stat::make($label, $count)->color($color);
    }

    private function maxedStatFor(string $modelClass, string $label): Stat
    {
        $maxAttempts = max(1, (int) config('services.arbor.sync.max_attempts', 10));
        $count = $modelClass::query()
            ->whereIn('sync_status', [
                $modelClass::STATUS_FAILED_CREATION,
                $modelClass::STATUS_FAILED_UPDATE,
                $modelClass::STATUS_FAILED_DELETION,
            ])
            ->where('sync_attempts', '>=', $maxAttempts)
            ->count();

        $color = $count > 0 ? 'warning' : 'success';

        return Stat::make($label, $count)->color($color);
    }
}
