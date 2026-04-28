<?php

namespace App\Filament\Pages;

use App\Services\UpdateLoggedItemsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms;
use Illuminate\Http\Request;

class ManualSync extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Manual Sync';
    protected static ?string $title = 'Manual Sync';
    protected static ?string $navigationGroup = 'Maintenance';
    protected static string $view = 'filament.pages.manual-sync';

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'from_date' => now()->subDays(7)->toDateString(),
            'include_amendments' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('from_date')
                    ->label('From date')
                    ->required()
                    ->native(false),
                Forms\Components\Toggle::make('include_amendments')
                    ->label('Include amendments')
                    ->default(true)
                    ->helperText('When enabled, missing/changed items are marked for update/delete.'),
            ])
            ->statePath('data');
    }

    public function syncPoints(): void
    {
        $this->runSync('points');
    }

    public function syncDemerits(): void
    {
        $this->runSync('demerits');
    }

    public function syncInterventions(): void
    {
        $this->runSync('interventions');
    }

    protected function runSync(string $type): void
    {
        $state = $this->form->getState();
        $from = $state['from_date'] ?? null;
        $include = (bool) ($state['include_amendments'] ?? true);

        if (!$from) {
            Notification::make()
                ->title('Select a start date first.')
                ->danger()
                ->send();
            return;
        }

        $service = app(UpdateLoggedItemsService::class);
        $req = new Request(); // no query params needed; we pass args directly

        $label = ucfirst($type);
        try {
            if ($type === 'points') {
                $service->fetchPoints($req, $from, $include);
            } elseif ($type === 'demerits') {
                $service->fetchDemerits($req, $from, $include);
            } elseif ($type === 'interventions') {
                $service->fetchInterventions($req, $from, $include);
            } else {
                throw new \InvalidArgumentException('Unknown sync type');
            }

            Notification::make()
                ->title("{$label} sync started")
                ->body("Requested from {$from} with include amendments=" . ($include ? 'yes' : 'no'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title("{$label} sync failed")
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
