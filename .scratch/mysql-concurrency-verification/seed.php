<?php
use Modules\Sale\Entities\Sale; use Modules\Sale\Entities\SalePayment; use Modules\Pos\Entities\PosOwnerPaymentTransfer; use Illuminate\Support\Facades\DB;
require __DIR__.'/boot.php';
// Fresh minimal data: payment P(1000) on A; T1 A->B 1000; T2 B->C 1000 (pred T1); extra sibling payment Q(100) on A with T3 A->D 100.
foreach (['pos_owner_payment_transfer_reversals','pos_owner_payment_transfers','sale_payments','sales'] as $t) DB::table($t)->delete();
$mk=fn($ref,$tot)=>DB::table('sales')->insertGetId(['date'=>'2026-11-07','reference'=>$ref,'customer_name'=>'X','total_amount'=>$tot,'paid_amount'=>$tot,'due_amount'=>0,'status'=>'Completed','payment_status'=>'Paid','payment_method'=>'CASH','created_at'=>now(),'updated_at'=>now()]);
[$a,$b,$c,$d]=[$mk('A',1170),$mk('B',1000),$mk('C',1000),$mk('D',100)];
$pay=fn($sid,$amt,$ref,$m)=>DB::table('sale_payments')->insertGetId(['sale_id'=>$sid,'amount'=>$amt,'date'=>'2026-11-07','reference'=>$ref,'payment_method'=>$m,'status'=>'ACTIVE','created_at'=>now(),'updated_at'=>now()]);
$p=$pay($a,1000,'P','CASH'); $q=$pay($a,100,'Q','CARD'); $pay($a,70,'R','CASH');
$base=['pos_transaction_id'=>1,'pos_checkout_id'=>1,'pos_return_id'=>1,'sale_return_id'=>1,'sale_return_detail_id'=>1,'payment_method'=>'CASH','transfer_source'=>'HISTORICAL_REPAIR'];
$t1=PosOwnerPaymentTransfer::create($base+['source_sale_id'=>$a,'replacement_sale_id'=>$b,'original_sale_payment_id'=>$p,'amount'=>1000,'amount_minor'=>100000]);
$t2=PosOwnerPaymentTransfer::create(array_merge($base,['sale_return_detail_id'=>2,'source_sale_id'=>$b,'replacement_sale_id'=>$c,'original_sale_payment_id'=>$p,'predecessor_transfer_id'=>$t1->id,'amount'=>1000,'amount_minor'=>100000]));
$t3=PosOwnerPaymentTransfer::create(array_merge($base,['sale_return_detail_id'=>3,'payment_method'=>'CARD','source_sale_id'=>$a,'replacement_sale_id'=>$d,'original_sale_payment_id'=>$q,'amount'=>100,'amount_minor'=>10000]));
echo json_encode(array_map(fn($x)=>is_object($x)?$x->id:$x,compact('a','b','c','d','p','q','t1','t2','t3'))),"\n";
