<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FetchStudents extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $queryString = env('EPRAISE_STUDENT_QUERY');
        $queryString = str($queryString)->replace('{School_ID}', env('EPRAISE_SCHOOL_ID'));
        $queryString = str($queryString)->replace('{API_Key}', env('EPRAISE_API_KEY'));

        $students = Http::get($queryString)->json();

        foreach ($students as $student) {
            Student::updateOrCreate(
                ['record_id' => $student['id']],
                [
                    'arbor_id' => $student['upn'],
                ]
            );
        }
    }
}
