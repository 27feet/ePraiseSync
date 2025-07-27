<?php

namespace App\Http\Controllers;

use App\Models\Demerit;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class UpdateDemerits extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $arborUrl = env('ARBOR_URL');
        $arborUrl = str($arborUrl)->replace('{School_ID}', env('ARBOR_SCHOOL_ID'));

        $api = new \Arbor\Api\Gateway\RestGateway(
            $arborUrl,
            env('ARBOR_APP_USERNAME'),
            env('ARBOR_APP_PASSWORD')
        );

        \Arbor\Model\ModelBase::setDefaultGateway($api);
        date_default_timezone_set('Europe/London');

        Demerit::whereRaw('synced_at < updated_at')->update(['sync_status' => 0]);

        $updates = Demerit::where('sync_status', 0)
            ->get();

        foreach ($updates as $update) {
        }
    }
}
