<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\DetentionSession;
use App\Models\DetentionType;

class FetchDetentionSetup extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request)
  {

    $pageNum = 0;
    $checkMore = true;
    $fromDate = Carbon::now()->subYears(2)->toDateString();
    $query = <<<GQL
query {

  DetentionType(page_size: 5, page_num: 0, academicYear__startDate_after_or_equal: "$fromDate") {
  id
  detentionTypeName
  academicYear{
    id
  }  
  detentionSessions{
    id
    startDatetime
    endDatetime
    durationMinutes
  }
}}
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $detentionTypeCollection = $response->getData()['DetentionType'];
      foreach ($detentionTypeCollection as $detentionType) {
        DetentionType::updateOrCreate(
          ['arbor_id' => $detentionType['id']],
          [
            'name' => $detentionType['detentionTypeName'],
            'academic_year_id' => $detentionType['academicYear']['id'],
          ]
        );
        foreach ($detentionType['detentionSessions'] as $detentionSession) {
          DetentionSession::updateOrCreate(
            ['arbor_id' => $detentionSession['id']],
            [
              'detention_type_id' => $detentionType['id'],
              'start_date_time' => Carbon::parse($detentionSession['startDatetime'])->timezone('Europe/London'),
              'end_date_time' => Carbon::parse($detentionSession['endDatetime'])->timezone('Europe/London'),
              'durationMinutes' => $detentionSession['durationMinutes'],
            ]
          );
        }
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($detentionTypeCollection) < 5) {
        $checkMore = false;
      }
    }
  }
}
