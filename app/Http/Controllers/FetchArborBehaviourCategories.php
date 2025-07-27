<?php

namespace App\Http\Controllers;

use App\Models\Point;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use App\Models\ArborBehaviourCategory;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchArborBehaviourCategories extends Controller
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
  Behaviour(page_size: 2000, page_num: 0){
    id
    displayName
    severity
    active
  }
}
GQL;
        while ($checkMore) {

            $response = ArborGraphQL::query($query);

            $behaviourCollection = $response->getData()['Behaviour'];
            foreach ($behaviourCollection as $behaviour) {
                ArborBehaviourCategory::updateOrCreate(
                    ['arbor_id' => $behaviour['id']],
                    [
                        'arbor_display_name' => $behaviour['displayName'],
                        'severity' => $behaviour['severity'],
                        'active' => $behaviour['active'],
                    ]
                );
            }
            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($behaviourCollection) < 2000) {
                $checkMore = false;
            }
        }
    }
}
