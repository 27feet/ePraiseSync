<?php

namespace App\Jobs;

use App\Models\Demerit;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class FixDemerit implements ShouldQueue
{
    use Queueable;

    protected Demerit $demerit;

    /**
     * Create a new job instance.
     */
    public function __construct(string $demeritID)
    {
        $this->demerit = Demerit::where('id', $demeritID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $baseUrl = rtrim(env('ARBOR_URL'), '/');
        if (!$this->demerit) {
            return;
        } else {
            $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
            $authstring = base64_encode($authtmp);

            $action_ids = $this->demerit->arbor_action_id;
            if (!$action_ids) {
            } else {
                foreach ($action_ids as $action_id) {
                    logger(Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->asJson()->delete(
                        $baseUrl . '/rest-v2/behavioural-incident-action/' . $action_id,
                    ));
                };
            };

            if (!$this->demerit->arbor_behaviour_student_id) {
            } else {
                logger(Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->asJson()->delete(
                    $baseUrl . '/rest-v2/behavioural-incident-student-involvement/' . $this->demerit->arbor_behaviour_student_id,
                ));
            };

            if (!$this->demerit->arbor_behaviour_id) {
            } else {
                logger(Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->asJson()->delete(
                    $baseUrl . '/rest-v2/behavioural-incident/' . $this->demerit->arbor_behaviour_id,
                ));
            };

            if (!$this->demerit->arbor_point_id) {
            } else {
                logger(Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->asJson()->delete(
                    $baseUrl . '/rest-v2/point-award/' . $this->demerit->arbor_point_id,
                ));
            };

            $this->demerit['sync_status'] = 0;
            $this->demerit['synced_at'] = null;
            $this->demerit->save();
        }
    }
}
