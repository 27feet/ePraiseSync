<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use Illuminate\Http\Request;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchArborClasses extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request, String $fromDate, String $toDate)
  {

    $pageNum = 0;
    $checkMore = true;
    $query = <<<GQL
query {
  Session(page_size: 2000, page_num: 0, startDatetime_after:"$fromDate", startDatetime_before:"$toDate"){
	startDatetime
  endDatetime
  sessionId
  academicUnit{
    academicUnitName
  }
}
}
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $classCollection = $response->getData()['Session'];
      foreach ($classCollection as $class) {
        if (!$class['academicUnit']) {
        } else {
          ArborClass::updateOrCreate(
            ['arbor_id' => $class['id']],
            [
              'name' => $class['academicUnit']['academicUnitName'],
              'start_date_time' => Carbon::parse($class['startDatetime'])->timezone('Europe/London'),
              'end_date_time' => Carbon::parse($class['endDatetime'])->timezone('Europe/London'),
            ]
          );
        }
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($classCollection) < 2000) {
        $checkMore = false;
      }
    }
  }
}
