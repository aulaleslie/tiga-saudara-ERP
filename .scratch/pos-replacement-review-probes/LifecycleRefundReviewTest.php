<?php
require_once getcwd().'/Modules/Pos/Tests/Feature/POSReturnReplacement10717RegressionTest.php';
class LifecycleRefundReviewTest extends \Modules\Pos\Tests\Feature\POSReturnReplacement10717RegressionTest {
 public function createApplication() { $app=parent::createApplication(); $path='/tmp/lifecycle_review_testing.sqlite'; if(!file_exists($path))touch($path); $app['config']->set('database.connections.sqlite.database',$path); \Illuminate\Support\Facades\DB::purge('sqlite'); return $app; }
 public function test_full_replacement_refund_probe():void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);
  $life->executeApprovalFromPreview($f['pos_return']->id);
  $sale=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  $sale->saleDetails()->update(['quantity'=>0,'sub_total'=>0]);
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$sale->id;$sr->reference='SR-PROBE';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>2225000];$srd->save();
  $method=new ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);
  $method->invoke($life,$sr);
  $this->assertSame(0.0,$sale->fresh()->getEffectivePaidAmount());
 }
 public function test_mixed_incoming_direct_refund_probe():void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);$life->executeApprovalFromPreview($f['pos_return']->id);
  $sale=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  // Legitimate subsequent adjustment and later payment: incoming 2,225,000 plus direct 200,000.
  $sale->update(['total_amount'=>2425000,'paid_amount'=>2425000]);
  \Modules\Sale\Entities\SalePayment::create(['sale_id'=>$sale->id,'amount'=>200000,'date'=>'2026-04-16','reference'=>'NEW-PAY','payment_method'=>'CASH']);
  $sale->saleDetails()->update(['quantity'=>1,'sub_total'=>1925000]);
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$sale->id;$sr->reference='SR-PROBE2';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->sub_total=500000;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>500000];$srd->save();
  $method=new ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $this->assertSame(1925000.0,$sale->fresh()->getEffectivePaidAmount());
 }

 public function test_chain_refund_probe():void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);$life->executeApprovalFromPreview($f['pos_return']->id);
  $saleB=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  $transfer1=$saleB->incomingOwnerPaymentTransfers()->firstOrFail();
  $saleC=$saleB->replicate();$saleC->reference='CHAIN-C';$saleC->save();
  $detailB=$saleB->saleDetails()->firstOrFail();$detailC=$detailB->replicate();$detailC->sale_id=$saleC->id;$detailC->save();
  $saleB->update(['total_amount'=>0,'paid_amount'=>0,'due_amount'=>0]);$detailB->update(['quantity'=>0,'sub_total'=>0]);
  $srd2=$f['sale_return_detail_printer']->replicate();$srd2->save();
  $transfer2=$transfer1->replicate();$transfer2->execution_key='CHAIN-TRANSFER';$transfer2->sale_return_detail_id=$srd2->id;$transfer2->source_sale_id=$saleB->id;$transfer2->replacement_sale_id=$saleC->id;$transfer2->predecessor_transfer_id=$transfer1->id;$transfer2->save();
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$saleC->id;$sr->reference='CHAIN-REFUND';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->sub_total=500000;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>500000];$srd->save();
  $detailC->update(['quantity'=>1,'sub_total'=>1725000]);
  $method=new ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $paidA=$f['source_sale']->fresh()->getEffectivePaidAmount();$paidB=$saleB->fresh()->getEffectivePaidAmount();$paidC=$saleC->fresh()->getEffectivePaidAmount();
  fwrite(STDERR,"CHAIN paid A=$paidA B=$paidB C=$paidC\n");
  $this->assertSame(45000.0,$paidA);
  $this->assertSame(0.0,$paidB,'Intermediate owner must retain no paid balance after C refund');
  $this->assertSame(1725000.0,$paidC);
  $this->assertSame(1770000.0,$paidA+$paidB+$paidC);
  $this->assertSame(1725000.0,(float)$saleC->fresh()->total_amount);
  $this->assertSame(2,\Modules\Pos\Entities\PosOwnerPaymentTransferReversal::where('sale_return_id',$sr->id)->count());
 }

 public function test_sibling_payment_refund_probe():void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);$life->executeApprovalFromPreview($f['pos_return']->id);
  $saleB=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  $source=$f['source_sale']->fresh();$transfer1=$saleB->incomingOwnerPaymentTransfers()->firstOrFail();
  // Independent later purchase/payment transferred from the same source owner to D.
  $p2=\Modules\Sale\Entities\SalePayment::create(['sale_id'=>$source->id,'amount'=>100000,'date'=>'2026-11-07','reference'=>'SIBLING-PAY','payment_method'=>'CARD','stage_order'=>99]);
  $saleD=$saleB->replicate();$saleD->reference='SIBLING-D';$saleD->total_amount=100000;$saleD->paid_amount=100000;$saleD->save();
  $srd2=$f['sale_return_detail_printer']->replicate();$srd2->save();
  $transfer2=$transfer1->replicate();$transfer2->execution_key='SIBLING-TRANSFER';$transfer2->sale_return_detail_id=$srd2->id;$transfer2->replacement_sale_id=$saleD->id;$transfer2->original_sale_payment_id=$p2->id;$transfer2->payment_method='CARD';$transfer2->amount=100000;$transfer2->amount_minor=10000000;$transfer2->save();
  $this->assertSame(45000.0,$source->fresh()->getEffectivePaidAmount());$this->assertSame(100000.0,$saleD->fresh()->getEffectivePaidAmount());
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$saleB->id;$sr->reference='SIBLING-REFUND';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->sub_total=500000;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>500000];$srd->save();
  $saleB->saleDetails()->update(['quantity'=>1,'sub_total'=>1725000]);
  $method=new ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  fwrite(STDERR,'SIBLING paid A='.$source->fresh()->getEffectivePaidAmount().' B='.$saleB->fresh()->getEffectivePaidAmount().' D='.$saleD->fresh()->getEffectivePaidAmount().' p2='.$p2->fresh()->status."\n");
  $this->assertSame(100000.0,$saleD->fresh()->getEffectivePaidAmount(),'Unrelated sibling owner must retain its payment');
  $this->assertSame(45000.0,$source->fresh()->getEffectivePaidAmount());
 }
}
