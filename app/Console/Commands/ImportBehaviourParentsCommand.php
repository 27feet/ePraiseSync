<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\BehaviourNotificationParentContact;
use App\Models\Student;

class ImportBehaviourParentsCommand extends Command
{
    protected $signature = 'behaviour:import-parents';
    protected $description = 'Import parent contacts (per student) from the JSON feed into behaviour_notification_parent_contacts';

    public function handle(): int
    {
        $url      = (string) config('services.behaviour_feeds.parents.url');
        $password = (string) config('services.behaviour_feeds.parents.password'); // pre-encoded token; we only prepend "Basic "
        $timeout  = (int) config('services.behaviour_feeds.timeout', 30);

        if ($url === '' || $password === '') {
            Log::warning('ImportBehaviourParents: missing feed configuration', [
                'url_present'      => $url !== '',
                'password_present' => $password !== '',
            ]);
            $this->error('Missing feed configuration (behaviour_feeds.parents.url/password).');
            return 1;
        }

        $this->info('Fetching parent contacts…');

        $resp = Http::acceptJson()
            ->timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Basic ' . $password, // pre-encoded from env
            ])
            ->get($url);

        if (!$resp->successful()) {
            Log::warning('ImportBehaviourParents: request failed', [
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ]);
            $this->error("Feed request failed with status {$resp->status()}");
            return 1;
        }

        $rows = $resp->json();
        if (!is_array($rows)) {
            Log::warning('ImportBehaviourParents: unexpected payload (not an array)', ['payload' => $rows]);
            $this->error('Unexpected payload from feed (not an array).');
            return 1;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            // Flexible keys from the spreadsheets/feeds
            $studentArborId = $row['student_arbor_id'] ?? $row['studentArborId'] ?? $row['upn'] ?? null;

            $title   = $row['title']   ?? $row['Title']   ?? null;
            $surname = $row['surname'] ?? $row['Surname'] ?? null;
            $email   = $row['email']   ?? $row['Email']   ?? null;

            if (!$studentArborId || !$email) {
                $skipped++;
                Log::warning('ImportBehaviourParents: missing required fields for row', [
                    'index'             => $i,
                    'student_arbor_id'  => $studentArborId,
                    'email_present'     => (bool) $email,
                ]);
                continue;
            }

            $emailNorm = strtolower(trim($email));
            if (!filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                Log::warning('ImportBehaviourParents: invalid email, skipping', [
                    'index' => $i,
                    'email' => $email,
                ]);
                continue;
            }

            // Resolve local student_record_id via arbor_id (if we have it)
            $studentRecordId = Student::where('arbor_id', (int) $studentArborId)->value('record_id');

            // Find existing record by preferred key first (record_id+email), else by arbor_id+email
            $model = null;
            if ($studentRecordId) {
                $model = BehaviourNotificationParentContact::where([
                    'student_record_id' => (int) $studentRecordId,
                    'email_normalized'  => $emailNorm,
                ])->first();
            }
            if (!$model) {
                $model = BehaviourNotificationParentContact::where([
                    'student_arbor_id'  => (int) $studentArborId,
                    'email_normalized'  => $emailNorm,
                ])->first();
            }

            $values = [
                'student_arbor_id' => (int) $studentArborId,
                'title'            => $title,
                'surname'          => $surname,
                'email'            => $email,      // keep original formatting
            ];

            if ($model) {
                // Upgrade to set student_record_id if we’ve just resolved it
                if ($studentRecordId && !$model->student_record_id) {
                    $model->student_record_id = (int) $studentRecordId;
                }
                $model->fill($values);

                if ($model->isDirty()) {
                    $model->save();
                    $updated++;
                }
            } else {
                // Create using the best available key
                $payload = array_merge([
                    'email_normalized'  => $emailNorm,
                    'student_record_id' => $studentRecordId ? (int) $studentRecordId : null,
                ], $values);

                BehaviourNotificationParentContact::create($payload);
                $created++;
            }
        }

        $this->info("Parents imported. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");

        return 0;
    }
}
