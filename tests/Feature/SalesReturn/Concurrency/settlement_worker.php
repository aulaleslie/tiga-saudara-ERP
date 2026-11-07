<?php

/**
 * Worker for the MySQL settlement concurrency tests (task 6.6).
 *
 * Invokes the REAL production entry points, no reimplemented locking/financial logic:
 *   approve <userId> <settlementId> <barrier>          SalesReturnSettlementController::approveItemSettlement
 *   pay     <userId> <customerId> <saleId> <amount> <paymentMethodId> <barrier>   GlobalSalePaymentService::storeMultiPayment
 *   release <userId> <saleReturnId> <custodyId> <quantity> <barrier>      StandardReturnCustodyService::release
 * Prints one RESULT:<outcome> line.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'mysql_test', 'session.driver' => 'array', 'cache.default' => 'array']);
session()->start();
session(['setting_id' => 1]);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

Gate::before(fn () => true);
$app->instance(App\Services\Notification\DocumentNotificationService::class, Mockery::mock(App\Services\Notification\DocumentNotificationService::class)->shouldIgnoreMissing());

$mode = $argv[1] ?? '';
$userId = (int) ($argv[2] ?? 0);
Auth::loginUsingId($userId);

$barrier = (string) end($argv);
$start = time();
while ($barrier !== '' && ! file_exists($barrier)) {
    if (time() - $start > 15) {
        fwrite(STDERR, "barrier timeout\n");
        exit(1);
    }
    usleep(2000);
}

try {
    if ($mode === 'approve') {
        $settlement = Modules\SalesReturn\Entities\SaleReturnItemSettlement::query()->findOrFail((int) $argv[3]);
        $request = Request::create('/approve', 'POST');
        $app->instance('request', $request);
        app(Modules\SalesReturn\Http\Controllers\SalesReturnSettlementController::class)->approveItemSettlement($request, $settlement);
        $error = session('error');
        echo 'RESULT:' . ($error ? 'blocked' : 'approved') . "\n";
    } elseif ($mode === 'pay') {
        app(Modules\Sale\Services\GlobalSalePaymentService::class)->storeMultiPayment((int) $argv[3], [
            'allocations' => [(int) $argv[4] => (float) $argv[5]],
            'reference' => 'GP-' . getmypid(), 'date' => now()->toDateString(), 'payment_method_id' => (int) $argv[6],
        ]);
        echo "RESULT:paid\n";
    } elseif ($mode === 'release') {
        $return = Modules\SalesReturn\Entities\SaleReturn::query()->findOrFail((int) $argv[3]);
        app(App\Support\SalesReturn\StandardReturnCustodyService::class)->release($return, [['custody_id' => (int) $argv[4], 'quantity' => (float) ($argv[5] ?? 1)]], 'concurrent release', 'K-' . getmypid());
        echo "RESULT:released\n";
    } else {
        fwrite(STDERR, "unknown mode\n");
        exit(2);
    }
} catch (Throwable $e) {
    echo 'RESULT:refused:' . class_basename($e) . ':' . substr($e->getMessage(), 0, 80) . "\n";
}
