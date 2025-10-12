<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FetchBackgroundDataService;

class FetchStudents extends Controller
{
    /**
     * Handle the incoming request.
     */

    public function __invoke(Request $request)
    {
        $fetchService = new FetchBackgroundDataService();
        $fetchService->fetchEpraiseStudents($request);
    }
}
