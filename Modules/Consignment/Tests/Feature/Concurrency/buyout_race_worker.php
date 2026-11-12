<?php

/**
 * Worker for the MySQL buyout race matrix. Calls the REAL production services/controllers; no harness retries.
 *   coord    <actorId> <settingId> <productId> [<holdMs>] <barrier>   (holds the coordination lock; default 20 ms)
 *   approve  <actorId> <settingId> <buyoutId> <barrier>
 *   finalize <actorId> <settingId> <buyoutId> <barrier>
 *   reverse  <actorId> <settingId> <buyoutId> <barrier>
 *   pay      <actorId> <settingId> <purchaseId> <paymentMethodId> <barrier>
 *   transfer <actorId> <settingId> <transferId> <barrier>   (real TransferV3DispatchExecutor::approveAndDispatch)
 *   pos      <actorId> <settingId> <json cfg> <barrier>   (own session+cart prepared before the barrier; races pos.sell.checkout.finalize)
 *   legacy   <actorId> <settingId> <confirmationId> <barrier>   (legacy ConsignmentBillingConfirmationLifecycleService::submitConfirmation)
 *   attribute <actorId> <settingId> <soldSourceId> <json receiptId=>qty> <barrier>   (real ConsignmentBuyoutCapacityService::setAttribution)
 *   declare  <actorId> <settingId> <saleReturnDetailId> <acquiredQty> <barrier>   (real ConsignmentBuyoutReturnOwnershipService::declare)
 *   discover <actorId> <settingId> <originSettingId> <barrier>   (manual ConsignmentSoldSourceDiscoveryService::discoverForSetting)
 *   http     <actorId> <settingId> <POST path> [<json params>] <barrier>   (full HTTP kernel, e.g. dispatch / receiving cancellation)
 * Prints RESULT:<outcome> (ok[:detail] | refused:<class>:<message>).
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'mysql_test', 'session.driver' => 'array', 'cache.default' => 'array',
    'consignment.buyouts.finalization_enabled' => true]);
if (! str_ends_with((string) config('database.connections.mysql_test.database'), '_test')) {
    fwrite(STDERR, "refusing: database must end in _test\n");
    exit(2);
}
session()->start();

$mode = $argv[1] ?? '';
$actorId = (int) ($argv[2] ?? 0);
$settingId = (int) ($argv[3] ?? 0);
session(['setting_id' => $settingId]);
$actor = Modules\Consignment\Tests\Feature\Concurrency\RaceWorkerBootstrap::login($actorId);
Illuminate\Support\Facades\Gate::define('purchaseReturns.dispatchApproval', fn () => true);
Illuminate\Support\Facades\Gate::define('saleReturns.receive', fn () => true);

$barrier = (string) end($argv);

// pos: prepare this worker's own session + cart BEFORE the barrier, so only the real finalize request is raced.
//   pos <actorId> <settingId> <json: product_id,qty,customer_id,payment_method_id,amount> <barrier>
$posSend = null;
if ($mode === 'pos') {
    $cfg = json_decode((string) ($argv[4] ?? '{}'), true);
    $store = app('session')->driver();
    $sid = Illuminate\Support\Str::random(40);
    $store->setId($sid);
    $store->start();
    $store->put('setting_id', $settingId);
    $store->regenerateToken();
    $token = $store->token();
    $store->save();
    $cookieName = config('session.cookie');
    $encrypter = app('encrypter');
    $cookie = $encrypter->encrypt(Illuminate\Cookie\CookieValuePrefix::create($cookieName, $encrypter->getKey()) . $sid, false);
    $posSend = function (string $method, string $uri, array $data) use ($cookieName, $cookie, $token) {
        $request = Illuminate\Http\Request::create($uri, $method, [], [$cookieName => $cookie], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($data));

        return app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    };
    $r1 = $posSend('POST', route('pos.sell.cart.lines.store', [], false), ['product_id' => $cfg['product_id'], 'qty' => $cfg['qty']]);
    $r2 = $posSend('PATCH', route('pos.sell.cart.customer.update', [], false), ['customer_id' => $cfg['customer_id']]);
    if ($r1->getStatusCode() >= 400 || $r2->getStatusCode() >= 400) {
        echo 'RESULT:prep-failed:' . $r1->getStatusCode() . '/' . $r2->getStatusCode() . ':' . substr(preg_replace('/\s+/', ' ', $r1->getContent() . $r2->getContent()), 0, 300) . "\n";
        exit(0);
    }
}

$start = time();
while ($barrier !== '' && ! file_exists($barrier)) {
    if (time() - $start > 30) {
        fwrite(STDERR, "barrier timeout\n");
        exit(1);
    }
    usleep(1000);
}

// Optional stagger so the OTHER winner order is exercised too (the lock-wait overlap still occurs when work is slow).
if ((int) getenv('RACE_DELAY_MS') > 0) {
    usleep((int) getenv('RACE_DELAY_MS') * 1000);
}

try {
    $id = (int) ($argv[4] ?? 0);
    switch ($mode) {
        case 'coord':
            Illuminate\Support\Facades\DB::transaction(function () use ($settingId, $id) {
                Modules\Consignment\Entities\ConsignmentInventoryCoordination::lockFor($settingId, $id);
                usleep(1000 * (count($GLOBALS['argv']) > 6 ? (int) $GLOBALS['argv'][5] : 20));
            });
            echo "RESULT:ok\n";
            break;
        case 'approve':
            $b = app(Modules\Consignment\Services\ConsignmentBuyoutLifecycleService::class)
                ->approve(Modules\Consignment\Entities\ConsignmentBuyout::findOrFail($id), $actor);
            echo 'RESULT:ok:' . $b->status . "\n";
            break;
        case 'finalize':
            $p = app(Modules\Consignment\Services\ConsignmentBuyoutFinalizationService::class)
                ->finalize(Modules\Consignment\Entities\ConsignmentBuyout::findOrFail($id), $actor);
            echo 'RESULT:ok:purchase=' . $p->id . "\n";
            break;
        case 'reverse':
            $b = app(Modules\Consignment\Services\ConsignmentBuyoutReversalService::class)
                ->reverse(Modules\Consignment\Entities\ConsignmentBuyout::findOrFail($id), $actor, 'race verification');
            echo 'RESULT:ok:' . $b->status . "\n";
            break;
        case 'pay':
            $p = app(Modules\Purchase\Services\PurchasePaymentStoreService::class)->store(
                Modules\Purchase\Entities\Purchase::findOrFail($id), $actor,
                ['date' => '2026-11-10', 'reference' => 'PAY-' . getmypid(), 'amount' => 10, 'payment_method_id' => (int) $argv[5]]);
            echo 'RESULT:ok:payment=' . $p->id . "\n";
            break;
        case 'transfer':
            $t = Modules\Adjustment\Entities\Transfer::findOrFail($id);
            $t = app(Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch(
                $t, $actor, $settingId, (int) $t->current_request_revision_id, (int) $t->approval_configuration_revision, null);
            echo 'RESULT:ok:' . $t->status . "\n";
            break;
        case 'pos':
            $resp = $posSend('POST', route('pos.sell.checkout.finalize', [], false), [
                'idempotency_key' => 'K-RACE-' . getmypid(),
                'payment' => ['payment_method_id' => $cfg['payment_method_id'], 'amount_paid' => $cfg['amount']]]);
            $code = $resp->getStatusCode();
            echo ($code < 400 ? 'RESULT:ok:' : 'RESULT:refused:http') . $code . ':' . substr(preg_replace('/\s+/', ' ', $resp->getContent()), 0, 200) . "\n";
            break;
        case 'legacy':
            $c = app(Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class)
                ->submitConfirmation(Modules\Consignment\Entities\ConsignmentBillingConfirmation::findOrFail($id), $actorId);
            echo 'RESULT:ok:' . $c->status . "\n";
            break;
        case 'declare':
            $row = app(Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService::class)
                ->declare(Modules\SalesReturn\Entities\SaleReturnDetail::findOrFail($id), (float) $argv[5], $actor);
            echo 'RESULT:ok:declared=' . $row->acquired_quantity . "\n";
            break;
        case 'discover':
            $r = app(Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($id);
            echo 'RESULT:ok:created=' . $r['created'] . ':existing=' . $r['existing'] . "\n";
            break;
        case 'attribute':
            app(Modules\Consignment\Services\ConsignmentBuyoutCapacityService::class)
                ->setAttribution($id, json_decode((string) $argv[5], true), $actorId, 'race re-attribution');
            echo "RESULT:ok:attributed\n";
            break;
        case 'http':
            $kernel = app(Illuminate\Contracts\Http\Kernel::class);
            $params = count($argv) > 6 ? (json_decode((string) $argv[5], true) ?: []) : [];
            $request = Illuminate\Http\Request::create((string) $argv[4], 'POST', $params);
            $request->setLaravelSession(session()->driver());
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $flash = (string) (session('error') ?? session('toast') ?? '');
            if ($flash === '' && session()->has('alert.config')) {
                $flash = (string) (json_decode((string) session('alert.config'), true)['title'] ?? '');
            }
            if ($response->isRedirection()) {
                $flash .= ' -> ' . $response->headers->get('Location');
            }
            echo ($status < 400 ? 'RESULT:ok:' : 'RESULT:refused:http') . $status . ':' . preg_replace('/\s+/', ' ', $flash) . "\n";
            break;
    }
} catch (Throwable $e) {
    echo 'RESULT:refused:' . get_class($e) . ':' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n";
}
exit(0);
