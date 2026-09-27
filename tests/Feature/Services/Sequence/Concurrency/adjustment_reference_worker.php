<?php

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config(['database.default' => 'mysql_test']);

use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Services\AdjustmentReferenceService;

$prefix = $argv[1] ?? 'BRK';
$locationId = (int) ($argv[2] ?? 1);
$year = (int) ($argv[3] ?? 2026);
$month = (int) ($argv[4] ?? 9);
$iterations = (int) ($argv[5] ?? 10);
$barrierFile = $argv[6] ?? '';

// Wait for start barrier so every worker process begins its allocation
// attempts as close to simultaneously as possible.
if ($barrierFile !== '') {
    $timeout = 10;
    $start = time();
    while (!file_exists($barrierFile)) {
        if (time() - $start > $timeout) {
            fwrite(STDERR, "Worker timeout waiting for barrier\n");
            exit(1);
        }
        usleep(5000);
    }
}

for ($i = 0; $i < $iterations; $i++) {
    try {
        $reference = DB::connection('mysql_test')->transaction(function () use ($prefix, $locationId, $year, $month) {
            $reference = AdjustmentReferenceService::allocate($prefix, \Carbon\Carbon::create($year, $month, 1));

            DB::connection('mysql_test')->table('adjustments')->insert([
                'date' => sprintf('%04d-%02d-01', $year, $month),
                'reference' => $reference,
                'type' => $prefix === AdjustmentReferenceService::PREFIX_BREAKAGE ? 'breakage' : 'normal',
                'status' => 'pending',
                'location_id' => $locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $reference;
        }, 3);

        echo "REF:" . $reference . "\n";
        flush();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Worker exception at iteration {$i}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}

exit(0);
