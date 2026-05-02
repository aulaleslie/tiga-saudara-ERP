<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$columns = DB::select("PRAGMA table_info(sales)");
foreach ($columns as $column) {
    echo $column->name . "\n";
}
echo "----\n";
$columns = DB::select("PRAGMA table_info(sale_details)");
foreach ($columns as $column) {
    echo $column->name . "\n";
}
