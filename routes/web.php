<?php

use App\Jobs\CreateDemerit;
use App\Jobs\CreateIntervention;
use App\Http\Controllers\KillPoints;
use App\Http\Controllers\FetchPoints;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FetchClasses;
use App\Http\Controllers\FetchDemerits;
use App\Http\Controllers\FetchStudents;
use App\Http\Controllers\FetchTeachers;
use App\Http\Controllers\FetchArborStaff;
use App\Http\Controllers\FetchArborClasses;
use App\Http\Controllers\FetchAcademicYears;
use App\Http\Controllers\FetchArborStudents;
use App\Http\Controllers\FetchInterventions;
use App\Http\Controllers\FetchAcademicCalendarDates;
use App\Http\Controllers\UpdateEpraisePointCategories;
use App\Http\Controllers\FetchArborBehaviourCategories;
use App\Http\Controllers\UpdateEpraiseDemeritCategories;
use App\Http\Controllers\FetchArborPointScalesCategories;
use App\Http\Controllers\UpdateEpraiseInterventionCategories;


// Route::get('/test', TestPoints::class)->name('testpoints');


Route::get('/demerithistory', FetchDemerits::class)
    ->name('demerits')
    ->defaults('fromDate', now()->subDays(10)->toDateString())
    ->defaults('includeAmendments', true);

Route::get('/pointhistory', FetchPoints::class)
    ->name('points')
    ->defaults('fromDate', now()->subDays(10)->toDateString())
    ->defaults('includeAmendments', false);


Route::get('/interventions', FetchInterventions::class)
    ->name('interventions')
    ->defaults('fromDate', now()->subDays(10)->toDateString())
    ->defaults('includeAmendments', false);


Route::get('/teachers', FetchTeachers::class)
    ->name('teachers');


Route::get('/classes', FetchClasses::class)
    ->name('classes');

Route::get('/fetchYears', FetchAcademicYears::class)
    ->name('academicYears');

Route::get('/fetchStudents', FetchArborStudents::class)
    ->name('arborStudents');

Route::get('/fetchDates', FetchAcademicCalendarDates::class)
    ->name('academicCalendarDates');

Route::get('/arborstaff', FetchArborStaff::class)
    ->name('arborstaff');

Route::get('/arborbehaviour', FetchArborBehaviourCategories::class)
    ->name('arborbehaviour');

Route::get('/students', FetchStudents::class)
    ->name('students');

Route::get('/arborclasses', FetchArborClasses::class)
    ->name('arborclasses')
    ->defaults('fromDate', now()->subDays(10)->toDateString())
    ->defaults('toDate', now()->addDays(10)->toDateString());


Route::get('/wp', function () {

    //    CreatePoint::dispatch(7907);

    //  return 'Completed';
});





//Route::get('/flush', FlushRedis::class)
//    ->name('flushRedis');

Route::get('/fix', function () {

    //FixDemerit::dispatch(17020);

    return 'Completed';
});

Route::get('/dd', function () {

    //    DeleteDemerit::dispatch(1);

    return 'Completed';
});


Route::get('/wd', function () {

    CreateDemerit::dispatch(30872);

    return 'Demerit Completed';
});

Route::get('/wi', function () {

    CreateIntervention::dispatch(30872);

    return 'Intervention Completed';
});

Route::get('/ud', function () {

    //    UpdateDemerit::dispatch(2);

    return 'Completed';
});

Route::get('/up', function () {

    //   UpdatePoint::dispatch(19888);

    return 'Completed';
});

Route::get('/dp', function () {

    //   DeletePoint::dispatch(1);

    return 'Completed';
});

Route::get('/arborScales', FetchArborPointScalesCategories::class)
    ->name('arborScales');


//Route::get('/consentTest', KillConsentTest::class)
//   ->name('consentTest');


Route::get('/epp', UpdateEpraisePointCategories::class)
    ->name('epraisePointCategories');

Route::get('/epd', UpdateEpraiseDemeritCategories::class)
    ->name('epraiseDemeritCategories');

Route::get('/epi', UpdateEpraiseInterventionCategories::class)
    ->name('epraiseInterventionCategories');

Route::get('/full', function () {
    (new FetchDemerits)
        ->__invoke(new \Illuminate\Http\Request(), now()->subDays(28)->toDateString(), true);
    (new FetchPoints)
        ->__invoke(new \Illuminate\Http\Request(), now()->subDays(28)->toDateString(), true);
    (new FetchInterventions)
        ->__invoke(new \Illuminate\Http\Request(), now()->subDays(28)->toDateString(), true);
    (new FetchTeachers)
        ->__invoke(new \Illuminate\Http\Request());
    (new FetchArborStaff)
        ->__invoke(new \Illuminate\Http\Request());
    (new FetchArborBehaviourCategories)
        ->__invoke(new \Illuminate\Http\Request());
    (new FetchStudents)
        ->__invoke(new \Illuminate\Http\Request());
    (new FetchArborClasses)
        ->__invoke(new \Illuminate\Http\Request(), now()->subDays(300)->toDateString(), now()->subDays(0)->toDateString());
    (new FetchArborPointScalesCategories)
        ->__invoke(new \Illuminate\Http\Request());
    (new UpdateEpraisePointCategories)
        ->__invoke(new \Illuminate\Http\Request());
    (new UpdateEpraiseDemeritCategories)
        ->__invoke(new \Illuminate\Http\Request());
    (new UpdateEpraiseInterventionCategories)
        ->__invoke(new \Illuminate\Http\Request());
});
