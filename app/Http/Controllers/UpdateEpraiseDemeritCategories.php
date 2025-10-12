<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FetchBackgroundDataService;

class UpdateEpraiseDemeritCategories extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {

        $fetchService = new FetchBackgroundDataService();
        $fetchService->updateDemeritCategories($request);
    }
}
