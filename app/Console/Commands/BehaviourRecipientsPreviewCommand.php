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
        $legacyEnabled = (bool) config('behaviour_notifications.enable_legacy_rules', false);

        // Pull today’s freshly created logs (adjust the window if you prefer)
        $logsQuery = BehaviourNotificationLog::query()
            ->with(['rule', 'ruleGroup', 'ruleLevel'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id');

        if (!$legacyEnabled) {
            $logsQuery
                ->whereNotNull('behaviour_rule_group_id')
                ->whereNotNull('behaviour_rule_level_id');
        }

        $logs = $logsQuery->get();

        if ($logs->isEmpty()) {
            $this->info("No behaviour_notification_logs created between {$start} and {$end}.");
            return self::SUCCESS;
        }

        // Summarise in a table first
        $rows = [];
        foreach ($logs as $log) {
            $isGroupLog = (bool) $log->behaviour_rule_level_id;
            if (!$legacyEnabled && !$isGroupLog) {
                continue;
            }
            $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];
            $src  = $snap['source']   ?? ($isGroupLog ? ($log->ruleGroup?->source ?? null) : ($log->rule->source ?? null));
            $rawCategory = $snap['category'] ?? ($isGroupLog ? null : ($log->rule->category ?? null));
            if ($rawCategory === null && is_string($log->category)) {
                $decoded = json_decode($log->category, true);
                $rawCategory = json_last_error() === JSON_ERROR_NONE ? $decoded : $log->category;
            }
            $cat  = is_array($rawCategory) ? implode(', ', $rawCategory) : $rawCategory;
            $cnt  = $snap['value']    ?? ($snap['count'] ?? null);
            $thr  = $log->hit_threshold ?? ($isGroupLog ? ($log->ruleLevel?->threshold ?? null) : ($log->rule->threshold ?? null));
            $templateKey = $isGroupLog ? ($log->ruleLevel?->template_key ?? '-') : ($log->rule->template_key ?? '-');

            $student = Student::where('record_id', (string) $log->student_id)->first();
            if (!$student) {
                $student = Student::where('arbor_id', (string) $log->student_id)->first();
            }
            $studentName = $student?->name;

            if ($isGroupLog) {
                if (!$log->ruleGroup || !$log->ruleLevel) {
                    $recips = ['staff' => [], 'parents' => []];
                } else {
                    $recips = $resolver->resolveForGroupLevel((int) $log->student_id, $log->ruleGroup, $log->ruleLevel);
                }
            } else {
                $recips = $resolver->resolveForRule((int) $log->student_id, $log->rule);
            }
            $rows[] = [
                'Log ID'        => $log->id,
                'Student'       => $studentName ? "{$studentName} ({$log->student_id})" : (string) $log->student_id,
                'Source'        => $src ?? '-',
                'Category'      => $cat ?? '-',
                'Count'         => $cnt ?? '-',
                'Threshold'     => $thr ?? '-',
                'Template'      => $templateKey,
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
                $isGroupLog = (bool) $log->behaviour_rule_level_id;
                if (!$legacyEnabled && !$isGroupLog) {
                    continue;
                }
                $student = Student::where('record_id', (string) $log->student_id)->first();
                if (!$student) {
                    $student = Student::where('arbor_id', (string) $log->student_id)->first();
                }
                $studentName = $student?->name;
                $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];

                $this->line(str_repeat('-', 80));
                $this->line("Log #{$log->id}  |  Student: " . ($studentName ? "{$studentName} ({$log->student_id})" : $log->student_id));
                if ($isGroupLog) {
                    $source = $log->ruleGroup?->source ?? '-';
                    $category = is_array($snap['category'] ?? null) ? implode(', ', $snap['category']) : ($snap['category'] ?? '-');
                    $threshold = $log->ruleLevel?->threshold ?? '-';
                    $template = $log->ruleLevel?->template_key ?? '-';
                    $this->line("Group: {$source} / {$category}  (threshold: {$threshold}, template: {$template})");
                } else {
                    $this->line("Rule: {$log->rule->source} / {$log->rule->category}  (threshold: {$log->rule->threshold}, template: {$log->rule->template_key})");
                }
                if (!empty($snap)) {
                    $this->line('Snapshot: ' . json_encode($snap));
                }

                if ($isGroupLog) {
                    if (!$log->ruleGroup || !$log->ruleLevel) {
                        $recips = ['staff' => [], 'parents' => []];
                    } else {
                        $recips = $resolver->resolveForGroupLevel((int) $log->student_id, $log->ruleGroup, $log->ruleLevel);
                    }
                } else {
                    $recips = $resolver->resolveForRule((int)$log->student_id, $log->rule);
                }

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
