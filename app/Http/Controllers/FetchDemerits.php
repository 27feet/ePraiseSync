<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Demerit;
use App\Models\CheckDemerit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FetchDemerits extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, String $fromDate, bool $includeAmendments)
    {
        $queryString = env('EPRAISE_DEMERIT_QUERY');
        $queryString = str($queryString)->replace('{School_ID}', env('EPRAISE_SCHOOL_ID'));
        $queryString = str($queryString)->replace('{From_Date}', $fromDate);
        $queryString = str($queryString)->replace('{API_Key}', env('EPRAISE_API_KEY'));

        $demerits = Http::get($queryString)->json();

        CheckDemerit::truncate();

        foreach ($demerits as $demerit) {
            CheckDemerit::updateOrCreate(
                ['record_id' => $demerit['id']],
                [
                    'date' => Carbon::parse($demerit['date'])->setTimezone('Europe/London'),
                ]
            );
            Demerit::updateOrCreate(
                ['record_id' => $demerit['id']],
                [
                    'student_id' => $demerit['student'],
                    'teacher_id' => $demerit['teacher'],
                    'class_id' => $demerit['class'],
                    'subject' => $demerit['subject'],
                    'category' => $demerit['category'],
                    'reason' => $demerit['reason'],
                    'date' => Carbon::parse($demerit['date'])->setTimezone('Europe/London'),
                    'points' => $demerit['value'],
                ]
            );
        }
        $checkRecords = Demerit::where('date', '>=', $fromDate)->get();
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
