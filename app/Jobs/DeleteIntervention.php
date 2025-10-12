<?php

namespace App\Jobs;

use App\Models\Intervention;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteIntervention implements ShouldQueue
{
    use Queueable;

    protected Intervention $intervention;

    public function __construct(string $interventionID)
    {
        $this->intervention = Intervention::where('id', $interventionID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        $client = Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson();

        // ---------- Normalize action IDs into an associative array ----------
        $raw = $this->intervention->arbor_action_id;
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

        // Ensure we have a key->id map; if it's a plain list, map numeric keys to stable labels
        if (array_values($actions) === $actions) {
            // plain numeric array, lift to stable keys
            $tmp = [];
            foreach ($actions as $idx => $val) {
                $tmp['action' . $idx] = $val;
            }
            $actions = $tmp;
        }

        // Make a working copy we will mutate as we delete successfully
        $remainingActions = $actions;

        // Helper: treat 2xx/404/410 as "ok"
        $ok = fn($res) => $res->successful() || in_array($res->status(), [404, 410], true);

        // Track outcomes (assume true if there is nothing to delete)
        $actionsDeleted     = empty(array_filter($actions)); // all empty means nothing to delete
        $involvementDeleted = !$this->intervention->arbor_behaviour_student_id;
        $incidentDeleted    = !$this->intervention->arbor_behaviour_id;
        $pointDeleted       = !$this->intervention->arbor_point_id;

        // ---------- Delete each behavioural incident action ----------
        foreach ($actions as $label => $id) {
            if (!$id) {
                continue;
            }
            $res = $client->delete('/rest-v2/behavioural-incident-actions/' . $id);
            $writeCount++;

            if ($ok($res)) {
                // clear only this one
                $remainingActions[$label] = null;
            } else {
                $actionsDeleted = false;
                Log::warning('DeleteIntervention: delete behavioural-incident-action failed', [
                    'intervention_id' => $this->intervention->id,
                    'action_key'      => $label,
                    'action_id'       => $id,
                    'status'          => $res->status(),
                    'body'            => $res->body(),
                ]);
            }
        }

        // After per-action deletes, if all are now null/empty, consider actions deleted
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
        if ($this->intervention->arbor_behaviour_student_id) {
            $res = $client->delete('/rest-v2/behavioural-incident-student-involvements/' . $this->intervention->arbor_behaviour_student_id);
            $writeCount++;

            if ($ok($res)) {
                $involvementDeleted = true;
            } else {
                Log::warning('DeleteIntervention: delete behavioural-incident-student-involvement failed', [
                    'intervention_id' => $this->intervention->id,
                    'involvement_id'  => $this->intervention->arbor_behaviour_student_id,
                    'status'          => $res->status(),
                    'body'            => $res->body(),
                ]);
            }
        }

        // ---------- Delete behavioural incident ----------
        if ($this->intervention->arbor_behaviour_id) {
            $res = $client->delete('/rest-v2/behavioural-incidents/' . $this->intervention->arbor_behaviour_id);
            $writeCount++;

            if ($ok($res)) {
                $incidentDeleted = true;
            } else {
                Log::warning('DeleteIntervention: delete behavioural-incident failed', [
                    'intervention_id' => $this->intervention->id,
                    'behaviour_id'    => $this->intervention->arbor_behaviour_id,
                    'status'          => $res->status(),
                    'body'            => $res->body(),
                ]);
            }
        }

        // ---------- Delete point award ----------
        if ($this->intervention->arbor_point_id) {
            $res = $client->delete('/rest-v2/point-awards/' . $this->intervention->arbor_point_id);
            $writeCount++;

            if ($ok($res)) {
                $pointDeleted = true;
            } else {
                Log::warning('DeleteIntervention: delete point-award failed', [
                    'intervention_id' => $this->intervention->id,
                    'point_id'        => $this->intervention->arbor_point_id,
                    'status'          => $res->status(),
                    'body'            => $res->body(),
                ]);
            }
        }

        // ---------- Finalize: clear only what actually deleted ----------
        // Prepare cleaned actions payload (null if none left)
        $cleanRemaining = [];
        foreach ($remainingActions as $k => $v) {
            if (!empty($v)) {
                $cleanRemaining[$k] = $v;
            }
        }
        $finalActions = empty($cleanRemaining) ? null : $cleanRemaining;

        $syncStatus =  ($actionsDeleted && $involvementDeleted && $incidentDeleted && $pointDeleted)
            ? Intervention::STATUS_DELETED
            : Intervention::STATUS_PARTIALLY_DELETED;

        if ($syncStatus === Intervention::STATUS_DELETED) {
            // If everything deleted, we can remove the record entirely
            $this->intervention->delete();
        } else {
            $this->intervention->forceFill([
                'arbor_action_id'            => $finalActions,
                'arbor_behaviour_student_id' => $involvementDeleted ? null : $this->intervention->arbor_behaviour_student_id,
                'arbor_behaviour_id'         => $incidentDeleted    ? null : $this->intervention->arbor_behaviour_id,
                'arbor_point_id'             => $pointDeleted       ? null : $this->intervention->arbor_point_id,
                'sync_status'                => $syncStatus,
                'synced_at'                  => now(),
            ])->save();
        }
        return $writeCount;
    }
}
