<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use Carbon\Carbon;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune {--days=30 : The number of days to retain resolved/read notifications}';
    protected $description = 'Prune old notifications';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        // We probably only want to delete ones that are resolved or read, but the spec says "older than a provided age".
        $count = Notification::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$count} notifications older than {$days} days.");
    }
}
