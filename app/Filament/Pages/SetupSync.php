<?php

namespace App\Filament\Pages;

use App\Services\FetchBackgroundDataService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms;
use Illuminate\Http\Request;

class SetupSync extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Setup Data Sync';
    protected static ?string $title = 'Setup Data Sync';
    protected static ?string $navigationGroup = 'Maintenance';
    protected static string $view = 'filament.pages.setup-sync';

    public array $data = [];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Toggle::make('fetch_arbor')
                ->label('Arbor data (years, terms, classes, point/behaviour categories, staff, students)')
                ->default(true),
            Forms\Components\Toggle::make('fetch_epraise')
                ->label('ePraise data (classes, students, teachers)')
                ->default(true),
            Forms\Components\Toggle::make('refresh_categories')
                ->label('Refresh ePraise category tables from current data')
                ->default(true),
        ])->statePath('data');
    }

    public function sync(): void
    {
        $state = $this->form->getState();
        $svc = app(FetchBackgroundDataService::class);
        $req = new Request();

        try {
            if ($state['fetch_arbor'] ?? false) {
                $svc->fetchAcademicCalendarDates($req);
                $svc->fetchAcademicYears($req);
                $svc->fetchArborBehaviourCategories($req);
                $svc->fetchArborClasses($req, now()->subDays(10)->toDateString(), now()->addDays(10)->toDateString());
                $svc->fetchArborPointScales($req);
                $svc->fetchArborStaff($req);
                // $svc->fetchArborStudents($req); // uncomment if needed
            }

            if ($state['fetch_epraise'] ?? false) {
                $svc->fetchEpraiseClasses($req);
                $svc->fetchEpraiseStudents($req);
                $svc->fetchEpraiseTeachers($req);
            }

            if ($state['refresh_categories'] ?? false) {
                $svc->updateDemeritCategories($req);
                $svc->updateInterventionCategories($req);
                $svc->updatePointCategories($req);
            }

            Notification::make()
                ->title('Setup sync completed')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Setup sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
