<?php

use App\Jobs\CreatePoint;
use App\Jobs\DeletePoint;
use App\Jobs\UpdatePoint;
use App\Jobs\WriteDemerit;
use App\Jobs\CreateDemerit;
use App\Jobs\DeleteDemerit;
use App\Jobs\UpdateDemerit;
use App\Jobs\CreateIntervention;
use App\Http\Controllers\TestPoints;
use App\Http\Controllers\FetchPoints;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FetchDemerits;
use App\Http\Controllers\FetchStudents;
use App\Http\Controllers\FetchTeachers;
use App\Http\Controllers\FetchArborStaff;
use App\Http\Controllers\FetchArborClasses;
use App\Http\Controllers\FetchInterventions;
use App\Http\Controllers\UpdateEpraisePointCategories;
use App\Http\Controllers\FetchArborBehaviourCategories;
use App\Http\Controllers\UpdateEpraiseDemeritCategories;
use App\Http\Controllers\FetchArborPointScalesCategories;
use App\Http\Controllers\UpdateEpraiseInterventionCategories;

Route::get('/wd', function () {

    CreateDemerit::dispatch(1);

    return 'Completed';
});

Route::get('/test', TestPoints::class)->name('testpoints');


Route::get('/demerithistory', FetchDemerits::class)
    ->name('demerits')
    ->defaults('fromDate', now()->subDays(28)->toDateString())
    ->defaults('includeAmendments', true);

Route::get('/pointhistory', FetchPoints::class)
    ->name('points')
    ->defaults('fromDate', now()->subDays(28)->toDateString())
    ->defaults('includeAmendments', true);

Route::get('/teachers', FetchTeachers::class)
    ->name('teachers');

Route::get('/arborstaff', FetchArborStaff::class)
    ->name('arborstaff');

Route::get('/arborbehaviour', FetchArborBehaviourCategories::class)
    ->name('arborbehaviour');

Route::get('/students', FetchStudents::class)
    ->name('students');

Route::get('/arborclasses', FetchArborClasses::class)
    ->name('arborclasses')
    ->defaults('fromDate', now()->subDays(300)->toDateString())
    ->defaults('toDate', now()->subDays(0)->toDateString());

Route::get('/wp', function () {

    CreatePoint::dispatch(19888);

    return 'Completed';
});

Route::get('/wd', function () {

    CreateDemerit::dispatch(2);

    return 'Demerit Completed';
});


Route::get('/dd', function () {

    DeleteDemerit::dispatch(1);

    return 'Completed';
});


Route::get('/wi', function () {

    CreateIntervention::dispatch(38);

    return 'Intervention Completed';
});


Route::get('/ud', function () {

    UpdateDemerit::dispatch(2);

    return 'Completed';
});

Route::get('/up', function () {

    UpdatePoint::dispatch(19888);

    return 'Completed';
});

Route::get('/dp', function () {

    DeletePoint::dispatch(1);

    return 'Completed';
});

Route::get('/arborScales', FetchArborPointScalesCategories::class)
    ->name('arborScales');


Route::get('/epp', UpdateEpraisePointCategories::class)
    ->name('epraisePointCategories');

Route::get('/epd', UpdateEpraiseDemeritCategories::class)
    ->name('epraiseDemeritCategories');

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
