<?php

namespace App\Filament\Pages;

use App\Support\TaskMonitor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TaskMonitorPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Task Monitor';
    protected static ?string $title = 'Task Monitor';
    protected static ?string $navigationGroup = 'Maintenance';
    protected static string $view = 'filament.pages.task-monitor';

    public array $rows = [];

    public function mount(): void
    {
        $this->refreshRows();
    }

    public function refreshRows(): void
    {
        $tasks = [
            ['name' => 'writeback:processAll', 'label' => 'Writeback (processAll)'],
            ['name' => 'writeback:fetchSystemData', 'label' => 'Fetch System Data'],
            ['name' => 'writeback:fetchloggeditems', 'label' => 'Fetch Logged Items'],
        ];

        $this->rows = collect($tasks)->map(function ($t) {
            $status = TaskMonitor::lastStatus($t['name']);
            return [
                'name'        => $t['name'],
                'label'       => $t['label'],
                'disabled'    => TaskMonitor::disabled($t['name']),
                'last_status' => $status['status'] ?? null,
                'last_seen_at'=> $status['at'] ?? null,
            ];
        })->all();
    }

    public function toggle(string $name): void
    {
        $current = TaskMonitor::disabled($name);
        TaskMonitor::setDisabled($name, !$current);
        $this->refreshRows();

        Notification::make()
            ->title((!$current ? 'Disabled' : 'Enabled') . " {$name}")
            ->success()
            ->send();
    }

    public function runNow(string $name): void
    {
        $output = TaskMonitor::runArtisan($name);
        $this->refreshRows();

        Notification::make()
            ->title("Ran {$name}")
            ->body($output ?: 'Completed.')
            ->success()
            ->send();
    }
}
