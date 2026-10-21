<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportEpraiseFile;
use App\Models\Point;
use App\Models\User;
use App\Services\EpraiseUploadImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImportEpraiseFilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_page_upload_preview_and_import_with_progress_and_resume(): void
    {
        $upload = UploadedFile::fake()->createWithContent('points.json', json_encode([$this->point()]));
        $page = Livewire::test(ImportEpraiseFile::class)
            ->assertSee('Import ePraise File')
            ->fillForm(['type' => 'points', 'file' => $upload])
            ->call('preview')
            ->assertHasNoFormErrors()
            ->assertSet('run.status', 'running')
            ->call('processBatch')
            ->assertSet('run.status', 'completed')
            ->assertSet('run.counts.new', 1);
        $this->assertSame(0, Point::count());

        // Changing the form after preview must not change the immutable file to apply.
        $page->set('data.type', 'demerits')->call('import')
            ->assertSet('run.status', 'running')->assertSet('run.type', 'points');
        Livewire::test(ImportEpraiseFile::class)
            ->assertSet('run.status', 'running')
            ->call('processBatch')
            ->assertSet('run.status', 'completed')
            ->assertSet('run.counts.new', 1);
        $this->assertSame(1, Point::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_progress_is_batched_and_completed_batches_are_not_repeated(): void
    {
        $rows = array_map(fn ($id) => $this->point($id), range(1, 501));
        $upload = 'epraise-imports/uploads/'.auth()->id().'/points.json';
        Storage::disk('local')->put($upload, "\xEF\xBB\xBF".json_encode($rows));
        $runner = app(EpraiseUploadImport::class);
        $run = $runner->preview('points', $upload, auth()->id());
        $run = $runner->step($run['id'], auth()->id());
        $this->assertSame(500, $run['processed']);
        $this->assertSame('running', $run['status']);
        $run = $runner->step($run['id'], auth()->id());
        $this->assertSame('completed', $run['status']);
        $this->assertSame(501, $run['counts']['new']);
        $this->assertSame(0, Point::count());

        $runner->apply($run['id'], auth()->id());
        $runner->step($run['id'], auth()->id());
        $this->assertSame(500, Point::count());
        $run = $runner->step($run['id'], auth()->id());
        $this->assertSame(501, Point::count());
        $this->assertSame('completed', $run['status']);
        $this->assertSame($run, $runner->step($run['id'], auth()->id()));
    }

    public function test_invalid_json_is_a_form_error_and_does_not_write_records(): void
    {
        Livewire::test(ImportEpraiseFile::class)
            ->fillForm(['type' => 'points', 'file' => UploadedFile::fake()->createWithContent('bad.json', '{bad json}')])
            ->call('preview')
            ->assertHasFormErrors(['file']);
        $this->assertSame(0, Point::count());
    }

    public function test_upload_path_cannot_reference_another_users_files(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(EpraiseUploadImport::class)->preview('points', 'epraise-imports/uploads/999/other.json', auth()->id());
    }

    public function test_import_cannot_be_started_until_preview_completes(): void
    {
        $upload = 'epraise-imports/uploads/'.auth()->id().'/points.json';
        Storage::disk('local')->put($upload, json_encode([$this->point()]));
        $runner = app(EpraiseUploadImport::class);
        $run = $runner->preview('points', $upload, auth()->id());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $runner->apply($run['id'], auth()->id());
    }

    public function test_other_users_cannot_read_or_process_a_run(): void
    {
        $upload = 'epraise-imports/uploads/'.auth()->id().'/points.json';
        Storage::disk('local')->put($upload, json_encode([$this->point()]));
        $runner = app(EpraiseUploadImport::class);
        $run = $runner->preview('points', $upload, auth()->id());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $runner->step($run['id'], 999);
    }

    public function test_page_requires_login(): void
    {
        auth()->logout();
        $this->get(ImportEpraiseFile::getUrl())->assertRedirect('/login');
    }

    public function test_missing_saved_run_does_not_prevent_opening_the_page(): void
    {
        session(['epraise_file_import.'.auth()->id() => (string) \Illuminate\Support\Str::uuid()]);
        Livewire::test(ImportEpraiseFile::class)->assertSet('runId', null)->assertSet('run', null);
    }

    public function test_overlapping_requests_do_not_process_the_same_batch(): void
    {
        $upload = 'epraise-imports/uploads/'.auth()->id().'/points.json';
        Storage::disk('local')->put($upload, json_encode([$this->point()]));
        $runner = app(EpraiseUploadImport::class);
        $run = $runner->preview('points', $upload, auth()->id());
        $handle = fopen(Storage::disk('local')->path('epraise-imports/runs/'.$run['id'].'/run.lock'), 'c');
        try {
            flock($handle, LOCK_EX);
            $this->assertSame(0, $runner->step($run['id'], auth()->id())['processed']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->assertSame(1, $runner->step($run['id'], auth()->id())['processed']);
    }

    public function test_batch_failure_is_saved_and_shown_without_exposing_database_details(): void
    {
        $upload = 'epraise-imports/uploads/'.auth()->id().'/points.json';
        Storage::disk('local')->put($upload, json_encode([$this->point()]));
        $runner = app(EpraiseUploadImport::class);
        $run = $runner->preview('points', $upload, auth()->id());
        $this->mock(\App\Services\EpraiseFileImporter::class)->shouldReceive('import')
            ->once()->andThrow(new \RuntimeException('Private SQL error'));
        $run = $runner->step($run['id'], auth()->id());
        $this->assertSame('failed', $run['status']);
        $this->assertSame(0, $run['processed']);
        $this->assertStringNotContainsString('Private SQL error', $run['error']);
        $this->assertSame($run, $runner->state($run['id'], auth()->id()));
    }

    private function point(int $id = 123): array
    {
        return [
            'id' => (string) $id, 'student' => '12', 'teacher' => '34', 'class' => '0',
            'subject' => 'Other', 'category' => 'Effort', 'date' => '2026-09-07 11:47:19', 'value' => '2',
        ];
    }
}
