<?php


namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UpdateLoggedItemsService;
use App\Support\TaskMonitor;

class FetchLoggedCommand extends Command
{


    protected $signature = 'writeback:fetchloggeditems';
    protected $description = 'Fetch Logged Items from ePraise and Arbor';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (TaskMonitor::disabled('writeback:fetchloggeditems')) {
            $this->info('writeback:fetchloggeditems is disabled via TaskMonitor.');
            return 0;
        }

        TaskMonitor::heartbeat('writeback:fetchloggeditems', 'running');

        $fetchService1 = new UpdateLoggedItemsService();
        $fetchService2 = new UpdateLoggedItemsService();
        $fetchService3 = new UpdateLoggedItemsService();
        $fetchService1->fetchPoints(request(), now()->subDays(24)->toDateString(), true);
        $fetchService2->fetchDemerits(request(), now()->subDays(24)->toDateString(), true);
        $fetchService3->fetchInterventions(request(), now()->subDays(14)->toDateString(), true);

        TaskMonitor::heartbeat('writeback:fetchloggeditems', 'ok');
    }
}
