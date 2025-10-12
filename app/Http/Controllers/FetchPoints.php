<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UpdateLoggedItemsService;

class FetchPoints extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request, String $fromDate, bool $includeAmendments)
    {
        $fetchService = new UpdateLoggedItemsService();
        $fetchService->fetchPoints($request, $fromDate, $includeAmendments);
    }
}
