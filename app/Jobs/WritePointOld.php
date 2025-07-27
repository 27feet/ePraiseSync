<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use Arbor\Model\Hydrator;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Arbor\Model\ModelBase;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class WritePointOld implements ShouldQueue
{
    use Queueable;

    protected Point $point;

    /**
     * Create a new job instance.
     */
    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $arborUrl = env('ARBOR_URL');
        $arborUrl = str($arborUrl)->replace('{School_ID}', env('ARBOR_SCHOOL_ID'));
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
            $config['api']['auth']['password'],
        );

        \Arbor\Model\ModelBase::setDefaultGateway($api);
        date_default_timezone_set('Europe/London');

        $data = Point::where('record_id', $this->point->record_id)
            ->firstOrFail()
            ->toArray();
        $stuID = Student::where('record_id', $data['student_id'])
            ->pluck('arbor_id')
            ->first();
        $teacherEmail = Teacher::where('record_id', $data['teacher_id'])
            ->pluck('email')
            ->first();
        $teacherID = ArborStaff::where('email', $teacherEmail)
            ->pluck('arbor_id')
            ->first();

        if (!$stuID) {
        } else {

            $output = new \Arbor\Model\PointAward();
            $hydrator = new Hydrator();


            $stuArray = [
                'student' => [
                    'entityType' => 'Student',
                    'id' => $stuID,
                    'href' => '/rest-v2/students/' . $stuID,
                    'uniqueObjectId' => '1-' . $stuID,
                ]
            ];


            $internalReference = EpraisePointCategory::where('epraise_name', $data['category'])
                ->pluck('arbor_point_category_id')
                ->first();

            $pointCategory = ArborPointCategory::where('id', $internalReference)
                ->pluck('arbor_id')
                ->first();

            if (!$pointCategory) {
            } else {

                $pointScale = ArborPointCategory::where('id', $internalReference)
                    ->first()
                    ->ArborPointScale()
                    ->pluck('arbor_id')
                    ->first();

                $pointCategoryArray = [
                    'pointAwardCategory' => [
                        'entityType' => 'PointAwardCategory',
                        'id' => $pointCategory,
                        'href' => '/rest-v2/point-award-categories/' . $pointCategory,
                        'uniqueObjectId' => '318-' . $pointCategory,
                    ]
                ];
                $pointCategoryModel = $api->instantiateModel(\Arbor\Resource\ResourceType::POINT_AWARD_CATEGORY);
                $hydrator->hydrateModel($pointCategoryModel, $pointCategoryArray[lcfirst(\Arbor\Resource\ResourceType::POINT_AWARD_CATEGORY)]);

                $pointScaleArray = [
                    'pointAwardScale' => [
                        'entityType' => 'PointAwardScale',
                        'id' => $pointScale,
                        'href' => '/rest-v2/point-award-scales/' . $pointScale,
                        'uniqueObjectId' => '320-' . $pointScale,
                    ]
                ];
                $pointScaleModel = $api->instantiateModel(\Arbor\Resource\ResourceType::POINT_AWARD_SCALE);
                $hydrator->hydrateModel($pointScaleModel, $pointScaleArray[lcfirst(\Arbor\Resource\ResourceType::POINT_AWARD_SCALE)]);

                $stuModel = $api->instantiateModel(\Arbor\Resource\ResourceType::STUDENT);
                $hydrator->hydrateModel($stuModel, $stuArray[lcfirst(\Arbor\Resource\ResourceType::STUDENT)]);
                $output->setStudent($stuModel);

                if (!$teacherID) {
                    $teacherID = '1'; // Default to a system user if no teacher found
                } else {
                    $teacherArray = [
                        'staff' => [
                            'entityType' => 'Staff',
                            'id' => $teacherID,
                            'href' => '/rest-v2/staffs/' . $teacherID,
                            'uniqueObjectId' => '3-' . $teacherID,
                        ]
                    ];
                    $teacherModel = $api->instantiateModel(\Arbor\Resource\ResourceType::STAFF);
                    $hydrator->hydrateModel($teacherModel, $teacherArray[lcfirst(\Arbor\Resource\ResourceType::STAFF)]);
                    $output->setAwardedByStaff($teacherModel);
                }


                $className = Course::where('course_id', $data['class_id'])
                    ->pluck('course_name')
                    ->first();
                $arborEventID = ArborClass::where('name', $className)
                    ->where('start_date_time', '<=', Carbon::parse($data['date'])->setTimezone('Europe/London'))
                    ->orderBy('start_date_time', 'desc')
                    ->pluck('arbor_id')
                    ->first();

                if (!$arborEventID) {
                } else {
                    $classArray = [
                        'event' => [
                            '__typename' => 'Session',
                            'entityType' => 'Session',
                            'id' => $arborEventID,
                            'uniqueObjectId' => '117-' . $arborEventID,
                        ]
                    ];
                    $classModel = $api->instantiateModel(\Arbor\Resource\ResourceType::SESSION);
                    $hydrator->hydrateModel($classModel, $classArray[lcfirst(\Arbor\Resource\ResourceType::SESSION)]);
                    $output->setEvent($classModel);
                }


                $correctDateTime = Carbon::parse(new DateTime($data['date']))->setTimezone('Europe/London');
                $output->setAwardedDatetime($correctDateTime);
                $output->setPoints($data['points']);
                $output->setPointAwardScale($pointScaleModel);
                $output->setPointAwardCategory($pointCategoryModel);
                $output->connect($api);

                $output->save();
                $this->point['sync_status'] = 1;
                $this->point['synced_at'] = now();
                $this->point['arbor_id'] = $output->getResourceId();
                $this->point->save();
            }
        }
    }
}
