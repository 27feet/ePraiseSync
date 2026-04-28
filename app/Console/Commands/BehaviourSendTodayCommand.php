<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\Student;
use App\Models\BehaviourNotificationLog;
use App\Mail\BehaviourNotificationMail;
use App\Services\BehaviourRecipientResolver;

class BehaviourSendTodayCommand extends Command
{
    protected $signature = 'behaviour:send-today
                            {--date= : ISO date (YYYY-MM-DD). Defaults to today}
                            {--dry-run : Show what would be sent without sending}
                            {--test-to= : Send all emails to this address instead of real recipients}';

    protected $description = 'Send behaviour notification emails for logs created on the given date (default: today).';

    public function handle(): int
    {
        $dateOpt = $this->option('date');
        $asOf    = $dateOpt ? Carbon::parse($dateOpt) : now();
        $start   = $asOf->copy()->startOfDay();
        $end     = $asOf->copy()->endOfDay();
        $dryRun  = (bool) $this->option('dry-run');
        $testTo  = $this->option('test-to') ? trim((string) $this->option('test-to')) : null;

        if ($testTo && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid --test-to email: {$testTo}");
            return self::FAILURE;
        }

        /** @var BehaviourRecipientResolver $resolver */
        $resolver = app(BehaviourRecipientResolver::class);
        $legacyEnabled = (bool) config('behaviour_notifications.enable_legacy_rules', false);

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

        $this->info("Preparing to " . ($dryRun ? 'preview' : 'send') . " emails for {$logs->count()} log(s) dated {$start->toDateString()}.");

        $sentStaff   = 0;
        $sentParents = 0;

        foreach ($logs as $log) {
            $isGroupLog = (bool) $log->behaviour_rule_level_id;
            if (!$legacyEnabled && !$isGroupLog) {
                continue;
            }
            if ($isGroupLog) {
                if (!$log->ruleLevel || !$log->ruleGroup) {
                    $this->warn("Log #{$log->id}: missing group/level relation; skipping.");
                    continue;
                }
            } elseif (!$log->rule) {
                $this->warn("Log #{$log->id}: missing rule relation; skipping.");
                continue;
            }

            // ------------------------------------------------------------------
            // FLEXIBLE STUDENT RESOLUTION:
            // - Your logs' student_id may be *record_id* (ePraise) or *arbor_id* (UPN).
            // - Prefer record_id lookup; if not found, fall back to arbor_id.
            // ------------------------------------------------------------------
            $rawId   = (string) $log->student_id;

            $student = Student::where('record_id', $rawId)->first();
            if (!$student) {
                $student = Student::where('arbor_id', $rawId)->first();
            }

            if (!$student) {
                $this->warn("Log #{$log->id}: cannot resolve Student from stored student_id '{$rawId}'; skipping.");
                continue;
            }

            $studentRecordId = (string) $student->record_id;   // what the resolver expects
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

            // Build a shared context for templating
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
            ];

            // 1) Staff — single email to all staff recipients (if any)
            if (!empty($staffEmails) && $recipientStaff) {
                $context = array_merge($contextBase, [
                    'staff_or_parent' => 'colleague',
                ]);

                if ($dryRun) {
                    $this->line(" • [STAFF] Log #{$log->id} → " . implode(', ', $staffEmails)
                        . " | template={$templateKey} | student={$studentName}"
                        . ($testTo ? " | test_to={$testTo}" : ''));
                } else {
                    Mail::to($testTo ?: $staffEmails)->send(
                        new BehaviourNotificationMail($context, $templateKey)
                    );
                    $sentStaff++;
                }
            }

            // 2) Parents — one email per parent recipient (if any)
            if (!empty($parentTargets) && $recipientParents) {
                foreach ($parentTargets as $p) {
                    $email   = $p['email']   ?? null;
                    $title   = $p['title']   ?? null;
                    $surname = $p['surname'] ?? null;

                    if (!$email) {
                        $this->warn("Log #{$log->id}: parent target missing email; skipping one parent.");
                        continue;
                    }

                    $context = array_merge($contextBase, [
                        'staff_or_parent' => trim(($title ? "{$title} " : '') . ($surname ?? 'Parent/Carer')),
                    ]);

                    if ($dryRun) {
                        $this->line(" • [PARENT] Log #{$log->id} → {$email}"
                            . " | template={$templateKey} | student={$studentName}"
                            . ($testTo ? " | test_to={$testTo}" : ''));
                    } else {
                        Mail::to($testTo ?: $email)->send(
                            new BehaviourNotificationMail($context, $templateKey)
                        );
                        $sentParents++;
                    }
                }
            }

            // Optional: store who we sent to
            if (!$dryRun && !$testTo) {
                $existing = is_array($log->recipients_snapshot) ? $log->recipients_snapshot : [];
                $merged = array_merge($existing, [
                    'staff'   => $staffEmails,
                    'parents' => array_values(array_unique(array_map(fn($r) => $r['email'] ?? null, $parentTargets))),
                ]);
                $log->forceFill(['recipients_snapshot' => $merged])->save();
            }
        }

        if ($dryRun) {
            $this->info('Dry run complete. No emails sent.');
        } else {
            $this->info("Done. Sent {$sentStaff} staff email(s) and {$sentParents} parent email(s).");
        }

        return self::SUCCESS;
    }
}
