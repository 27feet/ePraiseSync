<?php

namespace App\Jobs;

use App\Models\Point;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeletePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;

    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // Status gate
        if ($this->point->sync_status !== Point::STATUS_READY_TO_DELETE) {
            return 0;
        }

        // Pre-mark as failed delete (attempt signal)
        $this->point->forceFill([
            'sync_status' => Point::STATUS_FAILED_DELETION,
        ])->save();

        $client = Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson();

        // If no remote ID, consider already deleted: mark locally and exit
        if (!$this->point->arbor_id) {
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_DELETED,
            ])->save();
            return 0;
        }

        $res = $client->delete('/rest-v2/point-awards/' . $this->point->arbor_id);
        $writeCount++;

        if ($res->successful() || in_array($res->status(), [404, 410], true)) {
            $this->point->delete();
        } else {
            if (in_array($res->status(), [401, 403], true)) {
                Log::warning('DeletePoint: auth/permission error', [
                    'point_id' => $this->point->id,
                    'arbor_id' => $this->point->arbor_id,
                    'status'   => $res->status(),
                    'body'     => $res->body(),
                ]);
            } else {
                Log::warning('DeletePoint: remote delete failed', [
                    'point_id' => $this->point->id,
                    'arbor_id' => $this->point->arbor_id,
                    'status'   => $res->status(),
                    'body'     => $res->body(),
                ]);
            }

            // keep FAILED_DELETE + synced_at
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_FAILED_DELETION,
            ])->save();
        }

        return $writeCount;
    }
}
