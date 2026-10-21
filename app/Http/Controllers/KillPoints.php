<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KillPoints extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $baseUrl = rtrim(env('ARBOR_URL'), '/');
        $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
        $authstring = base64_encode($authtmp);
        for ($x = 120207; $x <= 120515; $x++) {
            logger(Http::withMiddleware(\App\Support\ArborHttp::middleware())->withHeaders([
                'Authorization' => 'Basic ' . $authstring,
                'Content-Type' => 'application/json',
            ])->asJson()->delete(
                $baseUrl . '/rest-v2/behavioural-incident/' . $x,
            ));
        }
    }
}
