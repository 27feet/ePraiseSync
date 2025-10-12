<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UpdateLoggedItemsService;

class FetchInterventions extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, String $fromDate, bool $includeAmendments)
    {
        $fetchService = new UpdateLoggedItemsService();
        $fetchService->fetchInterventions($request, $fromDate, $includeAmendments);
    }
}
