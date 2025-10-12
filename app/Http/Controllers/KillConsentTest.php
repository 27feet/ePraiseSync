<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class KillConsentTest extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {

        $authtmp = "parentalconsentmbowelland:aud*fnt.HMP*vzp1akv";
        $authstring = base64_encode($authtmp);
        $removeRecord = false;
        $pageNum = 0;
        $checkMore = true;
        $consentQuery = <<<GQL
query {Consent(page_size:950, page_num:0, consentType__archived:true){
 	consentId
  consentType {
    code
  }
  student{
    id
    leavingDate
  }
  }
}
GQL;
        while ($checkMore) {
            $response = ArborGraphQL::query($consentQuery);
            $consentsList = $response->getData()['Consent'];
            foreach ($consentsList as $consent) {
                $removeRecord = false;
                if (is_null($consent['student']['leavingDate'])) {
                    $removeRecord = true;
                } elseif (Carbon::parse($consent['student']['leavingDate'])->timezone('Europe/London') >= now()->subDays(14)) {
                    $removeRecord = true;
                }

                if ($removeRecord) {

                    $response = Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->delete(
                        'https://welland-primary-school.uk.arbor.sc/rest-v2/Consent/' . $consent['consentId'],
                    );

                    if ($response->successful()) {
                    } else {
                        $statusCode = $response->status();
                        $errorMessage = $response->body();
                        logger($statusCode);
                        logger($errorMessage);
                    }
                    logger($response);
                }
            }

            $checkMore = false;
            $consentQuery = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $consentQuery);
            $pageNum++;
            if (count($consentsList) < 1000) {
                $checkMore = false;
            }
        }
    }
}
