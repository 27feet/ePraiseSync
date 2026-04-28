<?php

namespace App\Jobs;

use App\Models\Demerit;
use App\Support\ArborHttp;
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

        if (!$this->demerit) {
            return;
        } else {
            $client = ArborHttp::client();

            $action_ids = $this->demerit->arbor_action_id;
            if (!$action_ids) {
            } else {
                foreach ($action_ids as $action_id) {
                    logger($client->delete('/rest-v2/behavioural-incident-action/' . $action_id));
                };
            };

            if (!$this->demerit->arbor_behaviour_student_id) {
            } else {
                logger($client->delete('/rest-v2/behavioural-incident-student-involvement/' . $this->demerit->arbor_behaviour_student_id));
            };

            if (!$this->demerit->arbor_behaviour_id) {
            } else {
                logger($client->delete('/rest-v2/behavioural-incident/' . $this->demerit->arbor_behaviour_id));
            };

            if (!$this->demerit->arbor_point_id) {
            } else {
                logger($client->delete('/rest-v2/point-award/' . $this->demerit->arbor_point_id));
            };

            $this->demerit['sync_status'] = 0;
            $this->demerit['synced_at'] = null;
            $this->demerit->save();
        }
    }
}
