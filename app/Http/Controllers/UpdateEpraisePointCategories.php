<?php

namespace App\Http\Controllers;

use App\Models\Point;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\EpraisePointCategory;

class UpdateEpraisePointCategories extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $pointCategories = Point::select('category')->distinct()->pluck('category')->toArray();
        foreach ($pointCategories as $category) {
            EpraisePointCategory::updateOrCreate(
                ['epraise_name' => $category]
            );
        }
    }
}
