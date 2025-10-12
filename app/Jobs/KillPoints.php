<?php

namespace App\Jobs;

use App\Models\Point;
use App\Models\Demerit;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class FixPoint implements ShouldQueue
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

        $baseUrl = rtrim(env('ARBOR_URL'), '/');
        if (!$this->point) {
            return;
        } else {
            $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
            $authstring = base64_encode($authtmp);

            if (!$this->point->arbor_id) {
            } else {
                logger(Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->asJson()->delete(
                    $baseUrl . '/rest-v2/point-award/' . $this->point->arbor_id,
                ));
            };

            $this->point['sync_status'] = 0;
            $this->point['synced_at'] = null;
            $this->point->save();
        }
    }
}
