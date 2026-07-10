<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class PreflightBarcodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:preflight-barcodes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preflight check for barcode uniqueness before registry backfill.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Running barcode preflight check...');
        $service = new \Modules\Product\Services\BarcodePreflightService();
        $results = $service->detectDuplicates();

        if (empty($results['conflicts']) && empty($results['invalid'])) {
            $this->info('No historical duplicates or invalid barcodes found. Safe to proceed with backfill.');
            return 0;
        }

        if (!empty($results['invalid'])) {
            $this->error('Invalid barcodes found! These must be fixed before migration:');
            foreach ($results['invalid'] as $invalid) {
                $type = $invalid['type'];
                $id = $invalid['id'];
                $barcode = $invalid['barcode'];
                $this->line("  - {$type} #{$id} with invalid value '{$barcode}'");
            }
            $this->line('');
        }

        if (!empty($results['conflicts'])) {
            $this->error('Duplicate barcodes found! Please resolve them before migration:');
            foreach ($results['conflicts'] as $key => $owners) {
                $this->warn("Barcode key '{$key}':");
                foreach ($owners as $owner) {
                    $type = $owner['type'];
                    $id = $owner['id'];
                    $barcode = $owner['barcode'];
                    $this->line("  - {$type} #{$id} with value '{$barcode}'");
                }
                $this->line('');
            }
        }

        return 1;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['example', InputArgument::REQUIRED, 'An example argument.'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
        ];
    }
}
