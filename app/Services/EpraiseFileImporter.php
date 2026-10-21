<?php

namespace App\Services;

use App\Models\CheckDemerit;
use App\Models\CheckIntervention;
use App\Models\CheckPoint;
use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use App\Support\Checksum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EpraiseFileImporter
{
    private const TYPES = [
        'points' => [Point::class, CheckPoint::class],
        'demerits' => [Demerit::class, CheckDemerit::class],
        'interventions' => [Intervention::class, CheckIntervention::class],
    ];

    /** Validate the entire file before any database writes. CSV reports lack stable record IDs. */
    public function read(string $type, string $path): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Type must be points, demerits or interventions.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('File does not exist or is not readable.');
        }
        $contents = file_get_contents($path);
        $rows = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new InvalidArgumentException('Expected a non-empty JSON array of ePraise API records. CSV reports cannot safely identify individual records.');
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            try {
                $this->fields($type, $row);
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException('Duplicate record ID.');
                }
                $seen[$id] = true;
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Row '.($index + 1).': '.$e->getMessage(), 0, $e);
            }
        }

        return $rows;
    }

    /** No HTTP requests, jobs or deletion scans. Transactions are bounded to 500 records. */
    public function import(string $type, array $rows, bool $apply = false): array
    {
        [$modelClass, $checkClass] = self::TYPES[$type];
        $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'restored' => 0, 'queued_skipped' => 0];
        $runId = (string) Str::uuid();
        $startedAt = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $type, $modelClass, $checkClass, $apply, &$counts, $runId, $startedAt) {
                $existing = $modelClass::whereIn('record_id', array_column($chunk, 'id'))
                    ->when($apply, fn ($query) => $query->lockForUpdate())
                    ->get()->keyBy('record_id');

                foreach ($chunk as $row) {
                    $id = (int) $row['id'];
                    $model = $existing->get($id);
                    if ($model && in_array($model->sync_status, [10, -10, -20], true)) {
                        $counts['queued_skipped']++;

                        continue;
                    }
                    $new = ! $model;
                    $model ??= new $modelClass(['record_id' => $id]);
                    $business = $this->fields($type, $row);
                    // Missing optional fields in a feed must not erase locally stored comments.
                    if ($type !== 'points' && ! array_key_exists('comment', $row)) {
                        $business['comment'] = $model->comment;
                    }
                    $model->fill($business);
                    $changed = ! $new && $model->isDirty(array_keys($business));
                    $restore = ! $new && in_array($model->sync_status, [-1, -11, -100, -101], true);
                    $counts[$new ? 'new' : ($changed ? 'changed' : ($restore ? 'restored' : 'unchanged'))]++;
                    if (! $apply) {
                        continue;
                    }

                    if ($new || $changed || $restore) {
                        $model->sync_status = $this->remotePresent($model)
                            ? $modelClass::STATUS_REQUIRES_UPDATE
                            : $modelClass::STATUS_REQUIRES_CREATE;
                        $model->sync_attempts = 0;
                        $model->sync_next_retry_at = null;
                        $model->sync_last_failed_at = null;
                        $model->sync_last_error_status = null;
                        $model->sync_last_error_body = null;
                    }
                    $model->checksum = Checksum::fromFields($business);
                    $model->missing_runs = 0;
                    $model->last_seen_at = $startedAt;
                    $model->last_seen_run = $runId;
                    if (! $new && ! $changed && ! $restore) {
                        $model->timestamps = false;
                    }
                    $model->save();
                    $checkClass::updateOrCreate(['record_id' => $id], [
                        'date' => $business[$type === 'interventions' ? 'date_added' : 'date'],
                        'last_seen_at' => $startedAt,
                        'last_seen_run' => $runId,
                    ]);
                }
            });
        }

        return $counts;
    }

    private function fields(string $type, mixed $row): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException('Expected a record object.');
        }
        $numeric = $type === 'interventions'
            ? ['id', 'student', 'teacher', 'demerit_id']
            : ['id', 'student', 'teacher', 'class', 'value'];
        foreach ($numeric as $key) {
            $value = $row[$key] ?? null;
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < (in_array($key, ['id', 'student']) ? 1 : 0)) {
                throw new InvalidArgumentException("Invalid or missing integer field: {$key}.");
            }
        }
        $dateKey = $type === 'interventions' ? 'date_added' : 'date';
        $date = $row[$dateKey] ?? null;
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date) : false;
        if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new InvalidArgumentException("Invalid or missing timestamp: {$dateKey} (expected YYYY-MM-DD HH:MM:SS).");
        }
        $categoryKey = $type === 'interventions' ? 'intervention_type' : 'category';
        foreach ([$categoryKey, 'subject', ...($type === 'demerits' ? ['reason'] : []), 'comment'] as $key) {
            $value = $row[$key] ?? null;
            if (($key === $categoryKey && (! is_string($value) || trim($value) === ''))
                || ($value !== null && (! is_string($value) || mb_strlen($value) > 255))) {
                throw new InvalidArgumentException("Invalid or oversized text field: {$key}.");
            }
        }
        $fields = [
            'student_id' => (string) $row['student'],
            'teacher_id' => (string) $row['teacher'],
            'subject' => $row['subject'] ?? null,
            $categoryKey => $row[$categoryKey],
            // Match the existing API fetcher's timestamp handling.
            $dateKey => Carbon::parse($date)->timezone('Europe/London'),
        ];
        if ($type === 'interventions') {
            $fields['demerit_id'] = (string) $row['demerit_id'];
        } else {
            $fields['class_id'] = (string) $row['class'];
            $fields['points'] = (string) $row['value'];
        }
        if ($type !== 'points') {
            $fields['comment'] = $row['comment'] ?? null;
        }
        if ($type === 'demerits') {
            $fields['reason'] = $row['reason'] ?? null;
        }

        return $fields;
    }

    private function remotePresent($model): bool
    {
        if ($model instanceof Point) {
            return ! empty($model->arbor_id);
        }

        return ! empty($model->arbor_point_id) || ! empty($model->arbor_behaviour_id)
            || ! empty($model->arbor_behaviour_student_id) || ! empty($model->arbor_detention_id)
            || (is_array($model->arbor_action_id) && count(array_filter($model->arbor_action_id)) > 0);
    }
}
