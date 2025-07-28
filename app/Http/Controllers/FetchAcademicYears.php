<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\Term;

class FetchAcademicYears extends Controller
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

  AcademicYear(page_size: 2, page_num: 0, startDate_after: "$fromDate") {
	id
  code
  academicYearName
  startDate
  endDate
  terms {
    id
    termName
    startDate
    endDate
    firstSchoolDay {
      id
      startDate
    }
    lastSchoolDay {
      id
      startDate
    }
  }
  }}
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $yearCollection = $response->getData()['AcademicYear'];
      foreach ($yearCollection as $year) {
        AcademicYear::updateOrCreate(
          ['arbor_id' => $year['id']],
          [
            'academic_year_name' => $year['academicYearName'],
            'code' => $year['code'],
            'start_date' => Carbon::parse($year['startDate'])->timezone('Europe/London'),
            'end_date' => Carbon::parse($year['endDate'])->timezone('Europe/London'),
          ]
        );
        foreach ($year['terms'] as $term) {
          Term::updateOrCreate(
            ['arbor_id' => $term['id']],
            [
              'term_name' => $term['termName'],
              'academic_year_id' => $year['id'],
              'start_date' => Carbon::parse($term['startDate'])->timezone('Europe/London'),
              'end_date' => Carbon::parse($term['endDate'])->timezone('Europe/London'),
              'first_school_day_id' => $term['firstSchoolDay']['id'],
              'last_school_day_id' => $term['lastSchoolDay']['id'],
              'first_school_day_date' => Carbon::parse($term['firstSchoolDay']['startDate'])->timezone('Europe/London'),
              'last_school_day_date' => Carbon::parse($term['lastSchoolDay']['startDate'])->timezone('Europe/London'),
            ]
          );
        }
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($yearCollection) < 2) {
        $checkMore = false;
      }
    }
  }
}
