<?php

namespace App\Http\Controllers;

use App\Models\Point;
use App\Models\Demerit;
use App\Models\Intervention;
use Illuminate\Http\Request;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use App\Models\EpraiseDemeritCategory;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use App\Models\EpraiseInterventionCategory;

class UpdateEpraiseInterventionCategories extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $interventionCategories = Intervention::select('intervention_type')->distinct()->pluck('intervention_type')->toArray();
        foreach ($interventionCategories as $category) {
            EpraiseInterventionCategory::updateOrCreate(
                ['epraise_name' => $category]
            );
        }
    }
}
