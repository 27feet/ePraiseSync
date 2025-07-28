<?php

namespace App\Http\Controllers;

use App\Models\Point;
use App\Models\Student;
use App\Models\ArborStaff;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchArborStudents extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request)
  {

    $pageNum = 0;
    $checkMore = true;
    $query = <<<GQL
query {
  Student(page_size: 2000, page_num: 0){
    
  id
  displayName
  
  academicLevel {
    displayName
    tutors
    {
      primaryEmailAddress
      {emailAddress}
    }
  }
  registrationForm {
    displayName
    tutors{
      primaryEmailAddress{
        emailAddress
      }
    }
  }
  }
  }

  
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $studentCollection = $response->getData()['Student'];
      foreach ($studentCollection as $student) {
        $studentRecord = Student::where('arbor_id', $student['id'])->first();
        if (!$studentRecord) {
        } else {
          $studentRecord->update([
            'year_group' => $student['academicLevel']['displayName'],
            'year_leader_emails' => $student['academicLevel']['tutors'] ? array_map(fn($tutor) => $tutor['primaryEmailAddress']['emailAddress'], $student['academicLevel']['tutors']) : [],
            'tutor_emails' => $student['registrationForm']['tutors'] ? array_map(fn($tutor) => $tutor['primaryEmailAddress']['emailAddress'], $student['registrationForm']['tutors']) : [],
            'name' => $student['displayName'],
          ]);
        }
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($studentCollection) < 2000) {
        $checkMore = false;
      }
    }
  }
}
