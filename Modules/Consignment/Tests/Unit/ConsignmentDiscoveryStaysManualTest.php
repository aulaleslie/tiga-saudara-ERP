<?php

namespace Modules\Consignment\Tests\Unit;

use Tests\TestCase;

/**
 * track-transferred-consignment-provenance 3.7: discovery and provenance binding run only from the existing manual
 * command and back-office action. No listener, observer, job or schedule may invoke them.
 */
class ConsignmentDiscoveryStaysManualTest extends TestCase
{
    private const ALLOWED = [
        'Modules/Consignment/Services/ConsignmentSoldSourceDiscoveryService.php',
        'Modules/Consignment/Services/ConsignmentRemoteSaleResolver.php',
        'Modules/Consignment/Services/ConsignmentBuyoutCapacityService.php',
        'Modules/Consignment/Console/DiscoverConsignmentSoldSourcesCommand.php',
        'Modules/Consignment/Http/Controllers/ConsignmentSoldSourceController.php',
    ];

    /** @test */
    public function only_manual_entry_points_call_discovery_or_binding(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('Modules')));
        $files = array_merge(iterator_to_array($it), iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')))));
        foreach ($files as $file) {
            $path = str_replace(base_path() . '/', '', $file->getPathname());
            if (! str_ends_with($path, '.php') || str_contains($path, '/Tests/') || in_array($path, self::ALLOWED, true)) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (preg_match('/discoverForSetting|bindSerializedSource|syncLotBindings/', $code)) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders);

        $kernel = file_get_contents(base_path('app/Console/Kernel.php'));
        $this->assertStringNotContainsString('consignment:discover', $kernel);
    }
}
