<?php

namespace App\Jobs;

use App\Models\Point;
use App\Models\Demerit;
use App\Support\ArborHttp;
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

        if (!$this->point) {
            return;
        } else {
            $client = ArborHttp::client();

            if (!$this->point->arbor_id) {
            } else {
                logger($client->delete('/rest-v2/point-award/' . $this->point->arbor_id));
            };

            $this->point['sync_status'] = 0;
            $this->point['synced_at'] = null;
            $this->point->save();
        }
    }
}
