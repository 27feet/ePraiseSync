<?php


namespace App\Console\Commands;

use App\Models\Point;
use App\Models\Demerit;
use App\Jobs\CreatePoint;
use App\Jobs\DeletePoint;
use App\Jobs\UpdatePoint;
use App\Jobs\CreateDemerit;
use App\Jobs\DeleteDemerit;
use App\Jobs\UpdateDemerit;
use App\Models\Intervention;
use Illuminate\Support\Sleep;
use Illuminate\Console\Command;
use App\Jobs\CreateIntervention;
use App\Jobs\DeleteIntervention;
use App\Jobs\UpdateIntervention;

class ProcessWritebackCommand extends Command
{

    protected $signature = 'writeback:processAll';
    protected $description = 'Update Arbor from Database';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $writeCount = 360;

        $deleteinterventionRecords = Intervention::where('sync_status', Intervention::STATUS_REQUIRES_DELETION)->orderByDesc('id',)->limit(72)->get();
        foreach ($deleteinterventionRecords as $deleteintervention) {
            if ($deleteintervention['sync_status'] === Intervention::STATUS_REQUIRES_DELETION) {
                $deleteintervention['sync_status'] = Intervention::STATUS_READY_TO_DELETE;
                $deleteintervention->save();
                Sleep::for(600)->millisecond();
                DeleteIntervention::dispatch($deleteintervention->id)->afterCommit();
                $writeCount -= 5;
            }
        }

        $deletedemeritRecords = Demerit::where('sync_status', Demerit::STATUS_REQUIRES_DELETION)->orderByDesc('id',)->limit(round($writeCount / 5, 0))->get();
        foreach ($deletedemeritRecords as $deletedemerit) {
            if ($deletedemerit['sync_status'] === Demerit::STATUS_REQUIRES_DELETION) {
                $deletedemerit['sync_status'] = Demerit::STATUS_READY_TO_DELETE;
                $deletedemerit->save();
                Sleep::for(600)->millisecond();
                DeleteDemerit::dispatch($deletedemerit->id)->afterCommit();
                $writeCount -= 5;
            }
        }

        $deletepointRecords = Point::where('sync_status', Point::STATUS_REQUIRES_DELETION)->orderByDesc('id',)->limit($writeCount)->get();
        foreach ($deletepointRecords as $deletepoint) {
            if ($deletepoint['sync_status'] === Point::STATUS_REQUIRES_DELETION) {
                $deletepoint['sync_status'] = Point::STATUS_READY_TO_DELETE;
                $deletepoint->save();
                Sleep::for(120)->millisecond();
                DeletePoint::dispatch($deletepoint->id)->afterCommit();
                $writeCount -= 1;
            }
        }

        $updateinterventionRecords = Intervention::where('sync_status', Intervention::STATUS_REQUIRES_UPDATE)->orderByDesc('id',)->limit(round($writeCount / 5, 0))->get();
        foreach ($updateinterventionRecords as $updateintervention) {
            if ($updateintervention['sync_status'] === Intervention::STATUS_REQUIRES_UPDATE) {
                $updateintervention['sync_status'] = Intervention::STATUS_READY_TO_UPDATE;
                $updateintervention->save();
                Sleep::for(600)->millisecond();
                UpdateIntervention::dispatch($updateintervention->id)->afterCommit();
                $writeCount -= 5;
            }
        }
        $updatedemeritRecords = Demerit::where('sync_status', Demerit::STATUS_REQUIRES_UPDATE)->orderByDesc('id',)->limit(round($writeCount / 5, 0))->get();
        foreach ($updatedemeritRecords as $updatedemerit) {
            if ($updatedemerit['sync_status'] === Demerit::STATUS_REQUIRES_UPDATE) {
                $updatedemerit['sync_status'] = Demerit::STATUS_READY_TO_UPDATE;
                $updatedemerit->save();
                Sleep::for(600)->millisecond();
                UpdateDemerit::dispatch($updatedemerit->id)->afterCommit();
                $writeCount -= 5;
            }
        }
        $updatepointRecords = Point::where('sync_status', Point::STATUS_REQUIRES_UPDATE)->orderByDesc('id',)->limit($writeCount)->get();
        foreach ($updatepointRecords as $updatepoint) {
            if ($updatepoint['sync_status'] === Point::STATUS_REQUIRES_UPDATE) {
                $updatepoint['sync_status'] = Point::STATUS_READY_TO_UPDATE;
                $updatepoint->save();
                Sleep::for(120)->millisecond();
                UpdatePoint::dispatch($updatepoint->id)->afterCommit();
                $writeCount -= 1;
            }
        }
        $addinterventionRecords = Intervention::where('sync_status', Intervention::STATUS_REQUIRES_CREATE)->orderByDesc('id',)->limit(round($writeCount / 5, 0))->get();
        foreach ($addinterventionRecords as $addintervention) {
            if ($addintervention['sync_status'] === Intervention::STATUS_REQUIRES_CREATE) {
                $addintervention['sync_status'] = Intervention::STATUS_READY_TO_CREATE;
                $addintervention->save();
                Sleep::for(600)->millisecond();
                CreateIntervention::dispatch($addintervention->id)->afterCommit();
                $writeCount -= 5;
            }
        }
        $adddemeritRecords = Demerit::where('sync_status', Demerit::STATUS_REQUIRES_CREATE)->orderByDesc('id',)->limit(round($writeCount / 5, 0))->get();
        foreach ($adddemeritRecords as $adddemerit) {
            if ($adddemerit['sync_status'] === Demerit::STATUS_REQUIRES_CREATE) {
                $adddemerit['sync_status'] = Demerit::STATUS_READY_TO_CREATE;
                $adddemerit->save();
                Sleep::for(600)->millisecond();
                CreateDemerit::dispatch($adddemerit->id)->afterCommit();
                $writeCount -= 5;
            }
        }
        $addpointRecords = Point::where('sync_status', Point::STATUS_REQUIRES_CREATE)->orderByDesc('id',)->limit($writeCount)->get();
        foreach ($addpointRecords as $addpoint) {
            if ($addpoint['sync_status'] === Point::STATUS_REQUIRES_CREATE) {
                $addpoint['sync_status'] = Point::STATUS_READY_TO_CREATE;
                $addpoint->save();
                Sleep::for(120)->millisecond();
                CreatePoint::dispatch($addpoint->id)->afterCommit();
                $writeCount -= 1;
            }
        }
    }
}
