<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Product\Services\ProductTaxonomySchemaFinalizer;
use Throwable;

class FinalizeProductTaxonomyCommand extends Command
{
    protected $signature = 'product:finalize-taxonomy
        {--check : Run preflight checks only without modifying schema}
        {--connection= : Optional database connection name}';

    protected $description = 'Preflight and explicitly finalize global product taxonomy unique constraints and drop legacy setting ownership.';

    public function handle(ProductTaxonomySchemaFinalizer $finalizer): int
    {
        $connection = $this->option('connection') ?: null;
        $checkOnly = (bool) $this->option('check');

        $blockers = $finalizer->preflight($connection);
        if ($blockers !== []) {
            $this->error('Product taxonomy finalization preflight failed:');
            foreach ($blockers as $blocker) {
                $this->line('  - ' . $blocker);
            }
            return self::FAILURE;
        }

        if ($checkOnly) {
            $this->info('Product taxonomy finalization preflight passed. Ready to run without --check.');
            return self::SUCCESS;
        }

        try {
            $finalizer->finalize($connection);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Global product taxonomy constraints finalized and legacy setting ownership removed.');

        return self::SUCCESS;
    }
}
