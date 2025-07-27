<?php

namespace App\Http\Controllers;

use App\Models\Point;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use App\Models\EpraiseDemeritCategory;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\Demerit;

class UpdateEpraiseDemeritCategories extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $demeritCategories = Demerit::select('category')->distinct()->pluck('category')->toArray();
        foreach ($demeritCategories as $category) {
            EpraiseDemeritCategory::updateOrCreate(
                ['epraise_name' => $category]
            );
        }
    }
}
