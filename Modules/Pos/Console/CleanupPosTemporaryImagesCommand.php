<?php

namespace Modules\Pos\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class CleanupPosTemporaryImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'pos:cleanup-temporary-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup unconsumed temporary POS payment images older than 24 hours';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting POS temporary payment images cleanup...');

        $now = \Carbon\Carbon::now();

        // Expired images (older than 24 hours) or consumed images
        $images = \Modules\Pos\Entities\PosTemporaryPaymentImage::where(function ($query) use ($now) {
            $query->where('expires_at', '<', $now)
                  ->orWhereNotNull('consumed_at');
        })->get();

        $count = 0;
        foreach ($images as $image) {
            if (\Illuminate\Support\Facades\Storage::exists($image->path)) {
                \Illuminate\Support\Facades\Storage::delete($image->path);
            }
            $image->delete();
            $count++;
        }

        $this->info("Cleaned up {$count} temporary POS payment image(s).");
    }
}
