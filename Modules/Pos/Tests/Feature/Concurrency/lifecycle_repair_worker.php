<?php

/**
 * Worker for the MySQL lifecycle-vs-repair contention test. Calls the REAL production services.
 *   repair    <actorId> <settingId> <transactionId> <fingerprint> <barrier>
 *   lifecycle <actorId> <settingId> <posReturnId> <barrier>
 * Prints RESULT:<outcome> (applied:<classification> | lifecycle-ok | refused:<class>:<message>).
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'mysql_test', 'session.driver' => 'array', 'cache.default' => 'array']);
if (! str_ends_with((string) config('database.connections.mysql_test.database'), '_test')) {
    fwrite(STDERR, "refusing: database must end in _test\n");
    exit(2);
}
session()->start();

$mode = $argv[1] ?? '';
$actorId = (int) ($argv[2] ?? 0);
$settingId = (int) ($argv[3] ?? 0);
session(['setting_id' => $settingId]);
Illuminate\Support\Facades\Auth::loginUsingId($actorId);

$barrier = (string) end($argv);
$start = time();
while ($barrier !== '' && ! file_exists($barrier)) {
    if (time() - $start > 20) {
        fwrite(STDERR, "barrier timeout\n");
        exit(1);
    }
    usleep(2000);
}

// No harness retries: the production services own their locking/retry behavior; any exception is reported verbatim.
try {
    if ($mode === 'repair') {
        $plan = app(Modules\Pos\Services\PosReplacementAccountingRepairService::class)
            ->apply((int) $argv[4], $settingId, $actorId, 'concurrency verification', (string) $argv[5]);
        echo 'RESULT:applied:' . ($plan['classification'] ?? '?') . "\n";
    } elseif ($mode === 'lifecycle') {
        app(Modules\Pos\Services\PosReturnLifecycleService::class)->executeApprovalFromPreview((int) $argv[4]);
        echo "RESULT:lifecycle-ok\n";
    }
} catch (Throwable $e) {
    echo 'RESULT:refused:' . get_class($e) . ':' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n";
}
exit(0);
