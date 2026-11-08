<?php

/**
 * Worker for the MySQL unknown-serial registration concurrency tests (task 6.2).
 *
 * Invokes the REAL production entry points:
 *   receive <userId> <saleReturnId> <barrier>                      SalesReturnController::receive (registers proposed serials)
 *   serial  <userId> <productId> <serialText> <barrier>            ProductSerialNumber::create (stand-in for purchase receiving)
 *   create  <userId> <saleId> <dispatchDetailId> <productId> <texts-csv> <barrier>   SaleReturnCreateForm::submit
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
Auth::loginUsingId((int) ($argv[2] ?? 0));

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
    if ($mode === 'receive') {
        $return = Modules\SalesReturn\Entities\SaleReturn::query()->findOrFail((int) $argv[3]);
        $app->instance('request', Request::create('/receive', 'POST'));
        app(Modules\SalesReturn\Http\Controllers\SalesReturnController::class)->receive($return);
        echo 'RESULT:' . ($return->fresh()->received_at ? 'received' : 'blocked') . "\n";
    } elseif ($mode === 'serial') {
        try {
            Modules\Product\Entities\ProductSerialNumber::create([
                'product_id' => (int) $argv[3], 'serial_number' => $argv[4], 'location_id' => 1, 'status' => 'ACTIVE',
            ]);
            echo "RESULT:created\n";
        } catch (Illuminate\Database\UniqueConstraintViolationException $e) {
            echo "RESULT:duplicate\n";
        }
    } elseif ($mode === 'create') {
        $rows = [[
            'dispatch_detail_id' => (int) $argv[4], 'product_id' => (int) $argv[5], 'serial_number_required' => true,
            'quantity' => count(explode(',', $argv[6])), 'unit_price' => 1000, 'total' => 1000 * count(explode(',', $argv[6])),
            'serial_numbers' => array_map(fn ($t) => ['id' => null, 'serial_number' => $t, 'pending' => true], explode(',', $argv[6])),
        ]];
        $before = Modules\SalesReturn\Entities\SaleReturn::query()->count();
        try {
            Livewire\Livewire::test(App\Livewire\SalesReturn\SaleReturnCreateForm::class)
                ->set('sale_id', (int) $argv[3])->set('date', now()->toDateString())->set('rows', $rows)->call('submit');
        } catch (Throwable $e) {
            // validation / claim rejection
        }
        echo 'RESULT:' . (Modules\SalesReturn\Entities\SaleReturn::query()->count() > $before ? 'created' : 'rejected') . "\n";
    } else {
        fwrite(STDERR, "unknown mode\n");
        exit(2);
    }
} catch (Throwable $e) {
    echo 'RESULT:refused:' . class_basename($e) . ':' . substr($e->getMessage(), 0, 80) . "\n";
}
