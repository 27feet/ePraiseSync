<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FetchTeachers extends Controller
{
    /**
     * Handle the incoming request.
     */


    public function __invoke(Request $request)
    {
        $queryString = env('EPRAISE_TEACHER_QUERY');
        $queryString = str($queryString)->replace('{School_ID}', env('EPRAISE_SCHOOL_ID'));
        $queryString = str($queryString)->replace('{API_Key}', env('EPRAISE_API_KEY'));

        $teachers = Http::get($queryString)->json();

        foreach ($teachers as $teacher) {
            Teacher::updateOrCreate(
                ['record_id' => $teacher['id']],
                [
                    'email' => $teacher['email'],
                ]
            );
        }
    }
}
