<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Point;
use App\Models\CheckPoint;
use Illuminate\Http\Request;
use App\Models\EpraisePointCategory;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Http;

class FetchPoints extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request, String $fromDate, bool $includeAmendments)
    {
        $queryString = env('EPRAISE_POINT_QUERY');
        $queryString = str($queryString)->replace('{School_ID}', env('EPRAISE_SCHOOL_ID'));
        $queryString = str($queryString)->replace('{From_Date}', $fromDate);
        $queryString = str($queryString)->replace('{API_Key}', env('EPRAISE_API_KEY'));

        $points = Http::get($queryString)->json();

        CheckPoint::truncate();

        foreach ($points as $point) {
            CheckPoint::updateOrCreate(
                ['record_id' => $point['id']],
                [
                    'date' => Carbon::parse($point['date'])->setTimezone('Europe/London'),
                ]
            );
            Point::updateOrCreate(
                ['record_id' => $point['id']],
                [
                    'student_id' => $point['student'],
                    'teacher_id' => $point['teacher'],
                    'class_id' => $point['class'],
                    'subject' => $point['subject'],
                    'category' => $point['category'],
                    'date' => Carbon::parse($point['date'])->setTimezone('Europe/London'),
                    'points' => $point['value'],
                ]
            );
        };
        $checkRecords = Point::where('date', '>=', $fromDate)->get();
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
