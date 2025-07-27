<?php

namespace App\Http\Controllers;

use App\Models\Point;
use App\Models\ArborStaff;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchArborStaff extends Controller
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
  Staff(page_size: 2000, page_num: 0) {
    id
    primaryEmailAddress {
      emailAddress
    }
  }
}
GQL;
    while ($checkMore) {

      $response = ArborGraphQL::query($query);

      $staffCollection = $response->getData()['Staff'];
      foreach ($staffCollection as $staff) {
        if (!$staff['primaryEmailAddress']) {
        } else {
          ArborStaff::updateOrCreate(
            ['arbor_id' => $staff['id']],
            [
              'email' => $staff['primaryEmailAddress']['emailAddress'],
            ]
          );
        }
      }
      $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
      $pageNum++;
      if (count($staffCollection) < 2000) {
        $checkMore = false;
      }
    }
  }
}
