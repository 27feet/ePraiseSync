<?php

namespace App\Jobs;

use App\Models\Point;
use App\Support\ArborHttp;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeletePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;
    protected ?int $lastErrorStatus = null;
    protected ?string $lastErrorBody = null;

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

        $client = ArborHttp::client();

        // If no remote ID, consider already deleted: mark locally and exit
        if (!$this->point->arbor_id) {
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_DELETED,
                'sync_attempts' => 0,
                'sync_last_failed_at' => null,
                'sync_next_retry_at' => null,
                'sync_last_error_status' => null,
                'sync_last_error_body' => null,
            ])->save();
            return 0;
        }

        $res = $client->delete('/rest-v2/point-awards/' . $this->point->arbor_id);
        $writeCount++;

        if ($res->successful() || in_array($res->status(), [404, 410], true)) {
            $this->point->delete();
        } else {
            $this->rememberError($res->status(), $res->body());
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
                'sync_next_retry_at' => null,
                'sync_last_error_status' => $this->lastErrorStatus,
                'sync_last_error_body' => $this->lastErrorBody,
            ])->save();
        }

        return $writeCount;
    }

    private function rememberError(?int $status, string $body): void
    {
        $this->lastErrorStatus = $status;
        $this->lastErrorBody = substr($body, 0, 4000);
    }
}
