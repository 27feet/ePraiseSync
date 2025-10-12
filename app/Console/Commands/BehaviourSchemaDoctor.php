<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BehaviourSchemaDoctor extends Command
{
    protected $signature = 'behaviour:doctor';
    protected $description = 'Check that all Behaviour Notification tables/columns exist as expected.';

    public function handle(): int
    {
        $missing = 0;

        // ---------- Helper closures ----------
        $hasTable = fn(string $t) => Schema::hasTable($t);
        $hasCol   = fn(string $t, string $c) => Schema::hasColumn($t, $c);

        $checkTable = function (string $table, array $requiredCols, array $optionalCols = []) use ($hasTable, $hasCol, &$missing) {
            $this->line('');
            $this->line("Checking <info>{$table}</info>…");

            if (!$hasTable($table)) {
                $this->error("  ✖ Table missing: {$table}");
                $missing++;
                return;
            }

            $this->info("  ✓ Table exists");

            foreach ($requiredCols as $col) {
                if (!$hasCol($table, $col)) {
                    $this->error("  ✖ Missing column: {$col}");
                    $missing++;
                } else {
                    $this->line("  • {$col}");
                }
            }

            foreach ($optionalCols as $col) {
                if ($hasCol($table, $col)) {
                    $this->line("  • (optional present) {$col}");
                } else {
                    $this->warn("  • (optional missing) {$col}");
                }
            }
        };

        $this->line('=== Behaviour Notifications Schema Doctor ===');

        // ---------------------------------------------------------------------
        // 1) behaviour_notification_rules
        //    (Source of truth for thresholds & recipients)
        // ---------------------------------------------------------------------
        $checkTable(
            'behaviour_notification_rules',
            [
                'id',
                'source',                 // 'demerit' | 'intervention'
                'category',
                'timeframe',              // 'term' | 'rolling_school_days'
                'threshold',
                'reset_on_term_change',
                'recipient_parents',
                'recipient_staff',
                'template_key',
                'is_active',
                'created_at',
                'updated_at',
            ],
            [
                'rolling_days',           // nullable; used when timeframe='rolling_school_days'
                'staff_roles',            // json nullable
                'extra_emails',           // json nullable
            ]
        );

        // ---------------------------------------------------------------------
        // 2) behaviour_notification_logs
        //    (Idempotency & audit of what we sent, when, to whom)
        // ---------------------------------------------------------------------
        $checkTable(
            'behaviour_notification_logs',
            [
                'id',
                'rule_id',
                'student_id',
                // Window information — we always have dates:
                'window_start_date',
                'window_end_date',
                // For term-bound:
                //   window_term_id is nullable but commonly present for 'term' rules
                // For rolling:
                //   window_school_days_lookback is nullable but commonly present for 'rolling_school_days' rules
                'source_snapshot',
                'recipients_snapshot',
                'created_at',
                'updated_at',
            ],
            [
                'hit_threshold',                 // int; recommended and used by evaluator
                'window_term_id',                // nullable int
                'window_school_days_lookback',   // nullable int
                'window_type',                   // optional helper: 'term' | 'rolling_school_days'
            ]
        );

        // ---------------------------------------------------------------------
        // 3) behaviour_notification_staff_directory
        //    (Staff name→email directory used to resolve tutor/HoY emails)
        // ---------------------------------------------------------------------
        $checkTable(
            'behaviour_notification_staff_directory',
            [
                'id',
                'display_name',
                'email',             // nullable
                'normalized_name',   // nullable, but column should exist
                'created_at',
                'updated_at',
            ]
        );

        // ---------------------------------------------------------------------
        // 4) behaviour_students_lookup
        //    (Resolved student names and staff name lists from feeds)
        // ---------------------------------------------------------------------
        $checkTable(
            'behaviour_students_lookup',
            [
                'id',
                'student_arbor_id',
                'forename',
                'surname',
                'student_name',
                'created_at',
                'updated_at',
            ],
            [
                'tutor_names',          // json of names (strings)
                'head_of_year_names',   // json of names (strings)
            ]
        );

        // ---------------------------------------------------------------------
        // 5) behaviour_notification_parent_contacts
        //    (Parent/Carer contacts per student)
        // ---------------------------------------------------------------------
        $checkTable(
            'behaviour_notification_parent_contacts',
            [
                'id',
                'student_arbor_id',
                'title',
                'surname',
                'email',
                'created_at',
                'updated_at',
            ]
        );

        $this->line('');
        if ($missing > 0) {
            $this->error("Schema check failed: {$missing} problem(s) detected.");
            $this->warn('Run the missing migrations or add reconcile migrations to create the missing columns.');
            return 1;
        }

        $this->info('All good! Behaviour Notifications schema looks consistent.');
        return 0;
    }
}
