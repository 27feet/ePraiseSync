<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Term;
use App\Models\Point;
use App\Models\Course;
use App\Models\Demerit;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\AcademicYear;
use App\Models\Intervention;
use App\Models\ArborPointScale;
use App\Models\ArborPointCategory;
use App\Models\AcademicCalendarDate;
use App\Models\EpraisePointCategory;
use App\Models\ArborBehaviourCategory;
use App\Models\EpraiseDemeritCategory;
use App\Models\EpraiseInterventionCategory;
use App\Support\EpraiseUrl;
use App\Support\ArborRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ANP\LaravelArbor\Facades\ArborGraphQL;

class FetchBackgroundDataService
{
    public function fetchAcademicCalendarDates(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;
        $fromDate = Carbon::now()->subYears(2)->toDateString();

        $query = <<<GQL
query {
  AcademicCalendarDate(page_size: 2000, page_num: 0, startDate_after: "$fromDate") {
    id
    startDate
    calendarYear
    calendarMonth
    calendarWeek
    calendarDay
    academicYear { id }
    term { id }
    dayOfTerm
    dayOfAcademicYear
    isGoodSchoolDay
  }
}
GQL;

        while ($checkMore) {
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            ArborRateLimiter::consume();
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchAcademicCalendarDates: bad GraphQL response');
                break;
            }

            $dates = $response->getData()['AcademicCalendarDate'] ?? [];
            foreach ($dates as $row) {
                AcademicCalendarDate::updateOrCreate(
                    ['arbor_id' => $row['id']],
                    [
                        'academic_year_id'       => $row['academicYear']['id'] ?? null,
                        'term_id'                => $row['term']['id'] ?? null,
                        'date'                   => Carbon::parse($row['startDate'])->timezone('Europe/London'),
                        'calendar_year'          => $row['calendarYear'],
                        'calendar_month'         => $row['calendarMonth'],
                        'calendar_day'           => $row['calendarDay'],
                        'calendar_week'          => $row['calendarWeek'],
                        'day_of_term'            => $row['dayOfTerm'],
                        'day_of_academic_year'   => $row['dayOfAcademicYear'],
                        'is_school_day'          => $row['isGoodSchoolDay'],
                    ]
                );
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($dates) < 2000) $checkMore = false;
        }
    }

    public function fetchAcademicYears(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;
        $fromDate = Carbon::now()->subYears(2)->toDateString();

        $query = <<<GQL
query {
  AcademicYear(page_size: 2, page_num: 0, startDate_after: "$fromDate") {
    id
    code
    academicYearName
    startDate
    endDate
    terms {
      id
      termName
      startDate
      endDate
      firstSchoolDay { id startDate }
      lastSchoolDay  { id startDate }
    }
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchAcademicYears: bad GraphQL response');
                break;
            }

            $years = $response->getData()['AcademicYear'] ?? [];
            foreach ($years as $year) {
                AcademicYear::updateOrCreate(
                    ['arbor_id' => $year['id']],
                    [
                        'academic_year_name' => $year['academicYearName'],
                        'code'               => $year['code'],
                        'start_date'         => Carbon::parse($year['startDate'])->timezone('Europe/London'),
                        'end_date'           => Carbon::parse($year['endDate'])->timezone('Europe/London'),
                    ]
                );

                foreach ($year['terms'] as $term) {
                    Term::updateOrCreate(
                        ['arbor_id' => $term['id']],
                        [
                            'term_name'             => $term['termName'],
                            'academic_year_id'      => $year['id'],
                            'start_date'            => Carbon::parse($term['startDate'])->timezone('Europe/London'),
                            'end_date'              => Carbon::parse($term['endDate'])->timezone('Europe/London'),
                            'first_school_day_id'   => $term['firstSchoolDay']['id']   ?? null,
                            'last_school_day_id'    => $term['lastSchoolDay']['id']    ?? null,
                            'first_school_day_date' => isset($term['firstSchoolDay']['startDate'])
                                ? Carbon::parse($term['firstSchoolDay']['startDate'])->timezone('Europe/London') : null,
                            'last_school_day_date'  => isset($term['lastSchoolDay']['startDate'])
                                ? Carbon::parse($term['lastSchoolDay']['startDate'])->timezone('Europe/London') : null,
                        ]
                    );
                }
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($years) < 2) $checkMore = false;
        }
    }

    public function fetchArborBehaviourCategories(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;

        $query = <<<GQL
query {
  Behaviour(page_size: 2000, page_num: 0) {
    id
    displayName
    severity
    active
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchArborBehaviourCategories: bad GraphQL response');
                break;
            }

            $rows = $response->getData()['Behaviour'] ?? [];
            foreach ($rows as $row) {
                ArborBehaviourCategory::updateOrCreate(
                    ['arbor_id' => $row['id']],
                    [
                        'arbor_display_name' => $row['displayName'],
                        'severity'           => $row['severity'],
                        'active'             => $row['active'],
                    ]
                );
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($rows) < 2000) $checkMore = false;
        }
    }

    public function fetchArborClasses(Request $request, string $fromDate, string $toDate): void
    {
        $pageNum  = 0;
        $checkMore = true;

        // NOTE: store by sessionId (and use it as arbor_id)
        $query = <<<GQL
query {
  Session(page_size: 2000, page_num: 0, startDatetime_after: "$fromDate", startDatetime_before_or_equal: "$toDate") {
    startDatetime
    endDatetime
    sessionId
    academicUnit { academicUnitName }
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchArborClasses: bad GraphQL response');
                break;
            }

            $classes = $response->getData()['Session'] ?? [];
            foreach ($classes as $class) {
                if (!($class['academicUnit'] ?? null)) {
                    continue;
                }
                ArborClass::updateOrCreate(
                    ['arbor_id' => $class['sessionId']],
                    [
                        'name'            => $class['academicUnit']['academicUnitName'],
                        'start_date_time' => Carbon::parse($class['startDatetime'])->timezone('Europe/London'),
                        'end_date_time'   => Carbon::parse($class['endDatetime'])->timezone('Europe/London'),
                    ]
                );
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($classes) < 2000) $checkMore = false;
        }
    }

    public function fetchArborPointScales(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;

        $query = <<<GQL
query {
  PointAwardScale(page_size: 2000, page_num: 0) {
    id
    displayName
    pointAwardScaleName
    categories { id displayName }
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchArborPointScales: bad GraphQL response');
                break;
            }

            $scales = $response->getData()['PointAwardScale'] ?? [];
            foreach ($scales as $scale) {
                ArborPointScale::updateOrCreate(
                    ['arbor_id' => $scale['id']],
                    [
                        'arbor_name'         => $scale['pointAwardScaleName'],
                        'arbor_display_name' => $scale['displayName'],
                    ]
                );

                foreach ($scale['categories'] as $category) {
                    ArborPointCategory::updateOrCreate(
                        ['arbor_id' => $category['id']],
                        [
                            'arbor_display_name'   => $category['displayName'],
                            'arbor_point_scale_id' => $scale['id'],
                        ]
                    );
                }
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($scales) < 2000) $checkMore = false;
        }
    }

    public function fetchArborStaff(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;

        $query = <<<GQL
query {
  Staff(page_size: 2000, page_num: 0) {
    id
    primaryEmailAddress { emailAddress }
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchArborStaff: bad GraphQL response');
                break;
            }

            $staff = $response->getData()['Staff'] ?? [];
            foreach ($staff as $person) {
                $email = $person['primaryEmailAddress']['emailAddress'] ?? null;
                if (!$email) continue;

                ArborStaff::updateOrCreate(
                    ['arbor_id' => $person['id']],
                    ['email' => mb_strtolower($email)]
                );
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($staff) < 2000) $checkMore = false;
        }
    }

    public function fetchArborStudents(Request $request): void
    {
        $pageNum  = 0;
        $checkMore = true;

        $query = <<<GQL
query {
  Student(page_size: 2000, page_num: 0) {
    id
    displayName
    academicLevel {
      displayName
      tutors { primaryEmailAddress { emailAddress } }
    }
    registrationForm {
      displayName
      tutors { primaryEmailAddress { emailAddress } }
    }
  }
}
GQL;

        while ($checkMore) {
            $response = ArborGraphQL::query($query);

            if (!method_exists($response, 'getData') || !is_array($response->getData())) {
                Log::warning('fetchArborStudents: bad GraphQL response');
                break;
            }

            $students = $response->getData()['Student'] ?? [];
            foreach ($students as $s) {
                $record = Student::where('arbor_id', $s['id'])->first();
                if (!$record) continue;

                $yearLeaderEmails = [];
                if (!empty($s['academicLevel']['tutors'])) {
                    foreach ($s['academicLevel']['tutors'] as $t) {
                        $e = $t['primaryEmailAddress']['emailAddress'] ?? null;
                        if ($e) $yearLeaderEmails[] = mb_strtolower($e);
                    }
                }

                $tutorEmails = [];
                if (!empty($s['registrationForm']['tutors'])) {
                    foreach ($s['registrationForm']['tutors'] as $t) {
                        $e = $t['primaryEmailAddress']['emailAddress'] ?? null;
                        if ($e) $tutorEmails[] = mb_strtolower($e);
                    }
                }

                $record->update([
                    'year_group'         => $s['academicLevel']['displayName'] ?? null,
                    'year_leader_emails' => $yearLeaderEmails,
                    'tutor_emails'       => $tutorEmails,
                    'name'               => $s['displayName'] ?? null,
                ]);
            }

            $query = str_replace('page_num: ' . $pageNum, 'page_num: ' . ($pageNum + 1), $query);
            $pageNum++;
            if (count($students) < 2000) $checkMore = false;
        }
    }

    public function fetchEpraiseClasses(Request $request): void
    {
        $query = EpraiseUrl::endpoint('classes', now()->toDateString());
        $resp  = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$resp->successful()) {
            Log::warning('fetchEpraiseClasses: request failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return;
        }

        $classes = $resp->json();
        if (!is_array($classes)) {
            Log::warning('fetchEpraiseClasses: unexpected payload', ['payload' => $classes]);
            return;
        }

        foreach ($classes as $class) {
            if (!isset($class['id'], $class['name'])) continue;

            Course::updateOrCreate(
                ['course_id' => $class['id']],
                ['course_name' => $class['name']]
            );
        }
    }

    public function fetchEpraiseStudents(Request $request): void
    {
        $query = EpraiseUrl::endpoint('students', now()->toDateString());
        $resp  = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$resp->successful()) {
            Log::warning('fetchEpraiseStudents: request failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return;
        }

        $students = $resp->json();
        if (!is_array($students)) {
            Log::warning('fetchEpraiseStudents: unexpected payload', ['payload' => $students]);
            return;
        }

        foreach ($students as $s) {
            if (! isset($s['id'])) continue;

            $recordId = (int) $s['id'];
            // You’re mapping UPN to arbor_id; keep if intentional
            $arborId = $s['upn'] ?? null;

            if ($arborId !== null && $arborId !== '') {
                $student = Student::where('arbor_id', $arborId)->first();

                if ($student) {
                    $recordIdOwner = Student::where('record_id', $recordId)
                        ->whereKeyNot($student->getKey())
                        ->first();

                    if ($recordIdOwner) {
                        Log::error('fetchEpraiseStudents: incoming record_id is already assigned to a different student', [
                            'incoming_record_id' => $recordId,
                            'incoming_arbor_id' => $arborId,
                            'existing_student_id' => $student->getKey(),
                            'existing_student_record_id' => $student->record_id,
                            'existing_student_arbor_id' => $student->arbor_id,
                            'record_id_owner_student_id' => $recordIdOwner->getKey(),
                            'record_id_owner_record_id' => $recordIdOwner->record_id,
                            'record_id_owner_arbor_id' => $recordIdOwner->arbor_id,
                        ]);

                        continue;
                    }

                    $student->update(['record_id' => $recordId]);

                    continue;
                }

                $recordIdOwner = Student::where('record_id', $recordId)->first();
                if ($recordIdOwner) {
                    Log::error('fetchEpraiseStudents: incoming arbor_id is new but record_id is already assigned to a different student', [
                        'incoming_record_id' => $recordId,
                        'incoming_arbor_id' => $arborId,
                        'record_id_owner_student_id' => $recordIdOwner->getKey(),
                        'record_id_owner_record_id' => $recordIdOwner->record_id,
                        'record_id_owner_arbor_id' => $recordIdOwner->arbor_id,
                    ]);

                    continue;
                }
            }

            Student::updateOrCreate(
                ['record_id' => $recordId],
                ['arbor_id' => $arborId]
            );
        }
    }

    public function fetchEpraiseTeachers(Request $request): void
    {
        $query = EpraiseUrl::endpoint('teachers', now()->toDateString());
        $resp  = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$resp->successful()) {
            Log::warning('fetchEpraiseTeachers: request failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return;
        }

        $teachers = $resp->json();
        if (!is_array($teachers)) {
            Log::warning('fetchEpraiseTeachers: unexpected payload', ['payload' => $teachers]);
            return;
        }

        foreach ($teachers as $t) {
            if (!isset($t['id'])) continue;

            $email = isset($t['email']) ? mb_strtolower($t['email']) : null;

            Teacher::updateOrCreate(
                ['record_id' => $t['id']],
                ['email' => $email]
            );
        }
    }

    public function updateDemeritCategories(Request $request): void
    {
        $names = Demerit::select('category')->distinct()->pluck('category')->toArray();
        foreach ($names as $name) {
            EpraiseDemeritCategory::updateOrCreate(['epraise_name' => $name]);
        }
    }

    public function updateInterventionCategories(Request $request): void
    {
        $names = Intervention::select('intervention_type')->distinct()->pluck('intervention_type')->toArray();
        foreach ($names as $name) {
            EpraiseInterventionCategory::updateOrCreate(['epraise_name' => $name]);
        }
    }

    public function updatePointCategories(Request $request): void
    {
        $names = Point::select('category')->distinct()->pluck('category')->toArray();
        foreach ($names as $name) {
            EpraisePointCategory::updateOrCreate(['epraise_name' => $name]);
        }
    }
}
