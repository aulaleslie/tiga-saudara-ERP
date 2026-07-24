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
        $query = \Modules\Pos\Entities\PosTemporaryPaymentImage::where(function ($query) use ($now) {
            $query->where('expires_at', '<', $now)
                  ->orWhereNotNull('consumed_at');
        });

        $count = 0;

        $query->chunkById(100, function ($images) use (&$count) {
            foreach ($images as $image) {
                try {
                    $fileDeleted = true;
                    if (\Illuminate\Support\Facades\Storage::exists($image->path)) {
                        $fileDeleted = \Illuminate\Support\Facades\Storage::delete($image->path);
                        if (!$fileDeleted) {
                            \Illuminate\Support\Facades\Log::warning('PosTemporaryPaymentImage file deletion failed', [
                                'image_id' => $image->id,
                                'path' => $image->path
                            ]);
                        }
                    }
                    if ($fileDeleted) {
                        $image->delete();
                        $count++;
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('PosTemporaryPaymentImage cleanup failed for image ID ' . $image->id, [
                        'exception' => $e->getMessage()
                    ]);
                }
            }
        });

        $this->info("Cleaned up {$count} temporary POS payment image(s).");
    }
}
