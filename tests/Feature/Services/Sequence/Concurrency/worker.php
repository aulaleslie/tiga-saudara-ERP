<?php

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config(['database.default' => 'mysql_test']);

use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceNamespace;
use Illuminate\Support\Facades\DB;

// Mode: standard (argv[1] = 'purchase'|'sale') or split (argv[1] = 'pos_split')
$mode = $argv[1] ?? 'purchase';

if ($mode === 'pos_split') {
    $settingIdA = (int) ($argv[2] ?? 1);
    $settingIdB = (int) ($argv[3] ?? 2);
    $prefixA = $argv[4] ?? 'PD-S1-SL';
    $prefixB = $argv[5] ?? 'PD-S2-SL';
    $year = (int) ($argv[6] ?? 2026);
    $month = (int) ($argv[7] ?? 8);
    $iterations = (int) ($argv[8] ?? 10);
    $reverseOrder = (bool) ($argv[9] ?? false);
    $barrierFile = $argv[10] ?? '';

    // Wait for start barrier
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

    $allocator = app(DocumentSequenceAllocator::class);

    $nsA = new SequenceNamespace(DocumentType::SALE, $settingIdA, $prefixA, $year, $month);
    $nsB = new SequenceNamespace(DocumentType::SALE, $settingIdB, $prefixB, $year, $month);

    // Provide namespaces in opposite order to deliberately test deadlock immunity through canonical sorting
    $inputNamespaces = $reverseOrder ? [$nsB, $nsA] : [$nsA, $nsB];

    for ($i = 0; $i < $iterations; $i++) {
        try {
            $allocations = DB::connection('mysql_test')->transaction(function () use ($allocator, $inputNamespaces, $year, $month) {
                // 1. Lock all owner namespaces canonically
                $allocator->lockNamespacesCanonically($inputNamespaces);

                // 2. Allocate and insert sales for each owner
                $results = [];
                foreach ($inputNamespaces as $ns) {
                    $alloc = $allocator->allocate($ns);

                    DB::connection('mysql_test')->table('sales')->insert([
                        'setting_id' => $ns->settingId,
                        'reference' => $alloc->reference,
                        'date' => sprintf('%04d-%02d-01', $year, $month),
                        'due_date' => sprintf('%04d-%02d-15', $year, $month),
                        'customer_id' => 1,
                        'customer_name' => 'Test Customer',
                        'tax_percentage' => 0,
                        'tax_amount' => 0,
                        'discount_percentage' => 0,
                        'discount_amount' => 0,
                        'shipping_amount' => 0,
                        'total_amount' => 100000,
                        'paid_amount' => 0,
                        'due_amount' => 100000,
                        'status' => 'Drafted',
                        'payment_status' => 'Unpaid',
                        'payment_method' => 'Cash',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $results[] = $alloc->reference;
                }

                return $results;
            }, 3);

            foreach ($allocations as $ref) {
                echo "REF:" . $ref . "\n";
            }
            flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "Split Worker exception at iteration {$i}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    exit(0);
}

// Standard single document allocation mode
$docType = $argv[1] ?? 'purchase';
$settingId = (int) ($argv[2] ?? 1);
$prefix = $argv[3] ?? 'TS-PO';
$year = (int) ($argv[4] ?? 2026);
$month = (int) ($argv[5] ?? 8);
$iterations = (int) ($argv[6] ?? 10);
$barrierFile = $argv[7] ?? '';

// Wait for start barrier
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

$namespace = new SequenceNamespace($docType, $settingId, $prefix, $year, $month);
$allocator = app(DocumentSequenceAllocator::class);

for ($i = 0; $i < $iterations; $i++) {
    try {
        // Wrap the entire business document operation in an outer database transaction with 3 retry attempts,
        // calling DocumentReferenceService directly to exercise executeWithConflictRetry under an active outer transaction.
        $allocatedRef = DB::connection('mysql_test')->transaction(function () use ($docType, $settingId, $year, $month) {
            if ($docType === 'purchase') {
                $doc = \App\Services\DocumentReferenceService::createPurchaseWithReference([
                    'setting_id' => $settingId,
                    'date' => sprintf('%04d-%02d-01', $year, $month),
                    'due_date' => sprintf('%04d-%02d-15', $year, $month),
                    'supplier_id' => 1,
                    'tax_percentage' => 0,
                    'tax_amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => 100000,
                    'paid_amount' => 0,
                    'due_amount' => 100000,
                    'status' => 'Drafted',
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'Cash',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $doc = \App\Services\DocumentReferenceService::createSaleWithReference([
                    'setting_id' => $settingId,
                    'date' => sprintf('%04d-%02d-01', $year, $month),
                    'due_date' => sprintf('%04d-%02d-15', $year, $month),
                    'customer_id' => 1,
                    'customer_name' => 'Test Customer',
                    'tax_percentage' => 0,
                    'tax_amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => 100000,
                    'paid_amount' => 0,
                    'due_amount' => 100000,
                    'status' => 'Drafted',
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'Cash',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $doc->reference;
        }, 3);

        echo "REF:" . $allocatedRef . "\n";
        flush();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Worker exception at iteration {$i}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}

exit(0);
