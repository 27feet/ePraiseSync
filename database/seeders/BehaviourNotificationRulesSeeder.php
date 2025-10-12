<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BehaviourNotificationRule;

class BehaviourNotificationRulesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Pre-encode JSON fields because upsert() does not apply model casts
        $encodedStaffRoles = json_encode(['tutor', 'head_of_year']); // matches your chosen staff roles
        $encodedExtraEmails = null; // keep null unless you have fixed emails to include

        $common = [
            'source'               => 'demerit',
            'timeframe'            => 'term',
            'rolling_days'         => null,
            'reset_on_term_change' => true,
            'recipient_parents'    => false,
            'recipient_staff'      => true,
            'staff_roles'          => $encodedStaffRoles, // <-- JSON string
            'extra_emails'         => $encodedExtraEmails, // <-- null or JSON string
            'is_active'            => true,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        $rows = [];

        foreach ([5, 10, 15] as $t) {
            $rows[] = array_merge($common, [
                'category'     => 'Uniform',
                'threshold'    => $t,
                'template_key' => "uniform_{$t}",
            ]);
        }

        foreach ([5, 10, 15] as $t) {
            $rows[] = array_merge($common, [
                'category'     => 'Late',
                'threshold'    => $t,
                'template_key' => "late_{$t}",
            ]);
        }

        foreach ([5, 10, 15] as $t) {
            $rows[] = array_merge($common, [
                'category'     => 'Skirt',
                'threshold'    => $t,
                'template_key' => "skirt_{$t}",
            ]);
        }

        // Skirt: 20 (staff + parents)
        $rows[] = array_merge($common, [
            'category'          => 'Skirt',
            'threshold'         => 20,
            'recipient_parents' => true,
            'recipient_staff'   => true,
            'template_key'      => 'skirt_20',
        ]);

        BehaviourNotificationRule::query()->upsert(
            $rows,
            ['source', 'category', 'threshold'],
            [
                'timeframe',
                'rolling_days',
                'reset_on_term_change',
                'recipient_parents',
                'recipient_staff',
                'staff_roles',
                'extra_emails',
                'template_key',
                'is_active',
                'updated_at',
            ]
        );
    }
}
