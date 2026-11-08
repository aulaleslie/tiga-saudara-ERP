<?php

/**
 * Worker for the POS unknown-serial registration concurrency tests (task 6.2, POS side).
 *
 *   approve <userId> <settingId> <posReturnId> <barrier>   same steps as PosReturnController::approve
 *   submit  <userId> <settingId> <posReturnId> <barrier>   PosReturnSubmissionService::submitDraftForApproval
 *   store   <userId> <settingId> <posTransactionId> <saleDetailId> <saleId> <pendingText> <replacementSerialId> <barrier>   PosReturnSubmissionService::store (draft save with a replacement serial)
 *   serial  <userId> <settingId> <productId> <locationId> <serialText> <barrier>   competing serial creator
 * Prints one RESULT:<outcome> line.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'mysql_test', 'session.driver' => 'array', 'cache.default' => 'array']);
session()->start();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Pos\Entities\PosReturn;

Gate::before(fn () => true);
$app->instance(App\Services\Notification\DocumentNotificationService::class, Mockery::mock(App\Services\Notification\DocumentNotificationService::class)->shouldIgnoreMissing());

$mode = $argv[1] ?? '';
Auth::loginUsingId((int) ($argv[2] ?? 0));
session(['setting_id' => (int) ($argv[3] ?? 0)]);

$barrier = (string) end($argv);
$start = time();
while (! file_exists($barrier)) {
    if (time() - $start > 15) {
        fwrite(STDERR, "barrier timeout\n");
        exit(1);
    }
    usleep(2000);
}

try {
    if ($mode === 'approve') {
        $return = PosReturn::query()->findOrFail((int) $argv[4]);
        $plan = app(Modules\Pos\Services\PosReturnApprovalPreviewPlannerService::class)->plan($return->fresh());
        if (! empty($plan['blockers']) || ! empty($plan['warnings'])) {
            echo 'RESULT:blocked' . "\n";
        } else {
            app(Modules\Pos\Services\PosReturnLifecycleService::class)->executeApprovalFromPreview($return->id, null, $plan);
            echo 'RESULT:' . $return->fresh()->status . "\n";
        }
    } elseif ($mode === 'submit') {
        $return = PosReturn::query()->findOrFail((int) $argv[4]);
        app(Modules\Pos\Services\PosReturnSubmissionService::class)->submitDraftForApproval($return);
        echo 'RESULT:submitted' . "\n";
    } elseif ($mode === 'store') {
        $snapshot = app(Modules\Pos\Services\PosReturnSnapshotService::class)->build((int) $argv[4]);
        $return = app(Modules\Pos\Services\PosReturnSubmissionService::class)->store([
            'pos_transaction_id' => (int) $argv[4], 'source_snapshot' => $snapshot, 'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_detail_id' => (int) $argv[5], 'sale_id' => (int) $argv[6], 'returned_serial_id' => null, 'pending_serial' => $argv[7],
                'resolution' => 'product_replacement', 'quantity' => 1, 'replacement_serial_id' => (int) $argv[8], 'replacement_reason' => 'Rusak',
            ]],
        ]);
        echo 'RESULT:stored:' . $return->id . "\n";
    } elseif ($mode === 'serial') {
        try {
            Modules\Product\Entities\ProductSerialNumber::create([
                'product_id' => (int) $argv[4], 'serial_number' => $argv[6], 'location_id' => (int) $argv[5], 'status' => 'ACTIVE',
            ]);
            echo "RESULT:created\n";
        } catch (Illuminate\Database\UniqueConstraintViolationException $e) {
            echo "RESULT:duplicate\n";
        }
    } else {
        fwrite(STDERR, "unknown mode\n");
        exit(2);
    }
} catch (Throwable $e) {
    echo 'RESULT:refused:' . class_basename($e) . ':' . substr(str_replace("\n", ' ', $e->getMessage()), 0, 120) . "\n";
}
