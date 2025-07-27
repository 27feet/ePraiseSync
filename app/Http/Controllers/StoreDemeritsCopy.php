<?php

namespace App\Http\Controllers;

use App\Models\Demerit;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StoreDemerits extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, String $fromDate)
    {
        $queryString = env('ePraise_Demerit_Query');
        $queryString = str($queryString)->replace('{School_ID}', env('ePraise_School_ID'));
        $queryString = str($queryString)->replace('{From_Date}', $fromDate);
        $queryString = str($queryString)->replace('{API_Key}', env('ePraise_API_Key'));
        //  dd($queryString);
        $config = [
            'api' => [
                'baseUrl' => 'https://api-sandbox2.uk.arbor.sc/rest-v2/',
                'auth' => [
                    'user' => 'malbox27feet',
                    'password' => 'rft1ZEK1mwa*tjq-pdp'
                ]
            ]
        ];
        $api = new \Arbor\Api\Gateway\RestGateway(
            $config['api']['baseUrl'],
            $config['api']['auth']['user'],
            $config['api']['auth']['password']
        );

        \Arbor\Model\ModelBase::setDefaultGateway($api);

        date_default_timezone_set('Europe/London');

        $demerits = Http::get($queryString)->json();

        foreach ($demerits as $data) {
            Demerit::updateOrCreate(
                ['record_id' => $data['id']],
                [
                    'student_id' => $data['student'],
                    'teacher_id' => $data['teacher'],
                    'class_id' => $data['class'],
                    'subject' => $data['subject'],
                    'category' => $data['category'],
                    'reason' => $data['reason'],
                    'date' => $data['date'],
                    'points' => $data['value'],
                ]
            );
        }
        dd('Imported');
        $demerit = new \Arbor\Model\PointAward();
        $demerit->setPointAwardScale($api->retrieve(\Arbor\Resource\ResourceType::POINT_AWARD_SCALE, '3'));
        $demerit->setStudent($api->retrieve(\Arbor\Resource\ResourceType::STUDENT, '101'));
        $demerit->setPoints($data['value']);
        $demerit->setAwardedDatetime(new DateTime($data['date']));
        $demerit->setNarrative($data['reason']);
        $demerit->setAwardedByStaff($api->retrieve(\Arbor\Resource\ResourceType::STAFF, '1020'));
        $demerit->connect($api);
        $demerit->save();
        dd($demerit->getResourceId());
        $test = $api->retrieve(\Arbor\Resource\ResourceType::STUDENT, '101');
        dd($test->getperson()->getlegallastname());

        $staff = new \Arbor\Model\Staff();
        $staff->setPerson(new \Arbor\Model\Person());
        $staff->getPerson()->setDateOfBirth(new DateTime('1979-02-02'));
        $staff->getPerson()->setLegalFirstName('Guillame');
        $staff->getPerson()->setLegalLastName('Boite');
        $staff->getPerson()->setGender($api->retrieve(\Arbor\Resource\ResourceType::GENDER, 'MALE'));
        $staff->getPerson()->setTitle($api->retrieve(\Arbor\Resource\ResourceType::TITLE, 'MR'));
        $staff->setEthnicity($api->retrieve(\Arbor\Resource\ResourceType::ETHNICITY, 'WBRI'));
        $staff->tag('example-reference-id', 123);
        $staff->connect($api);
        $staff->getPerson()->connect($api);

        $staff->getPerson()->save();
        $staff->save();

        $staffCopy = $api->retrieve(\Arbor\Resource\ResourceType::STAFF, $staff->getResourceId());

        $hydrator = new \Arbor\Model\Hydrator();
        print_r($hydrator->extractArray($staffCopy));
        print_r($staffCopy->getUserTags());
        dd($staffCopy->getPerson()->getLegalFirstName());
        // }
    }
}
