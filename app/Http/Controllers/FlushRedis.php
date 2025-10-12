<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ArborClass;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use App\Models\AcademicCalendarDate;
use ANP\LaravelArbor\Facades\ArborGraphQL;
use Illuminate\Support\Facades\Redis;

class FlushRedis extends Controller
{
  /**
   * Handle the incoming request.
   */


  public function __invoke()
  {
    Redis::command('flushdb');
  }
}
