<?php
use Modules\Sale\Entities\Sale; use Modules\Sale\Entities\SalePayment; use Modules\Pos\Entities\PosOwnerPaymentTransfer; use Illuminate\Support\Facades\DB;
require __DIR__.'/boot.php';
[$mode,$startAt,$arg]=[$argv[1],(float)$argv[2],$argv[3]??null];
$svc=app(Modules\Pos\Services\PosReturnLifecycleService::class);
$m=new ReflectionMethod($svc,'reduceTransferChain');$m->setAccessible(true);
while(microtime(true)<$startAt) usleep(200);
$out=['mode'=>$mode];
try {
  if($mode==='refund'){ // reduce T2 by 30000 minor via the real helper
    DB::transaction(function() use($svc,$m,$arg){ $t0=PosOwnerPaymentTransfer::findOrFail((int)$arg); Sale::query()->whereKey((int)$t0->replacement_sale_id)->lockForUpdate()->first(); /* lifecycle locks the refunded sale first */ $t=PosOwnerPaymentTransfer::query()->lockForUpdate()->findOrFail((int)$arg); $sr=(new Modules\SalesReturn\Entities\SaleReturn)->forceFill(['id'=>900+getmypid()%90]); $m->invoke($svc,$t,30000,$sr); usleep(150000); });
  } elseif($mode==='repairlocks'){ // same order as PosReplacementAccountingRepairService::apply: sales -> payments -> transfers
    DB::transaction(function() { $ids=Sale::query()->whereIn('reference',['A','B','C','D'])->orderBy('id')->lockForUpdate()->pluck('id')->all();
      SalePayment::query()->whereIn('sale_id',$ids)->orderBy('id')->lockForUpdate()->get(); usleep(100000);
      PosOwnerPaymentTransfer::query()->orderBy("id")->lockForUpdate()->get(); usleep(100000); }, 3);
  } elseif($mode==='dupkey'){
    PosOwnerPaymentTransfer::create(['execution_key'=>'DUP-KEY','pos_return_id'=>1,'sale_return_detail_id'=>50,'source_sale_id'=>(int)$arg,'replacement_sale_id'=>(int)$argv[4],'original_sale_payment_id'=>(int)$argv[5],'payment_method'=>'CASH','amount'=>10,'amount_minor'=>1000]);
  } elseif($mode==='overalloc'){ // two 600 transfers (distinct keys) from one 1000 payment, no sale lock
    DB::transaction(function() use($argv,$arg){ PosOwnerPaymentTransfer::create(['execution_key'=>'OVER-'.getmypid(),'pos_return_id'=>1,'sale_return_detail_id'=>60+getmypid()%1000,'source_sale_id'=>(int)$arg,'replacement_sale_id'=>(int)$argv[4],'original_sale_payment_id'=>(int)$argv[5],'payment_method'=>'CASH','amount'=>600,'amount_minor'=>60000]); usleep(200000); });
  }
  $out['result']='ok';
} catch (Throwable $e){ $out['result']='error'; $out['class']=get_class($e); $out['msg']=substr($e->getMessage(),0,160); }
echo json_encode($out),"\n";
