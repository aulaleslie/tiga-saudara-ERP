<?php
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName()!=='verify' || config('database.connections.mysql.port')!=3399) { fwrite(STDERR,"REFUSING: not the disposable DB\n"); exit(9); }
use Modules\Sale\Entities\Sale; use Modules\Sale\Entities\SalePayment; use Modules\Pos\Entities\PosOwnerPaymentTransfer;
