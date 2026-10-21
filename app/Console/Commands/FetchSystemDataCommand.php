<?php


namespace App\Console\Commands;

use App\Services\FetchBackgroundDataService;
use Illuminate\Console\Command;
use App\Support\TaskMonitor;
use App\Support\ArborThrottledException;

class FetchSystemDataCommand extends Command
{


    protected $signature = 'writeback:fetchSystemData';
    protected $description = 'Fetch System Data from ePraise to Database';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (TaskMonitor::disabled('writeback:fetchSystemData')) {
            $this->info('writeback:fetchSystemData is disabled via TaskMonitor.');
            return 0;
        }

        TaskMonitor::heartbeat('writeback:fetchSystemData', 'running');

        $fetchService01 = new FetchBackgroundDataService();
        $fetchService02 = new FetchBackgroundDataService();
        $fetchService03 = new FetchBackgroundDataService();
        $fetchService04 = new FetchBackgroundDataService();
        $fetchService05 = new FetchBackgroundDataService();
        $fetchService06 = new FetchBackgroundDataService();
        $fetchService07 = new FetchBackgroundDataService();
        $fetchService08 = new FetchBackgroundDataService();
        $fetchService09 = new FetchBackgroundDataService();
        $fetchService10 = new FetchBackgroundDataService();
        $fetchService11 = new FetchBackgroundDataService();
        $fetchService12 = new FetchBackgroundDataService();
        $fetchService13 = new FetchBackgroundDataService();
        try {
            $fetchService01->fetchAcademicCalendarDates(request());
            $fetchService02->fetchAcademicYears(request());
            $fetchService03->fetchArborBehaviourCategories(request());
            $fetchService04->fetchArborClasses(request(), now()->subDays(10)->toDateString(), now()->addDays(10)->toDateString());
            $fetchService05->fetchArborPointScales(request());
            $fetchService06->fetchArborStaff(request());
            //$fetchService07->fetchArborStudents(request());
            $fetchService08->fetchEpraiseClasses(request());
            $fetchService09->fetchEpraiseStudents(request());
            $fetchService10->fetchEpraiseTeachers(request());
            $fetchService11->updateDemeritCategories(request());
            $fetchService12->updateInterventionCategories(request());
            $fetchService13->updatePointCategories(request());
        } catch (ArborThrottledException $e) {
            TaskMonitor::heartbeat('writeback:fetchSystemData', 'deferred');
            $this->warn('Arbor system-data fetch deferred; retry after at least '.$e->retryAfterSeconds.' seconds or allow the next scheduled run.');
            return self::FAILURE;
        }

        TaskMonitor::heartbeat('writeback:fetchSystemData', 'ok');
    }
}
