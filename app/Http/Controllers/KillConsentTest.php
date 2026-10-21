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

        $legacy = config('services.arbor.legacy_consent');
        abort_unless(!empty($legacy['url']) && !empty($legacy['username']) && !empty($legacy['password']), 503, 'Legacy consent tool is disabled until its separate credentials are configured.');
        $authtmp = $legacy['username'] . ':' . $legacy['password'];
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

                    $response = Http::withMiddleware(\App\Support\ArborHttp::middleware())->withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->delete(
                        rtrim($legacy['url'], '/') . '/rest-v2/Consent/' . $consent['consentId'],
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
