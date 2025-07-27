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
use App\Models\Intervention;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteIntervention implements ShouldQueue
{
    use Queueable;

    protected Intervention $intervention;

    /**
     * Create a new job instance.
     */
    public function __construct(string $interventionID)
    {
        $this->intervention = Intervention::where('id', $interventionID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        if (!$this->intervention) {
            return;
        } else {
            $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
            $authstring = base64_encode($authtmp);
            $action_ids = $this->intervention->arbor_action_id;
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

            if (!$this->intervention->arbor_behaviour_student_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Student-Involvement/' . $this->intervention->arbor_behaviour_student_id,
                );
            };

            if (!$this->intervention->arbor_behaviour_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident/' . $this->intervention->arbor_behaviour_id,
                );
            };

            if (!$this->intervention->arbor_point_id) {
            } else {
                Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->delete(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/' . $this->intervention->arbor_point_id,
                );
            };

            $this->intervention->delete();
        }
    }
}
