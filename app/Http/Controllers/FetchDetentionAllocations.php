<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\DetentionAllocation;
use App\Models\DetentionSession;
use App\Models\DetentionType;

class FetchDetentionAllocations extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request)
  {

    $pageNum = 0;
    $checkMore = true;
    $fromDate = Carbon::now()->toDateString();
    $fromDate = "2025-07-17";
    $query = <<<GQL
query {

  Detention(page_size: 2000, page_num: 0, detentionSession__startDatetime_after_or_equal: "$fromDate") {
    id
    reasonForDetention {
      id
    }
    detentionSession {
      id
    }
    student {
      id
    }
  }
}
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $detentionCollection = $response->getData()['Detention'];
      foreach ($detentionCollection as $detention) {
        DetentionAllocation::updateOrCreate(
          ['arbor_id' => $detention['id']],
          [
            'reason_for_detention' => $detention['reasonForDetention']['id'],
            'detention_session_id' => $detention['detentionSession']['id'],
            'student_id' => $detention['student']['id'],
          ]
        );
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($detentionCollection) < 2000) {
        $checkMore = false;
      }
    }
  }
}
