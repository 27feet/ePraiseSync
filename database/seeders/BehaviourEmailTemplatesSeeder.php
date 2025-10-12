<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BehaviourEmailTemplate;
use Illuminate\Support\Carbon;

class BehaviourEmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $rows = [
            // Uniform 5 / 10 / 15
            [
                'key'       => 'uniform_5',
                'subject'   => 'Uniform concern for {{ student_name }} – 5 in current term',
                'body_html' => <<<HTML
<p>Dear {{ staff_or_parent }},</p>
<p>{{ student_name }} has reached <strong>5 uniform demerits</strong> this term ({{ window_start }} to {{ window_end }}).</p>
<p>Category: {{ category }}<br>
Count in window: <strong>{{ count }}</strong></p>
<p>Please review and take appropriate action.</p>
HTML,
                'body_text' => "Uniform concern for {{ student_name }} – 5 in current term\nCount: {{ count }}\nCategory: {{ category }}\nWindow: {{ window_start }} → {{ window_end }}",
            ],
            [
                'key'       => 'uniform_10',
                'subject'   => 'Uniform concern for {{ student_name }} – 10 in current term',
                'body_html' => '<p>{{ student_name }} has reached <strong>10</strong> uniform demerits this term.</p>',
                'body_text' => 'Uniform concern: {{ student_name }} has reached 10 this term.',
            ],
            [
                'key'       => 'uniform_15',
                'subject'   => 'Uniform concern for {{ student_name }} – 15 in current term',
                'body_html' => '<p>{{ student_name }} has reached <strong>15</strong> uniform demerits this term.</p>',
                'body_text' => 'Uniform concern: {{ student_name }} has reached 15 this term.',
            ],

            // Late 5 / 10 / 15
            [
                'key'       => 'late_5',
                'subject'   => 'Punctuality concern for {{ student_name }} – 5 in current term',
                'body_html' => '<p>{{ student_name }} has reached <strong>5</strong> late demerits this term.</p>',
                'body_text' => 'Punctuality concern: {{ student_name }} has reached 5 this term.',
            ],
            [
                'key'       => 'late_10',
                'subject'   => 'Punctuality concern for {{ student_name }} – 10 in current term',
                'body_html' => '<p>{{ student_name }} has reached <strong>10</strong> late demerits this term.</p>',
                'body_text' => 'Punctuality concern: {{ student_name }} has reached 10 this term.',
            ],
            [
                'key'       => 'late_15',
                'subject'   => 'Punctuality concern for {{ student_name }} – 15 in current term',
                'body_html' => '<p>{{ student_name }} has reached <strong>15</strong> late demerits this term.</p>',
                'body_text' => 'Punctuality concern: {{ student_name }} has reached 15 this term.',
            ],

            // Skirt 5 / 10 / 15 / 20
            [
                'key'       => 'skirt_5',
                'subject'   => 'Skirt uniform concern – {{ student_name }} (5 this term)',
                'body_html' => '<p>{{ student_name }} has reached <strong>5</strong> skirt-related demerits this term.</p>',
                'body_text' => 'Skirt concern: {{ student_name }} has reached 5 this term.',
            ],
            [
                'key'       => 'skirt_10',
                'subject'   => 'Skirt uniform concern – {{ student_name }} (10 this term)',
                'body_html' => '<p>{{ student_name }} has reached <strong>10</strong> skirt-related demerits this term.</p>',
                'body_text' => 'Skirt concern: {{ student_name }} has reached 10 this term.',
            ],
            [
                'key'       => 'skirt_15',
                'subject'   => 'Skirt uniform concern – {{ student_name }} (15 this term)',
                'body_html' => '<p>{{ student_name }} has reached <strong>15</strong> skirt-related demerits this term.</p>',
                'body_text' => 'Skirt concern: {{ student_name }} has reached 15 this term.',
            ],
            [
                'key'       => 'skirt_20',
                'subject'   => 'Escalation: Skirt uniform – {{ student_name }} (20 this term)',
                'body_html' => '<p><strong>Escalation</strong>: {{ student_name }} has reached <strong>20</strong> skirt demerits this term.</p>',
                'body_text' => 'Escalation: {{ student_name }} has reached 20 skirt demerits this term.',
            ],
        ];

        foreach ($rows as $r) {
            BehaviourEmailTemplate::updateOrCreate(
                ['key' => $r['key']],
                [
                    'subject'    => $r['subject'],
                    'body_html'  => $r['body_html'],
                    'body_text'  => $r['body_text'],
                    'is_active'  => true,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
