<?php

namespace App\Jobs;

use App\Models\Demerit;
use App\Jobs\Concerns\HandlesArborWriteback;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteDemerit implements ShouldQueue
{
    use Queueable;
    use HandlesArborWriteback;

    protected Demerit $demerit;
    protected ?int $lastErrorStatus = null;
    protected ?string $lastErrorBody = null;

    public function __construct(string $demeritID)
    {
        $this->demerit = Demerit::where('id', $demeritID)->firstOrFail();
    }

    protected function performWriteback(): int
    {
        $writeCount = 0;

        $client = $this->arborClient();

        // ---------- Normalize action IDs into an associative array ----------
        $raw = $this->demerit->arbor_action_id;
        $actions = [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $actions = $decoded;
            }
        } elseif ($raw instanceof \Illuminate\Support\Collection) {
            $actions = $raw->toArray();
        } elseif (is_array($raw)) {
            $actions = $raw;
        }

        // If it's a plain numeric list, lift to stable keys (so we can clear individually)
        if (array_values($actions) === $actions) {
            $tmp = [];
            foreach ($actions as $idx => $val) {
                $tmp['action' . $idx] = $val;
            }
            $actions = $tmp;
        }

        // Working copy to mutate as we successfully delete
        $remainingActions = $actions;

        // Treat 2xx/404/410 as "ok"
        $ok = fn($res) => $res->successful() || in_array($res->status(), [404, 410], true);

        // Track outcomes (assume true if there is nothing to delete)
        $actionsDeleted     = empty(array_filter($actions)); // no IDs present => nothing to delete
        $involvementDeleted = !$this->demerit->arbor_behaviour_student_id;
        $incidentDeleted    = !$this->demerit->arbor_behaviour_id;
        $pointDeleted       = !$this->demerit->arbor_point_id;

        // ---------- Delete behavioural incident actions (clear individually) ----------
        foreach ($actions as $label => $id) {
            if (!$id) continue;

            $res = $client->delete('/rest-v2/behavioural-incident-actions/' . $id);
            $writeCount++;

            if ($ok($res)) {
                $remainingActions[$label] = null;
                $this->demerit->arbor_action_id = $remainingActions; // clear just this one
            } else {
                $this->rememberError($res->status(), $res->body());
                $actionsDeleted = false;
                Log::warning('DeleteDemerit: delete behavioural-incident-action failed', [
                    'demerit_id' => $this->demerit->id,
                    'action_key' => $label,
                    'action_id'  => $id,
                    'status'     => $res->status(),
                    'body'       => $res->body(),
                ]);
            }
        }

        // After per-action deletes, determine if *all* actions are now gone
        if (!empty($actions)) {
            $anyLeft = false;
            foreach ($remainingActions as $v) {
                if (!empty($v)) {
                    $anyLeft = true;
                    break;
                }
            }
            $actionsDeleted = !$anyLeft;
        }

        // ---------- Delete student involvement ----------
        if ($this->demerit->arbor_behaviour_student_id) {
            $res = $client->delete('/rest-v2/behavioural-incident-student-involvements/' . $this->demerit->arbor_behaviour_student_id);
            $writeCount++;

            if ($ok($res)) {
                $involvementDeleted = true;
                $this->demerit->arbor_behaviour_student_id = null;
            } else {
                $this->rememberError($res->status(), $res->body());
                Log::warning('DeleteDemerit: delete behavioural-incident-student-involvement failed', [
                    'demerit_id'     => $this->demerit->id,
                    'involvement_id' => $this->demerit->arbor_behaviour_student_id,
                    'status'         => $res->status(),
                    'body'           => $res->body(),
                ]);
            }
        }

        // ---------- Delete behavioural incident ----------
        if ($this->demerit->arbor_behaviour_id) {
            $res = $client->delete('/rest-v2/behavioural-incidents/' . $this->demerit->arbor_behaviour_id);
            $writeCount++;

            if ($ok($res)) {
                $incidentDeleted = true;
                $this->demerit->arbor_behaviour_id = null;
            } else {
                $this->rememberError($res->status(), $res->body());
                Log::warning('DeleteDemerit: delete behavioural-incident failed', [
                    'demerit_id'  => $this->demerit->id,
                    'behaviour_id' => $this->demerit->arbor_behaviour_id,
                    'status'      => $res->status(),
                    'body'        => $res->body(),
                ]);
            }
        }

        // ---------- Delete point award ----------
        if ($this->demerit->arbor_point_id) {
            $res = $client->delete('/rest-v2/point-awards/' . $this->demerit->arbor_point_id);
            $writeCount++;

            if ($ok($res)) {
                $pointDeleted = true;
                $this->demerit->arbor_point_id = null;
            } else {
                $this->rememberError($res->status(), $res->body());
                Log::warning('DeleteDemerit: delete point-award failed', [
                    'demerit_id' => $this->demerit->id,
                    'point_id'   => $this->demerit->arbor_point_id,
                    'status'     => $res->status(),
                    'body'       => $res->body(),
                ]);
            }
        }

        // ---------- Finalize: clear only what actually deleted ----------
        // Clean remaining actions (drop null/empty), or set to null if none left
        $cleanRemaining = [];
        foreach ($remainingActions as $k => $v) {
            if (!empty($v)) $cleanRemaining[$k] = $v;
        }
        $finalActions = empty($cleanRemaining) ? null : $cleanRemaining;

        $syncStatus = ($actionsDeleted && $involvementDeleted && $incidentDeleted && $pointDeleted)
            ? Demerit::STATUS_DELETED
            : Demerit::STATUS_FAILED_DELETION;
            
        if ($syncStatus === Demerit::STATUS_DELETED) {
            Log::info('DeleteDemerit: Demerit fully deleted in Arbor', [
                'demerit_id' => $this->demerit->id,
            ]);
            $this->demerit->delete();
        } else {
            $this->demerit->forceFill([
                'arbor_action_id'            => $finalActions,
                'arbor_behaviour_student_id' => $involvementDeleted ? null : $this->demerit->arbor_behaviour_student_id,
                'arbor_behaviour_id'         => $incidentDeleted    ? null : $this->demerit->arbor_behaviour_id,
                'arbor_point_id'             => $pointDeleted       ? null : $this->demerit->arbor_point_id,
                'sync_status'                => $syncStatus,
                'synced_at'                  => now(),
                'sync_next_retry_at'         => null,
                'sync_last_error_status'     => $this->lastErrorStatus,
                'sync_last_error_body'       => $this->lastErrorBody,
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
