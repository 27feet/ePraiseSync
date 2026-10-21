<?php

namespace App\Console\Commands;

use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecoverReadyWritebacksCommand extends Command
{
    protected $signature = 'writeback:recover-ready
        {--model=* : points|demerits|interventions; default all}
        {--status=* : READY statuses 10|-10|-20; default all three}
        {--id=* : Local database row IDs; requires exactly one --model}
        {--apply : Reset READY records; default is a read-only report}
        {--workers-stopped-and-queue-empty : Assert all producers/workers stopped, all deliveries audited, no old job will be replayed}';

    protected $description = 'Report or recover stranded READY work only after operators have ruled out existing deliveries';

    public function handle(): int
    {
        $map = ['points' => Point::class, 'demerits' => Demerit::class, 'interventions' => Intervention::class];
        $models = array_values(array_unique($this->option('model') ?: array_keys($map)));
        $statuses = $this->option('status') ?: ['10', '-10', '-20'];
        $ids = $this->option('id');
        if (array_diff($models, array_keys($map)) || array_diff($statuses, ['10', '-10', '-20'])) {
            $this->error('Use --model=points|demerits|interventions and --status=10|-10|-20.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('--id must be a positive local database row ID.');

                return self::FAILURE;
            }
        }
        if ($ids && count($models) !== 1) {
            $this->error('--id requires exactly one --model to avoid ambiguity between tables.');

            return self::FAILURE;
        }
        if ($this->option('apply') && ! $this->option('workers-stopped-and-queue-empty')) {
            $this->error('First drain/inspect all pending, delayed, reserved and failed jobs, stop all producers/workers, audit uncertain remote writes, and rule out replay of old deliveries. Then pass --workers-stopped-and-queue-empty.');

            return self::FAILURE;
        }
        $runId = (string) Str::uuid();
        $operator = getenv('USERNAME') ?: getenv('USER') ?: 'unknown';
        foreach ($models as $label) {
            $class = $map[$label];
            $selected = 0;
            $recovered = 0;
            $class::whereIn('sync_status', $statuses)->when($ids, fn ($q) => $q->whereIn('id', $ids))
                ->chunkById(500, function ($records) use ($class, $runId, $operator, &$selected, &$recovered) {
                    foreach ($records as $record) {
                        $selected++;
                        if (! $this->option('apply')) {
                            continue;
                        }
                        $from = $record->sync_status;
                        $target = [10 => 1, -10 => -1, -20 => -2][$from];
                        if ($target === 1) {
                            $remote = $record instanceof Point ? $record->arbor_id : (
                                $record->arbor_point_id || $record->arbor_behaviour_id || $record->arbor_behaviour_student_id
                                || ! empty(array_filter((array) $record->arbor_action_id))
                            );
                            if ($remote) {
                                $target = -2;
                            }
                        }
                        // Preserve error evidence, attempts, cooldown dates and remote IDs.
                        $changed = $class::whereKey($record->id)->where('sync_status', $from)->update(['sync_status' => $target]);
                        if ($changed) {
                            $recovered++;
                            Log::notice('READY writeback recovered after operator delivery audit', [
                                'run_id' => $runId, 'operator' => $operator, 'model' => $class,
                                'id' => $record->id, 'epraise_record_id' => $record->record_id,
                                'from_status' => $from, 'to_status' => $target,
                            ]);
                        }
                    }
                });
            $this->line("{$label}: {$selected} READY records selected".($this->option('apply') ? ", {$recovered} recovered." : ' (report only).'));
        }
        if ($this->option('apply')) {
            Log::warning('READY writeback recovery audit completed', [
                'run_id' => $runId, 'operator' => $operator, 'models' => $models, 'statuses' => $statuses, 'ids' => $ids,
            ]);
            $this->line("Audit run: {$runId}");
        }

        return self::SUCCESS;
    }
}
