<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Private, resumable import state. Each browser request processes at most 500 records. */
class EpraiseUploadImport
{
    public function preview(string $type, string $upload, int $userId): array
    {
        $this->allowLargeFeed();
        $prefix = "epraise-imports/uploads/{$userId}/";
        if (! str_starts_with($upload, $prefix) || str_contains(substr($upload, strlen($prefix)), '/')
            || str_contains($upload, '..') || ! Storage::disk('local')->exists($upload)) {
            throw ValidationException::withMessages(['data.file' => 'Upload a JSON file first.']);
        }
        $id = (string) Str::uuid();
        $directory = $this->directory($id);
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        if (! $disk->copy($upload, "{$directory}/source.json")) {
            throw new RuntimeException('Could not save a private copy of the upload.');
        }
        try {
            $rows = app(EpraiseFileImporter::class)->read($type, $disk->path("{$directory}/source.json"));
        } catch (\Throwable $e) {
            $disk->deleteDirectory($directory);
            throw ValidationException::withMessages(['data.file' => 'File validation failed: '.$e->getMessage()]);
        }

        $state = [
            'id' => $id, 'user_id' => $userId, 'type' => $type, 'apply' => false,
            'status' => 'running', 'processed' => 0, 'total' => count($rows),
            'counts' => ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'restored' => 0, 'queued_skipped' => 0],
            'error' => null,
        ];
        $this->save($state);

        return $state;
    }

    public function state(string $id, int $userId): array
    {
        $path = Storage::disk('local')->path($this->directory($id).'/state.json');
        abort_unless(is_file($path), 404);
        $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        abort_unless($state['user_id'] === $userId, 403);

        return $state;
    }

    public function apply(string $id, int $userId): array
    {
        return $this->locked($id, $userId, function (array $state) {
            if ($state['status'] !== 'completed' || $state['apply']) {
                throw ValidationException::withMessages(['data.file' => 'Complete a preview before importing.']);
            }
            $state['apply'] = true;
            $state['status'] = 'running';
            $state['processed'] = 0;
            $state['counts'] = array_fill_keys(array_keys($state['counts']), 0);
            $this->save($state);

            return $state;
        });
    }

    public function step(string $id, int $userId): array
    {
        $this->allowLargeFeed();

        return $this->locked($id, $userId, function (array $state) {
            if ($state['status'] !== 'running') {
                return $state;
            }
            try {
                // The private snapshot was fully validated before this run was created.
                $contents = Storage::disk('local')->get($this->directory($state['id']).'/source.json');
                $rows = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents), true, 512, JSON_THROW_ON_ERROR);
                $batch = array_slice($rows, $state['processed'], 500);
                if ($batch === []) {
                    throw new RuntimeException('The saved file is incomplete. Upload it again to start a new preview.');
                }
                $counts = app(EpraiseFileImporter::class)->import($state['type'], $batch, $state['apply']);
                foreach ($counts as $key => $count) {
                    $state['counts'][$key] += $count;
                }
                $state['processed'] += count($batch);
                $state['status'] = $state['processed'] >= $state['total'] ? 'completed' : 'running';
            } catch (\Throwable $e) {
                report($e);
                $state['status'] = 'failed';
                $state['error'] = 'This batch could not finish. Earlier completed batches remain saved. Start a new preview to retry the file. Error details have been recorded in the application log.';
            }
            $this->save($state);

            return $state;
        });
    }

    private function locked(string $id, int $userId, \Closure $callback): array
    {
        $this->state($id, $userId);
        $handle = fopen(Storage::disk('local')->path($this->directory($id).'/run.lock'), 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not lock the import.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return $this->state($id, $userId);
            }

            return $callback($this->state($id, $userId));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function directory(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return "epraise-imports/runs/{$id}";
    }

    private function allowLargeFeed(): void
    {
        // Decoding a large JSON array needs more memory than its on-disk size.
        $current = ini_get('memory_limit');
        if ($current !== '-1' && ini_parse_quantity($current) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private function save(array $state): void
    {
        $path = Storage::disk('local')->path($this->directory($state['id']).'/state.json');
        $temporary = $path.'.'.Str::random(12).'.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not save import progress.');
        }
    }
}
