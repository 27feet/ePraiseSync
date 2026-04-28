<?php

namespace App\Console\Commands;

use App\Models\Point;
use App\Models\Demerit;
use App\Models\Intervention;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use App\Models\EpraiseInterventionCategory;
use App\Support\Checksum;

class ReconcileSyncStatusesCommand extends Command
{
    protected $signature = 'writeback:reconcile-statuses
        {--model=* : points|demerits|interventions|all}
        {--chunk=500 : Records to process per chunk}
        {--touch : Update updated_at timestamps}
        {--dry-run : Report changes without writing}';

    protected $description = 'Clear unnecessary queued writebacks when items are already in sync.';

    public function handle(): int
    {
        $models = $this->option('model');
        $models = array_filter(array_map(
            static fn ($model) => strtolower(trim((string) $model)),
            $models
        ));

        if (empty($models) || in_array('all', $models, true)) {
            $models = ['points', 'demerits', 'interventions'];
        }

        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $chunk = 500;
        }

        $touch  = (bool) $this->option('touch');
        $dryRun = (bool) $this->option('dry-run');

        $map = [
            'points' => [
                Point::class,
                static fn (Point $point) => [
                    'student_id' => $point->student_id,
                    'teacher_id' => $point->teacher_id,
                    'class_id'   => $point->class_id,
                    'subject'    => $point->subject,
                    'category'   => $point->category,
                    'date'       => $point->date,
                    'points'     => $point->points,
                ],
                static fn (Point $point) => !empty($point->arbor_id),
                null,
            ],
            'demerits' => [
                Demerit::class,
                static fn (Demerit $demerit) => [
                    'student_id' => $demerit->student_id,
                    'teacher_id' => $demerit->teacher_id,
                    'class_id'   => $demerit->class_id,
                    'subject'    => $demerit->subject,
                    'category'   => $demerit->category,
                    'reason'     => $demerit->reason,
                    'date'       => $demerit->date,
                    'points'     => $demerit->points,
                    'comment'    => $demerit->comment,
                ],
                static function (Demerit $demerit): bool {
                    if (!empty($demerit->arbor_point_id)) return true;
                    if (!empty($demerit->arbor_behaviour_id)) return true;
                    if (!empty($demerit->arbor_behaviour_student_id)) return true;

                    $actions = $demerit->arbor_action_id;
                    if (is_string($actions)) {
                        $actions = json_decode($actions, true);
                    }
                    if (is_array($actions)) {
                        $vals = array_filter(array_values($actions));
                        return !empty($vals);
                    }

                    return false;
                },
                null,
            ],
            'interventions' => [
                Intervention::class,
                static fn (Intervention $intervention) => [
                    'student_id'        => $intervention->student_id,
                    'teacher_id'        => $intervention->teacher_id,
                    'demerit_id'        => $intervention->demerit_id,
                    'subject'           => $intervention->subject,
                    'intervention_type' => $intervention->intervention_type,
                    'date_added'        => $intervention->date_added,
                    'comment'           => $intervention->comment,
                ],
                static function (Intervention $intervention): bool {
                    if (!empty($intervention->arbor_point_id)) return true;
                    if (!empty($intervention->arbor_behaviour_id)) return true;
                    if (!empty($intervention->arbor_behaviour_student_id)) return true;

                    $actions = $intervention->arbor_action_id;
                    if (is_string($actions)) {
                        $actions = json_decode($actions, true);
                    }
                    if (is_array($actions)) {
                        $vals = array_filter(array_values($actions));
                        return !empty($vals);
                    }

                    return false;
                },
                function (Intervention $intervention): bool {
                    $key = $intervention->intervention_type ?: $intervention->category;
                    if (!$key) {
                        return false;
                    }

                    static $cache = [];
                    if (array_key_exists($key, $cache)) {
                        return $cache[$key];
                    }

                    $internal = EpraiseInterventionCategory::where('epraise_name', $key)->first();
                    $cache[$key] = (bool) ($internal && ($internal->arbor_point_category_id || $internal->arbor_behaviour_category_id));

                    return $cache[$key];
                },
            ],
        ];

        $unknown = array_diff($models, array_keys($map));
        if (!empty($unknown)) {
            $this->error('Unknown model option(s): ' . implode(', ', $unknown));
            return 1;
        }

        foreach ($models as $label) {
            [$modelClass, $resolver, $remotePresent, $mappingPresent] = $map[$label];
            $this->reconcileFor($label, $modelClass, $resolver, $remotePresent, $mappingPresent, $chunk, $touch, $dryRun);
        }

        return 0;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param callable(Model):array $resolver
     * @param callable(Model):bool $remotePresent
     * @param callable(Model):bool|null $mappingPresent
     */
    private function reconcileFor(
        string $label,
        string $modelClass,
        callable $resolver,
        callable $remotePresent,
        ?callable $mappingPresent,
        int $chunk,
        bool $touch,
        bool $dryRun
    ): void {
        $total = 0;
        $cleared = 0;

        $this->info("Reconciling {$label} queued writebacks...");

        $queuedStatuses = [
            $modelClass::STATUS_REQUIRES_CREATE,
            $modelClass::STATUS_READY_TO_CREATE,
            $modelClass::STATUS_REQUIRES_UPDATE,
            $modelClass::STATUS_READY_TO_UPDATE,
        ];

        $query = $modelClass::query()
            ->whereIn('sync_status', $queuedStatuses)
            ->orderBy('id');

        $query->chunkById($chunk, function ($records) use (
            &$total,
            &$cleared,
            $resolver,
            $remotePresent,
            $mappingPresent,
            $touch,
            $dryRun,
            $modelClass
        ) {
            foreach ($records as $record) {
                $total++;

                $fields = $resolver($record);
                $currentChecksum = Checksum::fromFields($fields);
                $legacyChecksum = Checksum::fromFieldsWithTimezone($fields, 'Europe/London');

                $inSync = false;
                if (!empty($record->synced_checksum)) {
                    if ($record->synced_checksum === $currentChecksum) {
                        $inSync = true;
                    } elseif ($record->synced_checksum === $legacyChecksum) {
                        $record->synced_checksum = $currentChecksum;
                        $inSync = true;
                    }
                } elseif (!empty($record->synced_at)) {
                    if (empty($record->checksum) || $record->checksum === $currentChecksum) {
                        $record->checksum = $currentChecksum;
                        $record->synced_checksum = $currentChecksum;
                        $inSync = true;
                    }
                }

                if (!$inSync) {
                    continue;
                }

                $hasRemote = (bool) $remotePresent($record);
                $mappingExists = $mappingPresent ? (bool) $mappingPresent($record) : true;
                $allowClear = $hasRemote || ($record instanceof Intervention && !$mappingExists);

                if (!$allowClear) {
                    continue;
                }

                $cleared++;

                if ($dryRun) {
                    continue;
                }

                $origTimestamps = $record->timestamps;
                if (!$touch) {
                    $record->timestamps = false;
                }

                $record->forceFill([
                    'sync_status'     => $modelClass::STATUS_PROCESSED_SUCCESSFULLY,
                    'checksum'        => $record->checksum ?: $currentChecksum,
                    'synced_checksum' => $record->synced_checksum ?: $currentChecksum,
                    'sync_last_error_status' => null,
                    'sync_last_error_body' => null,
                ])->save();

                $record->timestamps = $origTimestamps;
            }
        });

        $suffix = $dryRun ? ' (dry-run)' : '';
        $this->info("{$label}: {$cleared} of {$total} cleared{$suffix}.");
    }
}
