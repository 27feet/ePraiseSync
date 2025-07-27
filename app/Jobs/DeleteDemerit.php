<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Demerit;
use App\Models\Student;
use App\Models\Teacher;
use Arbor\Model\Hydrator;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteDemerit implements ShouldQueue
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
            $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
            $authstring = base64_encode($authtmp);

            $action_ids = $this->demerit->arbor_action_id;
            if (!$action_ids) {
            } else {
                foreach ($action_ids as $action_id) {
                    Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->delete(
                        'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Action/' . $action_id,
                    );
                };
            };

            if (!$this->demerit->arbor_behaviour_student_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Student-Involvement/' . $this->demerit->arbor_behaviour_student_id,
                );
            };

            if (!$this->demerit->arbor_behaviour_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident/' . $this->demerit->arbor_behaviour_id,
                );
            };

            if (!$this->demerit->arbor_point_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/' . $this->demerit->arbor_point_id,
                );
            };

            $this->demerit->delete();
        }
    }
}
