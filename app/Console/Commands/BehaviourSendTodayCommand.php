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
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send behaviour notification emails for logs created on the given date (default: today).';

    public function handle(): int
    {
        $dateOpt = $this->option('date');
        $asOf    = $dateOpt ? Carbon::parse($dateOpt) : now();
        $start   = $asOf->copy()->startOfDay();
        $end     = $asOf->copy()->endOfDay();
        $dryRun  = (bool) $this->option('dry-run');

        /** @var BehaviourRecipientResolver $resolver */
        $resolver = app(BehaviourRecipientResolver::class);

        $logs = BehaviourNotificationLog::query()
            ->with('rule')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            $this->info("No behaviour_notification_logs created between {$start} and {$end}.");
            return self::SUCCESS;
        }

        $this->info("Preparing to " . ($dryRun ? 'preview' : 'send') . " emails for {$logs->count()} log(s) dated {$start->toDateString()}.");

        $sentStaff   = 0;
        $sentParents = 0;

        foreach ($logs as $log) {
            if (!$log->rule) {
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

            // Resolve recipients for this student + rule (expects record_id)
            $recips = $resolver->resolveForRule($studentRecordId, $log->rule);
            $staffEmails   = $recips['staff']   ?? [];
            $parentTargets = $recips['parents'] ?? [];

            // Build a shared context for templating
            $snap = is_array($log->source_snapshot) ? $log->source_snapshot : [];
            $contextBase = [
                'student_name' => $studentName,
                'category'     => $snap['category'] ?? ($log->rule->category ?? ''),
                'count'        => $snap['value']    ?? '',
                'threshold'    => $log->rule->threshold ?? '',
                'window_start' => optional($log->window_start_date)->toDateString(),
                'window_end'   => optional($log->window_end_date)->toDateString(),
                'as_of'        => optional($log->created_at)->toDateTimeString(),
            ];

            // 1) Staff — single email to all staff recipients (if any)
            if (!empty($staffEmails) && $log->rule->recipient_staff) {
                $context = array_merge($contextBase, [
                    'staff_or_parent' => 'colleague',
                ]);

                if ($dryRun) {
                    $this->line(" • [STAFF] Log #{$log->id} → " . implode(', ', $staffEmails)
                        . " | template={$log->rule->template_key} | student={$studentName}");
                } else {
                    Mail::to($staffEmails)->send(
                        new BehaviourNotificationMail($context, (string) $log->rule->template_key)
                    );
                    $sentStaff++;
                }
            }

            // 2) Parents — one email per parent recipient (if any)
            if (!empty($parentTargets) && $log->rule->recipient_parents) {
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
                            . " | template={$log->rule->template_key} | student={$studentName}");
                    } else {
                        Mail::to($email)->send(
                            new BehaviourNotificationMail($context, (string) $log->rule->template_key)
                        );
                        $sentParents++;
                    }
                }
            }

            // Optional: store who we sent to
            if (!$dryRun) {
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
