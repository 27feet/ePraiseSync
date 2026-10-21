<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TestPoints extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
        $authstring = base64_encode($authtmp);
        $response = Http::withMiddleware(\App\Support\ArborHttp::middleware())->withHeaders([
            'Authorization' => 'Basic ' . $authstring, // Example header
            'Content-Type' => 'application/json',
        ])->delete(
            'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/559428',
        );

        if ($response->successful()) {
            logger($response->json());
            $data = $response->json();
            // Process the successful response data
        } else {
            $statusCode = $response->status();
            $errorMessage = $response->body();
            logger($statusCode);
            logger($errorMessage);
            // Handle the error
        }
    }
}
