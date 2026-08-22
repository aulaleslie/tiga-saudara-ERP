<?php

/**
 * Worker process for MySQL-backed concurrency tests that exercise the REAL
 * production POS checkout path (Modules\Pos\Services\FinalizePosCheckoutService::finalize())
 * under real OS-process concurrency.
 *
 * This intentionally does NOT reimplement any locking/allocation/posting logic.
 * It resolves the actual application service from the container and calls its
 * real public `finalize()` entry point — the same one
 * Modules\Pos\Http\Controllers\PosSellController::checkoutFinalize() calls.
 *
 * Because a bare CLI process has no HTTP session pipeline, cart lines are
 * seeded through the real PosCartService::addLine() (the same service the
 * cart-mutation routes call), backed by a manually-started array session
 * store bound into the container so PosCartSessionStore's session()-based
 * storage works exactly as it would mid-request.
 *
 * Usage:
 *   php pos_checkout_worker.php \
 *       <terminalSettingId> <posSessionId> <cashierUserId> \
 *       <productId> <bundleId> <paymentMethodId> \
 *       <idempotencyKeyPrefix> <iterations> <barrierFile> \
 *       [<standaloneSourceProductId>]
 *
 * When <standaloneSourceProductId> is > 0, that product (owned by a
 * different Setting than <terminalSettingId>, reachable via a
 * cross-setting SettingSaleLocation mapping) is added as cart line 1
 * BEFORE the bundle line. PosCheckoutSplitPlannerService::plan() builds
 * its owner groups by iterating cart lines in insertion order, so this
 * genuinely reverses which owner's sequence namespace is inserted first
 * into SplitPosCheckoutPostingAdapter's pre-canonical lock list, before
 * lockNamespacesCanonically() sorts it. The worker prints the actual
 * cart-line product-ownership order it constructed (LINE_ORDER:) so the
 * orchestrating test can verify the reversal actually happened rather
 * than merely being plausible.
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config(['database.default' => 'mysql_test']);
config(['session.driver' => 'array']);
config(['cache.default' => 'array']);

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\FinalizePosCheckoutService;
use Modules\Pos\Services\PosCartService;
use Modules\Product\Entities\Product;

$terminalSettingId = (int) ($argv[1] ?? 0);
$posSessionId = (int) ($argv[2] ?? 0);
$cashierUserId = (int) ($argv[3] ?? 0);
$productId = (int) ($argv[4] ?? 0);
$bundleId = (int) ($argv[5] ?? 0);
$paymentMethodId = (int) ($argv[6] ?? 0);
$idempotencyPrefix = $argv[7] ?? ('W' . getmypid());
$iterations = (int) ($argv[8] ?? 1);
$barrierFile = $argv[9] ?? '';
$standaloneSourceProductId = (int) ($argv[10] ?? 0);

// Wait for start barrier (same touch-file convention as worker.php).
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

// Start a real, container-bound session store so PosCartSessionStore's
// session()-based reads/writes behave as they would mid-HTTP-request.
// SessionManager caches the resolved driver instance for the lifetime of
// this process, so subsequent session() calls hit the same Store.
session()->start();

$cartService = $app->make(PosCartService::class);
$finalizeService = $app->make(FinalizePosCheckoutService::class);

/** @var PosSession $posSession */
$posSession = PosSession::query()->findOrFail($posSessionId);

$bundleOwnerSettingId = $terminalSettingId;
$standaloneOwnerSettingId = $standaloneSourceProductId > 0
    ? (int) (Product::query()->whereKey($standaloneSourceProductId)->value('setting_id') ?? 0)
    : 0;

for ($i = 0; $i < $iterations; $i++) {
    try {
        // Seed a fresh cart for this iteration via the real cart service
        // (same code path the cart-mutation HTTP routes use). When a
        // standalone source-owned product is supplied, it is added as cart
        // line 1 BEFORE the bundle line, reversing which owner's group the
        // split planner encounters first.
        $lineOrder = [];
        if ($standaloneSourceProductId > 0) {
            $cartService->addLine($terminalSettingId, $posSessionId, $standaloneSourceProductId, 1, null, null);
            $lineOrder[] = $standaloneOwnerSettingId;
        }

        $cartService->addLine($terminalSettingId, $posSessionId, $productId, 1, null, $bundleId > 0 ? $bundleId : null);
        $lineOrder[] = $bundleOwnerSettingId;

        echo 'LINE_ORDER:' . implode('>', $lineOrder) . "\n";

        $idempotencyKey = sprintf('%s-%d-%s', $idempotencyPrefix, $i, uniqid());

        $result = $finalizeService->finalize(
            $terminalSettingId,
            $posSession,
            $cashierUserId,
            $idempotencyKey,
            [
                'payment_method_id' => $paymentMethodId,
                'amount_paid' => 100000,
            ],
            null
        );

        $payload = $result['payload'] ?? [];
        $httpStatus = (int) ($result['http_status'] ?? 0);

        if ($httpStatus >= 400) {
            fwrite(STDERR, "Worker checkout failed at iteration {$i}: http_status={$httpStatus} " . json_encode($payload) . "\n");
            exit(1);
        }

        echo 'CHECKOUT_ID:' . (int) ($payload['pos_checkout_id'] ?? 0) . "\n";
        echo 'STATUS:' . (string) ($payload['status'] ?? '') . "\n";

        $splitGroups = $payload['split_groups'] ?? null;
        if (is_array($splitGroups) && $splitGroups !== []) {
            foreach ($splitGroups as $group) {
                $saleId = (int) ($group['sale_id'] ?? 0);
                $settingIdForGroup = (int) ($group['source_setting_id'] ?? 0);
                if ($saleId > 0) {
                    $reference = DB::connection('mysql_test')->table('sales')->where('id', $saleId)->value('reference');
                    echo 'SALE_ID:' . $saleId . "\n";
                    echo 'REF:' . (string) $reference . "\n";
                    echo 'SETTING:' . $settingIdForGroup . "\n";
                }
            }
        } else {
            $saleId = (int) ($payload['sale_id'] ?? 0);
            if ($saleId > 0) {
                $reference = DB::connection('mysql_test')->table('sales')->where('id', $saleId)->value('reference');
                echo 'SALE_ID:' . $saleId . "\n";
                echo 'REF:' . (string) $reference . "\n";
                echo 'SETTING:' . $terminalSettingId . "\n";
            }
        }

        flush();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Worker exception at iteration {$i}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}

exit(0);
