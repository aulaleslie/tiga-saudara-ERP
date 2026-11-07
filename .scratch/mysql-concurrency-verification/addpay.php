<?php
use Illuminate\Support\Facades\DB;
require __DIR__.'/boot.php';
echo DB::table('sale_payments')->insertGetId(['sale_id'=>(int)$argv[1],'amount'=>1000,'date'=>'2026-11-07','reference'=>'SPARE','payment_method'=>'CASH','status'=>'ACTIVE','created_at'=>now(),'updated_at'=>now()]);
