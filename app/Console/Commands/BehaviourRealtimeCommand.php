<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\BehaviourNotificationLog;
use App\Models\Student;
use App\Mail\BehaviourNotificationMail;
use App\Services\BehaviourRecipientResolver;

class BehaviourRealtimeCommand extends Command
{
    protected $signature = 'behaviour:realtime
                            {--interval=15 : minutes between evaluations (for info only)}
                            {--dry : don\'t send emails}
                            {--date= : override date (YYYY-MM-DD)}
                            {--test-to= : Send all emails to this address instead of real recipients}';

    protected $description = 'Send realtime_today behaviour notifications for the current school day.';

    public function handle(BehaviourRecipientResolver $resolver): int
    {
        $dry = (bool)$this->option('dry');
        $asOf = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today('Europe/London')->startOfDay();
        $testTo = $this->option('test-to') ? trim((string) $this->option('test-to')) : null;
        $legacyEnabled = (bool) config('behaviour_notifications.enable_legacy_rules', false);

        if ($testTo && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid --test-to email: {$testTo}");
            return self::FAILURE;
        }

        $sentStaff = 0;
        $sentParents = 0;

        $logsQuery = BehaviourNotificationLog::query()
            ->with(['rule', 'ruleGroup', 'ruleLevel'])
            ->todayWindow()
            ->whereDate('window_start_date', $asOf->toDateString())
            ->orderBy('id');

        if (!$legacyEnabled) {
            $logsQuery
                ->whereNotNull('behaviour_rule_group_id')
                ->whereNotNull('behaviour_rule_level_id');
        }

        $logs = $logsQuery->get();

        foreach ($logs as $log) {
            $existingRecipients = is_array($log->recipients_snapshot) ? $log->recipients_snapshot : [];
            if (!empty($existingRecipients)) {
                continue;
            }

            $isGroupLog = (bool) $log->behaviour_rule_level_id;
            if (!$legacyEnabled && !$isGroupLog) {
                continue;
            }
            if ($isGroupLog) {
                if (!$log->ruleLevel || !$log->ruleGroup) {
                    $this->warn("Log #{$log->id}: missing group/level relation; skipping.");
                    continue;
                }
                if (($log->ruleGroup->mode ?? null) !== 'realtime_today') {
                    continue;
                }
            } else {
                if (!$log->rule) {
                    $this->warn("Log #{$log->id}: missing rule relation; skipping.");
                    continue;
                }
                if (($log->rule->mode ?? null) !== 'realtime_today') {
                    continue;
                }
            }

            $rawId = (string) $log->student_id;
            $student = Student::where('record_id', $rawId)->first();
            if (!$student) {
                $student = Student::where('arbor_id', $rawId)->first();
            }
            if (!$student) {
                $this->warn("Log #{$log->id}: cannot resolve Student from stored student_id '{$rawId}'; skipping.");
                continue;
            }

            $studentRecordId = (string) $student->record_id;
            $studentArborId  = (string) ($student->arbor_id ?? '');
            $studentName     = $student->name ?: ($studentArborId ?: $studentRecordId);

            if ($isGroupLog) {
                $recips = $resolver->resolveForGroupLevel($studentRecordId, $log->ruleGroup, $log->ruleLevel);
                $templateKey = (string) $log->ruleLevel->template_key;
                $recipientStaff = (bool) $log->ruleLevel->recipient_staff;
                $recipientParents = (bool) $log->ruleLevel->recipient_parents;
                $threshold = (int) $log->ruleLevel->threshold;
            } else {
                $recips = $resolver->resolveForRule($studentRecordId, $log->rule);
                $templateKey = (string) $log->rule->template_key;
                $recipientStaff = (bool) $log->rule->recipient_staff;
                $recipientParents = (bool) $log->rule->recipient_parents;
                $threshold = (int) $log->rule->threshold;
            }

            $staffEmails   = $recips['staff']   ?? [];
            $parentTargets = $recips['parents'] ?? [];

            $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];
            $rawCategory = $snap['category'] ?? null;
            if ($rawCategory === null && is_string($log->category)) {
                $decoded = json_decode($log->category, true);
                $rawCategory = json_last_error() === JSON_ERROR_NONE ? $decoded : $log->category;
            }
            $categoryLabel = is_array($rawCategory)
                ? implode(', ', $rawCategory)
                : (string) ($rawCategory ?? '');
            $rawValue = $snap['raw_value'] ?? ($snap['counter']['raw_value'] ?? ($snap['value'] ?? ($snap['count'] ?? '')));
            $cycleValue = $snap['cycle_value'] ?? ($snap['counter']['effective_value'] ?? ($snap['value'] ?? ($snap['count'] ?? '')));
            $cycleIndex = $snap['counter_cycle'] ?? ($snap['counter']['cycle'] ?? null);
            $rolloverEvery = $snap['rollover_every'] ?? ($snap['counter']['rollover_every'] ?? null);
            $counterMode = $snap['counter_mode'] ?? ($snap['counter']['mode'] ?? 'cumulative');
            $contextBase = [
                'student_name' => $studentName,
                'category'     => $categoryLabel,
                'categories'   => is_array($rawCategory) ? $rawCategory : ($rawCategory ? [$rawCategory] : []),
                'count'        => $cycleValue,
                'value'        => $cycleValue,
                'raw_value'    => $rawValue,
                'cycle_value'  => $cycleValue,
                'cycle_index'  => $cycleIndex,
                'rollover_every' => $rolloverEvery,
                'counter_mode' => $counterMode,
                'threshold'    => $threshold,
                'window_start' => optional($log->window_start_date)->toDateString(),
                'window_end'   => optional($log->window_end_date)->toDateString(),
                'as_of'        => optional($log->created_at)->toDateTimeString(),
                'items'        => $snap['items'] ?? [],
            ];

            if ($dry) {
                if (!empty($staffEmails) && $recipientStaff) {
                    $this->line(" • [STAFF] Log #{$log->id} → " . implode(', ', $staffEmails)
                        . " | template={$templateKey} | student={$studentName}"
                        . ($testTo ? " | test_to={$testTo}" : ''));
                }
                if (!empty($parentTargets) && $recipientParents) {
                    foreach ($parentTargets as $p) {
                        $email = $p['email'] ?? null;
                        if ($email) {
                            $this->line(" • [PARENT] Log #{$log->id} → {$email}"
                                . " | template={$templateKey} | student={$studentName}"
                                . ($testTo ? " | test_to={$testTo}" : ''));
                        }
                    }
                }
                continue;
            }

            if (!empty($staffEmails) && $recipientStaff) {
                $context = array_merge($contextBase, [
                    'staff_or_parent' => 'colleague',
                ]);
                Mail::to($testTo ?: $staffEmails)->send(new BehaviourNotificationMail($context, $templateKey));
                $sentStaff++;
                usleep(150000);
            }

            if (!empty($parentTargets) && $recipientParents) {
                foreach ($parentTargets as $p) {
                    $email   = $p['email']   ?? null;
                    $title   = $p['title']   ?? null;
                    $surname = $p['surname'] ?? null;
                    if (!$email) {
                        continue;
                    }
                    $context = array_merge($contextBase, [
                        'staff_or_parent' => trim(($title ? "{$title} " : '') . ($surname ?? 'Parent/Carer')),
                    ]);
                    Mail::to($testTo ?: $email)->send(new BehaviourNotificationMail($context, $templateKey));
                    $sentParents++;
                    usleep(150000);
                }
            }

            if (!$testTo) {
                $existing = is_array($log->recipients_snapshot) ? $log->recipients_snapshot : [];
                $merged = array_merge($existing, [
                    'staff'   => $staffEmails,
                    'parents' => array_values(array_unique(array_map(fn($r) => $r['email'] ?? null, $parentTargets))),
                ]);
                $log->forceFill(['recipients_snapshot' => $merged])->save();
            }
        }

        $this->info("Done. Sent {$sentStaff} staff email(s), {$sentParents} parent email(s).");
        return self::SUCCESS;
    }
}
