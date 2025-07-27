<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use Arbor\Model\Hydrator;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use Arbor\Model\ModelBase;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeletePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;

    /**
     * Create a new job instance.
     */
    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        if (!$this->point) {
            logger('Point not found for ID: ' . $this->point->id);
            return;
        } else {
            $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
            $authstring = base64_encode($authtmp);
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $authstring,
                'Content-Type' => 'application/json',
            ])->delete(
                'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/' . $this->point->arbor_id,
            );

            if ($response->successful()) {
                $this->point->delete();
            } else {
                $statusCode = $response->status();
                $errorMessage = $response->body();
                logger($statusCode);
                logger($errorMessage);
            }
        }
    }
}
