<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class FetchPointsJob implements ShouldQueue
{
    use Queueable;

    protected User $user;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(Filesystem $file): void
    {

        $file->put(public_path('epraise.txt'), 'Fetched points for: ' . $this->user->name);
        logger('Spoke to ePraise and fetched points for: ' . $this->user->name);
    }
}
