<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;

$batch = SalesImportBatch::latest()->first();
print_r(SalesImportRow::where('batch_id', $batch->id)->get()->toArray());
