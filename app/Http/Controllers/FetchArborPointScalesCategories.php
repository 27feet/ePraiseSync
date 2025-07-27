<?php

namespace App\Http\Controllers;

use App\Models\Point;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchArborPointScalesCategories extends Controller
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
  PointAwardScale(page_size: 2000, page_num: 0){
    id
    displayName
    pointAwardScaleName
    categories
    {
      id
      displayName
    }
  }
}
GQL;
        while ($checkMore) {

            $response = ArborGraphQL::query($query);

            $scalesCollection = $response->getData()['PointAwardScale'];
            foreach ($scalesCollection as $scale) {
                ArborPointScale::updateOrCreate(
                    ['arbor_id' => $scale['id']],
                    [
                        'arbor_name' => $scale['pointAwardScaleName'],
                        'arbor_display_name' => $scale['displayName'],
                    ]
                );
                $categoryCollection = $scale['categories'];

                foreach ($categoryCollection as $category) {
                    ArborPointCategory::updateOrCreate(
                        ['arbor_id' => $category['id']],
                        [
                            'arbor_display_name' => $category['displayName'],
                            'arbor_point_scale_id' => $scale['id'],
                        ]
                    );
                }
            }
            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($scalesCollection) < 2000) {
                $checkMore = false;
            }
        }
    }
}
