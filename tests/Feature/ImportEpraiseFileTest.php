<?php

namespace Tests\Feature;

use App\Models\CheckPoint;
use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use App\Services\EpraiseFileImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportEpraiseFileTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function test_command_previews_by_default_and_import_is_idempotent_without_network_or_jobs(): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        $path = $this->file([$this->point()]);
        $this->artisan('epraise:import-file', ['type' => 'points', 'file' => $path])->assertSuccessful();
        $this->assertSame(0, Point::count());
        $this->assertSame(0, CheckPoint::count());
        $this->artisan('epraise:import-file', ['type' => 'points', 'file' => $path, '--apply' => true])->assertSuccessful();
        $point = Point::sole();
        $this->assertSame(Point::STATUS_REQUIRES_CREATE, $point->sync_status);
        $this->assertSame(123, $point->record_id);
        $updatedAt = $point->updated_at;
        $point->forceFill(['sync_status' => 0, 'arbor_id' => 'arbor-123'])->save();
        $this->travel(2)->hours();
        $counts = app(EpraiseFileImporter::class)->import('points', [$this->point()], true);
        $point->refresh();
        $this->assertSame(1, $counts['unchanged']);
        $this->assertSame(1, Point::count());
        $this->assertSame(1, CheckPoint::count());
        $this->assertSame(0, $point->sync_status);
        $this->assertSame('arbor-123', $point->arbor_id);
        $this->assertTrue($point->updated_at->equalTo($updatedAt));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_changed_records_keep_remote_ids_and_missing_records_are_not_deleted(): void
    {
        $importer = app(EpraiseFileImporter::class);
        $importer->import('points', [$this->point(), $this->point(['id' => '124'])], true);
        $point = Point::where('record_id', 123)->firstOrFail();
        $point->forceFill(['sync_status' => 0, 'arbor_id' => 'arbor-123', 'synced_checksum' => $point->checksum])->save();
        $counts = $importer->import('points', [$this->point(['value' => '5'])], true);
        $point->refresh();
        $this->assertSame(1, $counts['changed']);
        $this->assertSame(5, $point->points);
        $this->assertSame(Point::STATUS_REQUIRES_UPDATE, $point->sync_status);
        $this->assertSame('arbor-123', $point->arbor_id);
        $this->assertNotSame($point->checksum, $point->synced_checksum);
        $this->assertSame(0, Point::where('record_id', 124)->value('missing_runs'));
        $this->assertSame(Point::STATUS_REQUIRES_CREATE, Point::where('record_id', 124)->value('sync_status'));
    }

    public function test_queued_records_are_skipped_and_reported(): void
    {
        $importer = app(EpraiseFileImporter::class);
        $importer->import('points', [$this->point()], true);
        Point::query()->update(['sync_status' => Point::STATUS_READY_TO_CREATE]);
        $path = $this->file([$this->point(['value' => '9'])]);
        $this->artisan('epraise:import-file', ['type' => 'points', 'file' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(2, Point::sole()->points);
        $this->assertSame(Point::STATUS_READY_TO_CREATE, Point::sole()->sync_status);
    }

    public function test_demerits_and_interventions_preserve_comments_missing_from_json(): void
    {
        $importer = app(EpraiseFileImporter::class);
        $demerit = $this->point(['reason' => '', 'comment' => 'Keep this comment']);
        $intervention = [
            'id' => '456', 'student' => '12', 'teacher' => '34', 'demerit_id' => '0',
            'subject' => 'Other', 'intervention_type' => 'Support', 'date_added' => '2026-09-08 09:54:52',
            'comment' => 'Keep intervention comment',
        ];
        $importer->import('demerits', [$demerit], true);
        $importer->import('interventions', [$intervention], true);
        unset($demerit['comment'], $intervention['comment']);
        $this->assertSame(1, $importer->import('demerits', [$demerit], true)['unchanged']);
        $this->assertSame(1, $importer->import('interventions', [$intervention], true)['unchanged']);
        $this->assertSame('Keep this comment', Demerit::sole()->comment);
        $this->assertSame('Keep intervention comment', Intervention::sole()->comment);
    }

    public function test_present_records_marked_for_deletion_are_restored(): void
    {
        $importer = app(EpraiseFileImporter::class);
        $importer->import('points', [$this->point()], true);
        Point::query()->update(['sync_status' => -1, 'arbor_id' => 'existing', 'missing_runs' => 3]);
        $this->assertSame(1, $importer->import('points', [$this->point()], true)['restored']);
        $this->assertSame(-2, Point::sole()->sync_status);
        $this->assertSame(0, Point::sole()->missing_runs);
    }

    public function test_entire_file_is_validated_before_writing(): void
    {
        foreach ([
            [$this->point(), $this->point(['id' => '124', 'date' => '2026-02-30 12:00:00'])],
            [$this->point(), $this->point()],
            [$this->point(['student' => ''])],
            [$this->point(['category' => ['wrong shape']])],
        ] as $rows) {
            $this->artisan('epraise:import-file', ['type' => 'points', 'file' => $this->file($rows), '--apply' => true])->assertFailed();
            $this->assertSame(0, Point::count());
        }
    }

    public function test_validate_only_does_not_access_database(): void
    {
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->never();
        $this->artisan('epraise:import-file', [
            'type' => 'points', 'file' => $this->file([$this->point()]), '--validate-only' => true,
        ])->assertSuccessful();
    }

    private function point(array $overrides = []): array
    {
        return array_replace([
            'id' => '123', 'student' => '12', 'teacher' => '34', 'class' => '0',
            'subject' => 'Other', 'category' => 'Effort', 'date' => '2026-09-07 11:47:19', 'value' => '2',
        ], $overrides);
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epraise-import-test-');
        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
