<?php

namespace App\Console\Commands;

use App\Jobs\RunSearch;
use App\Models\Search;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:discover')]
#[Description('Start the weekly scan of each user\'s home area once their landing day has begun where they live')]
class DiscoverEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        User::query()->whereNotNull('home_location')->each(function (User $user): void {
            if ($user->isScanning()) {
                $this->components->warn("Skipped {$user->email}: a scan is already running.");

                return;
            }

            if (! $user->scanDue()) {
                return;
            }

            RunSearch::dispatch(Search::discoveryFor($user)->id);

            $this->components->info("Scanning {$user->home_location} for {$user->email}.");
        });
    }
}
