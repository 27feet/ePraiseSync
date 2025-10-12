<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BehaviourNotificationLog;
use App\Services\BehaviourRecipientResolver;

class BehaviourRecipientsPreviewCommand extends Command
{
    protected $signature = 'behaviour:recipients-preview 
                            {--date= : ISO date (YYYY-MM-DD). Defaults to today}
                            {--details : Show full recipient lists per log}';

    protected $description = 'Preview who would be emailed for today\'s behaviour notification logs (no sending).';

    public function handle(): int
    {
        $dateOpt = $this->option('date');
        $asOf    = $dateOpt ? Carbon::parse($dateOpt) : now();
        $start   = $asOf->copy()->startOfDay();
        $end     = $asOf->copy()->endOfDay();

        /** @var BehaviourRecipientResolver $resolver */
        $resolver = app(BehaviourRecipientResolver::class);

        // Pull today’s freshly created logs (adjust the window if you prefer)
        $logs = BehaviourNotificationLog::query()
            ->with('rule') // we’ll need staff_roles / template_key etc.
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            $this->info("No behaviour_notification_logs created between {$start} and {$end}.");
            return self::SUCCESS;
        }

        // Summarise in a table first
        $rows = [];
        foreach ($logs as $log) {
            $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];
            $src  = $snap['source']   ?? ($log->rule->source   ?? null);
            $cat  = $snap['category'] ?? ($log->rule->category ?? null);
            $cnt  = $snap['count']    ?? null;
            $thr  = $log->hit_threshold ?? ($log->rule->threshold ?? null);

            $studentName = Student::where('arbor_id', $log->student_id)->value('name');

            $recips = $resolver->resolveForRule((int)$log->student_id, $log->rule);
            $rows[] = [
                'Log ID'        => $log->id,
                'Student'       => $studentName ? "{$studentName} ({$log->student_id})" : (string) $log->student_id,
                'Source'        => $src ?? '-',
                'Category'      => $cat ?? '-',
                'Count'         => $cnt ?? '-',
                'Threshold'     => $thr ?? '-',
                'Template'      => $log->rule->template_key ?? '-',
                'Staff (#)'     => count($recips['staff']),
                'Parents (#)'   => count($recips['parents']),
                'Window'        => sprintf(
                    '%s → %s',
                    optional($log->window_start_date)->toDateString() ?? '-',
                    optional($log->window_end_date)->toDateString()   ?? '-'
                ),
            ];
        }

        $this->line('');
        $this->info("Behaviour notification recipients preview for {$start->toDateString()}");
        $this->table(
            ['Log ID', 'Student', 'Source', 'Category', 'Count', 'Threshold', 'Template', 'Staff (#)', 'Parents (#)', 'Window'],
            $rows
        );

        // Optional verbose lists
        if ($this->option('details')) {
            $this->line('');
            $this->info('Detailed recipients per log:');
            foreach ($logs as $log) {
                $studentName = Student::where('arbor_id', $log->student_id)->value('name');
                $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];

                $this->line(str_repeat('-', 80));
                $this->line("Log #{$log->id}  |  Student: " . ($studentName ? "{$studentName} ({$log->student_id})" : $log->student_id));
                $this->line("Rule: {$log->rule->source} / {$log->rule->category}  (threshold: {$log->rule->threshold}, template: {$log->rule->template_key})");
                if (!empty($snap)) {
                    $this->line('Snapshot: ' . json_encode($snap));
                }

                $recips = $resolver->resolveForRule((int)$log->student_id, $log->rule);

                $this->line('Staff recipients:');
                if (empty($recips['staff'])) {
                    $this->line('  (none)');
                } else {
                    foreach ($recips['staff'] as $e) {
                        $this->line("  - {$e}");
                    }
                }

                $this->line('Parent recipients (each receives an individual email):');
                if (empty($recips['parents'])) {
                    $this->line('  (none)');
                } else {
                    foreach ($recips['parents'] as $e) {
                        $this->line("  - {$e}");
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
