<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FetchBackgroundDataService;

class FetchArborClasses extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request, String $fromDate, String $toDate)
  {
    $fetchService = new FetchBackgroundDataService();
    $fetchService->fetchArborClasses($request, $fromDate, $toDate);
  }
}
