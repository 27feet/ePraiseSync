<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UpdateLoggedItemsService;

class FetchDemerits extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, string $fromDate, bool $includeAmendments)
    {
        // Allow overrides via query string for ad-hoc refreshes
        $from = $request->input('from', $fromDate);
        $to = $request->input('to');
        $include = $request->has('includeAmendments')
            ? filter_var($request->input('includeAmendments'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : $includeAmendments;
        $include = is_null($include) ? $includeAmendments : (bool) $include;
        $forceEmpty = $request->boolean('forceEmpty');

        $fetchService = new UpdateLoggedItemsService();
        $fetchService->fetchDemerits($request, (string) $from, $include, $to, $forceEmpty);
    }
}
