<?php

namespace App\Console\Commands;

use App\Models\Point;
use App\Models\Demerit;
use App\Models\Intervention;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use App\Support\Checksum;

class RecalculateChecksumsCommand extends Command
{
    protected $signature = 'writeback:recalculate-checksums
        {--model=* : points|demerits|interventions|all}
        {--chunk=500 : Records to process per chunk}
        {--status=success : success|all}
        {--touch : Update updated_at timestamps}
        {--dry-run : Report changes without writing}';

    protected $description = 'Recalculate checksum fields for logged items using current rules.';

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

        $status = strtolower(trim((string) $this->option('status')));
        if (!in_array($status, ['success', 'all'], true)) {
            $this->error('Invalid --status option. Use "success" or "all".');
            return 1;
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
            ],
        ];

        $unknown = array_diff($models, array_keys($map));
        if (!empty($unknown)) {
            $this->error('Unknown model option(s): ' . implode(', ', $unknown));
            return 1;
        }

        foreach ($models as $key) {
            [$modelClass, $resolver] = $map[$key];
            $this->recalculateFor($key, $modelClass, $resolver, $chunk, $status, $touch, $dryRun);
        }

        return 0;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param callable(Model):array $resolver
     */
    private function recalculateFor(
        string $label,
        string $modelClass,
        callable $resolver,
        int $chunk,
        string $status,
        bool $touch,
        bool $dryRun
    ): void {
        $total = 0;
        $changed = 0;

        $statusLabel = $status === 'success' ? 'status=success' : 'status=all';
        $this->info("Recalculating {$label} checksums ({$statusLabel})...");

        $query = $modelClass::query()->orderBy('id');
        if ($status === 'success') {
            $query->where('sync_status', $modelClass::STATUS_PROCESSED_SUCCESSFULLY);
        }

        $query
            ->chunkById($chunk, function ($records) use (&$total, &$changed, $resolver, $touch, $dryRun, $modelClass) {
                foreach ($records as $record) {
                    $total++;
                    $checksum = $this->computeChecksum($resolver($record));
                    $shouldSyncChecksum = $record->sync_status === $modelClass::STATUS_PROCESSED_SUCCESSFULLY;
                    $syncedChecksumMismatch = $shouldSyncChecksum && $record->synced_checksum !== $checksum;

                    if ($record->checksum === $checksum && !$syncedChecksumMismatch) {
                        continue;
                    }

                    $changed++;

                    if ($dryRun) {
                        continue;
                    }

                    $origTimestamps = $record->timestamps;
                    if (!$touch) {
                        $record->timestamps = false;
                    }

                    $payload = ['checksum' => $checksum];
                    if ($shouldSyncChecksum) {
                        $payload['synced_checksum'] = $checksum;
                    }

                    $record->forceFill($payload)->save();
                    $record->timestamps = $origTimestamps;
                }
            });

        $suffix = $dryRun ? ' (dry-run)' : '';
        $this->info("{$label}: {$changed} of {$total} updated{$suffix}.");
    }

    private function computeChecksum(array $fields): string
    {
        return Checksum::fromFields($fields);
    }
}
