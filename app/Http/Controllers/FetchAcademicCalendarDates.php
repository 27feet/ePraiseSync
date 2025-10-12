<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FetchBackgroundDataService;

class FetchAcademicCalendarDates extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request)
  {
    $fetchService = new FetchBackgroundDataService();
    $fetchService->fetchAcademicCalendarDates(request());
  }
}
