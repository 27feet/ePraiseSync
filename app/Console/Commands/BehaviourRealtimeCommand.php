<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\BehaviourNotificationRule;
use App\Models\BehaviourNotificationLog;
use App\Mail\BehaviourNotificationMail;
use App\Services\BehaviourRecipientResolver;

class BehaviourRealtimeCommand extends Command
{
    protected $signature = 'behaviour:realtime
                            {--interval=15 : minutes between evaluations (for info only)}
                            {--dry : don\'t send emails}
                            {--date= : override date (YYYY-MM-DD)}';

    protected $description = 'Evaluate realtime_today rules for the current school day and send emails.';

    public function handle(BehaviourRecipientResolver $resolver): int
    {
        $dry = (bool)$this->option('dry');
        $asOf = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today('Europe/London')->startOfDay();

        $rules = BehaviourNotificationRule::query()
            ->realtimeToday()
            ->where('is_active', true)
            ->get();

        $sentStaff = 0;
        $sentParents = 0;

        foreach ($rules as $rule) {
            // Only consider logs created for *today* window for this rule that do not yet have recipients_snapshot
            $logs = BehaviourNotificationLog::query()
                ->where('rule_id', $rule->id)
                ->todayWindow()
                ->whereDate('window_start_date', $asOf->toDateString())
                ->get();

            foreach ($logs as $log) {
                // Resolve recipients from routing + parent contacts
                $recips = $resolver->resolve($log->student_id, $rule);

                // Record recipients (idempotency & audit)
                $log->forceFill(['recipients_snapshot' => $recips])->save();

                if ($dry) continue;

                // STAFF: one email with all staff recipients
                if (!empty($recips['staff'])) {
                    $to = array_column($recips['staff']['to'] ?? [], 'email');
                    $cc = array_column($recips['staff']['cc'] ?? [], 'email');

                    if ($to) {
                        Mail::to($to)->cc($cc)->send(new BehaviourNotificationMail([
                            'template_key' => $rule->template_key,
                            'rule'         => $rule,
                            'log'          => $log,
                            'audience'     => 'staff',
                        ]));
                        $sentStaff++;
                        usleep(150000); // ~0.15s between sends to respect rate limits
                    }
                }

                // PARENTS: individual emails
                foreach (($recips['parents'] ?? []) as $parent) {
                    Mail::to($parent['email'])->send(new BehaviourNotificationMail([
                        'template_key' => $rule->template_key,
                        'rule'         => $rule,
                        'log'          => $log,
                        'audience'     => 'parent',
                        'parent'       => $parent,
                    ]));
                    $sentParents++;
                    usleep(150000);
                }
            }
        }

        $this->info("Done. Sent {$sentStaff} staff email(s), {$sentParents} parent email(s).");
        return self::SUCCESS;
    }
}
