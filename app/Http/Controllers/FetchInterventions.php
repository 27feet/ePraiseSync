<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Demerit;
use App\Models\CheckDemerit;
use App\Models\Intervention;
use Illuminate\Http\Request;
use App\Models\CheckIntervention;
use Illuminate\Support\Facades\Http;

class FetchInterventions extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, String $fromDate, bool $includeAmendments)
    {
        $queryString = env('EPRAISE_INTERVENTION_QUERY');
        $queryString = str($queryString)->replace('{School_ID}', env('EPRAISE_SCHOOL_ID'));
        $queryString = str($queryString)->replace('{From_Date}', $fromDate);
        $queryString = str($queryString)->replace('{API_Key}', env('EPRAISE_API_KEY'));

        $interventions = Http::get($queryString)->json();

        CheckIntervention::truncate();

        foreach ($interventions as $intervention) {
            CheckIntervention::updateOrCreate(
                ['record_id' => $intervention['id']],
                [
                    'date' => Carbon::parse($intervention['date_added'])->setTimezone('Europe/London'),
                ]
            );
            Intervention::updateOrCreate(
                ['record_id' => $intervention['id']],
                [
                    'student_id' => $intervention['student'],
                    'teacher_id' => $intervention['teacher'],
                    'demerit_id' => $intervention['demerit_id'],
                    'subject' => $intervention['subject'],
                    'intervention_type' => $intervention['intervention_type'],
                    'date_added' => Carbon::parse($intervention['date_added'])->setTimezone('Europe/London'),
                ]
            );
        }
        $checkRecords = Intervention::where('date_added', '>=', $fromDate)->get();
        if ($includeAmendments) {
            foreach ($checkRecords as $checkRecord) {
                if (!$checkRecord->stillLive) {
                    //Flag for deletion if record has been deleted.
                    $checkRecord['sync_status'] = -1;
                    $checkRecord->save();
                } else {
                    if ($checkRecord->sync_status === 1) {
                        if ($checkRecord['updated_at'] > $checkRecord['synced_at']) {
                            //If the record has been updated since it was last synced, set as needing a sync.
                            $checkRecord['sync_status'] = 0;
                            $checkRecord->save();
                        }
                    }
                }
            }
        }
    }
}
