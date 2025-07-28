<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KillConsentTest extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {

        $restArray = [
            'request' => [
                'consentType' => [
                    'active' => 'false',
                    'archived' => 'true',
                    'consentTypeName' => 'Dentistry Consent',
                ]
            ]
        ];

        $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
        $authstring = base64_encode($authtmp);
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $authstring, // Example header
            'Content-Type' => 'application/json',

        ])->put(
            'https://api-sandbox2.uk.arbor.sc/rest-v2/Consent-Type/CONTACT_DENTIST',
            $restArray
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
