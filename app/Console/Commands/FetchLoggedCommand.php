<?php


namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UpdateLoggedItemsService;

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
        $fetchService1 = new UpdateLoggedItemsService();
        $fetchService2 = new UpdateLoggedItemsService();
        $fetchService3 = new UpdateLoggedItemsService();
        $fetchService1->fetchPoints(request(), now()->subDays(10)->toDateString(), true);
        $fetchService2->fetchDemerits(request(), now()->subDays(10)->toDateString(), true);
        $fetchService3->fetchInterventions(request(), now()->subDays(10)->toDateString(), true);
    }
}
