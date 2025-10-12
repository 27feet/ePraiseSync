<?php

namespace App\Console\Commands;

use App\Models\Demerit;
use App\Jobs\DeleteDemerit;
use Illuminate\Support\Sleep;
use Illuminate\Console\Command;

class RemoveDemeritsCommand extends Command
{


    protected $signature = 'writeback:removedemerits';
    protected $description = 'Writeback Demerits from Database to Arbor';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $demeritRecords = Demerit::where('sync_status', -1)->orderByDesc('id',)->limit(66)->get();
        foreach ($demeritRecords as $demerit) {
            $demerit['sync_status'] = -9;
            $demerit->save();
            Sleep::for(600)->millisecond();
            DeleteDemerit::dispatch($demerit->id);
        }
    }
}
