<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FetchBackgroundDataService;

class FetchAcademicYears extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke(Request $request)
  {

    $fetchService1 = new FetchBackgroundDataService();
    $fetchService1->fetchAcademicYears(request());
  }
}
