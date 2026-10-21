<?php

namespace App\Filament\Pages;

use App\Services\EpraiseUploadImport;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class ImportEpraiseFile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import ePraise File';

    protected static ?string $title = 'Import ePraise File';

    protected static ?string $navigationGroup = 'Maintenance';

    protected static string $view = 'filament.pages.import-epraise-file';

    public array $data = [];

    #[Locked]
    public ?string $runId = null;

    #[Locked]
    public ?array $run = null;

    public function mount(): void
    {
        $this->form->fill(['type' => 'points']);
        $id = session($this->sessionKey());
        if ($id) {
            try {
                $this->run = app(EpraiseUploadImport::class)->state($id, (int) auth()->id());
                $this->runId = $id;
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                session()->forget($this->sessionKey());
            }
        }
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')->label('Records to import')
                ->options(['points' => 'Points', 'demerits' => 'Demerits', 'interventions' => 'Interventions'])
                ->required()->in(['points', 'demerits', 'interventions']),
            Forms\Components\FileUpload::make('file')->label('Saved ePraise JSON feed')
                ->disk('local')->directory(fn () => 'epraise-imports/uploads/'.auth()->id())
                ->visibility('private')->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])
                ->maxSize(65536)->required()
                ->helperText('Upload one saved API JSON file, up to 64 MB. CSV exports cannot be imported because they lack unique record IDs.'),
        ])->statePath('data');
    }

    public function preview(): void
    {
        if ($this->runId && app(EpraiseUploadImport::class)->state($this->runId, (int) auth()->id())['status'] === 'running') {
            return;
        }
        $state = $this->form->getState();
        $this->run = app(EpraiseUploadImport::class)->preview($state['type'], $state['file'], (int) auth()->id());
        $this->runId = $this->run['id'];
        session([$this->sessionKey() => $this->runId]);
    }

    public function import(): void
    {
        abort_unless($this->runId, 404);
        $this->run = app(EpraiseUploadImport::class)->apply($this->runId, (int) auth()->id());
    }

    public function processBatch(): void
    {
        if (! $this->runId) {
            return;
        }
        $previous = $this->run['status'] ?? null;
        $this->run = app(EpraiseUploadImport::class)->step($this->runId, (int) auth()->id());
        if ($previous === 'running' && $this->run['status'] === 'completed') {
            Notification::make()->title($this->run['apply'] ? 'Import finished' : 'Preview finished')
                ->body($this->run['counts']['queued_skipped'] ? 'Some records have queued jobs and were skipped. Drain those jobs, then preview and import again.' : 'Review the record totals below.')
                ->success()->send();
        }
    }

    private function sessionKey(): string
    {
        return 'epraise_file_import.'.auth()->id();
    }
}
