<?php

namespace Modules\Consignment\Console;

use Illuminate\Console\Command;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Setting\Entities\Setting;

class DiscoverConsignmentSoldSourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'consignment:discover-sold-sources {--setting= : Specific Setting ID to discover} {--preview : Preview only without persisting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover and persist eligible consignment sold sources from approved sales/POS dispatches';

    /**
     * Execute the console command.
     */
    public function handle(ConsignmentSoldSourceDiscoveryService $discoveryService): int
    {
        $settingId = $this->option('setting');
        $previewOnly = (bool) $this->option('preview');

        if ($settingId) {
            $settings = Setting::where('id', $settingId)->get();
        } else {
            $settings = Setting::all();
        }

        if ($settings->isEmpty()) {
            $this->warn('No active settings found for discovery.');
            return 0;
        }

        foreach ($settings as $setting) {
            $this->info("Processing discovery for Setting ID {$setting->id} ({$setting->company_name})...");
            $result = $discoveryService->discoverForSetting($setting->id, $previewOnly);

            $this->table(
                ['Created', 'Existing', 'Excluded', 'Blocked'],
                [[$result['created'], $result['existing'], $result['excluded'], $result['blocked']]]
            );
        }

        $this->info('Discovery process completed.');
        return 0;
    }
}
