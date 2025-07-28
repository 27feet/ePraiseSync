<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use App\Models\AcademicCalendarDate;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchAcademicCalendarDates extends Controller
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

  AcademicCalendarDate(page_size: 2000, page_num: 0, startDate_after: "$fromDate")     {
      id
      startDate
      calendarYear
      calendarMonth
      calendarWeek
      calendarDay
      academicYear{
        id
      }
      term{
        id
      }
      dayOfTerm
      dayOfAcademicYear
      isGoodSchoolDay
    }
  }
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $yearCollection = $response->getData()['AcademicCalendarDate'];
      foreach ($yearCollection as $year) {
        AcademicCalendarDate::updateOrCreate(
          ['arbor_id' => $year['id']],
          [
            'academic_year_id' => $year['academicYear']['id'] ?? null,
            'term_id' => $year['term']['id'] ?? null,
            'date' => Carbon::parse($year['startDate'])->timezone('Europe/London'),
            'calendar_year' => $year['calendarYear'],
            'calendar_month' => $year['calendarMonth'],
            'calendar_day' => $year['calendarDay'],
            'calendar_week' => $year['calendarWeek'],
            'day_of_term' => $year['dayOfTerm'],
            'day_of_academic_year' => $year['dayOfAcademicYear'],
            'is_school_day' => $year['isGoodSchoolDay'],
          ]
        );
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($yearCollection) < 2000) {
        $checkMore = false;
      }
    }
  }
}
