<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosOwnerPaymentTransferReversal;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\SalePayment;

class PosOwnerPaymentTransferRefundChainTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    protected function makeChain(): array
    {
        $f = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $a = $f['source_sale'];
        $b = $f['unrelated_sale'];
        $c = $b->replicate();
        $c->reference = $b->reference . '-C';
        $c->save();
        $payment = $f['source_payment'];

        $base = [
            'pos_transaction_id' => $f['transaction']->id,
            'pos_checkout_id' => $f['checkout']->id,
            'pos_return_id' => $f['pos_return']->id,
            'sale_return_id' => $f['sale_return']->id,
            'sale_return_detail_id' => $f['sale_return_detail_printer']->id,
            'original_sale_payment_id' => $payment->id,
            'payment_method' => 'CASH',
            'transfer_source' => PosOwnerPaymentTransfer::SOURCE_HISTORICAL_REPAIR,
        ];
        $t1 = PosOwnerPaymentTransfer::query()->create($base + [
            'source_sale_id' => $a->id, 'replacement_sale_id' => $b->id,
            'amount' => 1000.00, 'amount_minor' => 100000,
        ]);
        $t2 = PosOwnerPaymentTransfer::query()->create($base + [
            'source_sale_id' => $b->id, 'replacement_sale_id' => $c->id,
            'predecessor_transfer_id' => $t1->id,
            'amount' => 1000.00, 'amount_minor' => 100000,
        ]);

        return [$f, $payment, $t1, $t2];
    }

    protected function reduce(PosOwnerPaymentTransfer $t, int $minor, $saleReturn): void
    {
        $svc = app(PosReturnLifecycleService::class);
        $m = new \ReflectionMethod($svc, 'reduceTransferChain');
        $m->setAccessible(true);
        $m->invoke($svc, $t, $minor, $saleReturn);
    }

    /** @test */
    public function refund_reduces_every_hop_of_a_chain_and_root_payment_and_keeps_evidence(): void
    {
        [$f, $payment, $t1, $t2] = $this->makeChain();
        $rootBefore = (float) SalePayment::query()->where('sale_id', $payment->sale_id)->active()->sum('amount');

        $this->reduce($t2, 30000, $f['sale_return']);

        $this->assertSame(70000, (int) $t2->fresh()->amount_minor);
        $this->assertSame(70000, (int) $t1->fresh()->amount_minor);
        $rootAfter = (float) SalePayment::query()->where('sale_id', $payment->sale_id)->active()->sum('amount');
        $this->assertEqualsWithDelta($rootBefore - 300.00, $rootAfter, 0.001);
        $this->assertSame(2, PosOwnerPaymentTransferReversal::query()->count());
    }

    /** @test */
    public function full_reduction_preserves_snapshot_instead_of_silently_erasing_history(): void
    {
        [$f, , $t1, $t2] = $this->makeChain();

        $this->reduce($t2, 100000, $f['sale_return']);

        $this->assertNull(PosOwnerPaymentTransfer::query()->find($t2->id));
        $this->assertNull(PosOwnerPaymentTransfer::query()->find($t1->id));
        $rev = PosOwnerPaymentTransferReversal::query()->where('transfer_id', $t2->id)->firstOrFail();
        $this->assertTrue($rev->transfer_deleted);
        $this->assertSame(100000, $rev->amount_minor_before);
        $this->assertSame($t2->id, $rev->transfer_snapshot['id']);
    }

 /** @test */
 public function full_refund_of_replacement_leaves_no_effective_paid_balance():void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);
  $life->executeApprovalFromPreview($f['pos_return']->id);
  $sale=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  $sale->saleDetails()->update(['quantity'=>0,'sub_total'=>0]);
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$sale->id;$sr->reference='SR-PROBE';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>2225000];$srd->save();
  $method=new \ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);
  $method->invoke($life,$sr);
  $this->assertSame(0.0,$sale->fresh()->getEffectivePaidAmount());
 }
 /** @test */
 public function mixed_incoming_and_direct_payment_refund_keeps_remaining_value():void {
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
  $method=new \ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $this->assertSame(1925000.0,$sale->fresh()->getEffectivePaidAmount());
 }

 /** @test */
 public function chained_refund_conserves_owner_balances_and_records_reversals():void {
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
  $method=new \ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $paidA=$f['source_sale']->fresh()->getEffectivePaidAmount();$paidB=$saleB->fresh()->getEffectivePaidAmount();$paidC=$saleC->fresh()->getEffectivePaidAmount();
  $this->assertSame(45000.0,$paidA);
  $this->assertSame(0.0,$paidB,'Intermediate owner must retain no paid balance after C refund');
  $this->assertSame(1725000.0,$paidC);
  $this->assertSame(1770000.0,$paidA+$paidB+$paidC);
  $this->assertSame(1725000.0,(float)$saleC->fresh()->total_amount);
  $this->assertSame(2,\Modules\Pos\Entities\PosOwnerPaymentTransferReversal::where('sale_return_id',$sr->id)->count());
 }

 /** @test */
 public function refund_reduces_backing_payment_only_and_preserves_sibling_owner_allocation():void {
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
  $method=new \ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $this->assertSame(100000.0,$saleD->fresh()->getEffectivePaidAmount(),'Unrelated sibling owner must retain its payment');
  $this->assertSame(45000.0,$source->fresh()->getEffectivePaidAmount());
 }

 /** @test */
 public function refund_ignores_incoming_transfers_backed_by_invalidated_payments(): void {
  $f=$this->buildSyntheticTransaction10717Fixture('pending_approval');
  $this->actingAsInSetting($f['cashier'],$f['setting_a']);
  $life=app(\Modules\Pos\Services\PosReturnLifecycleService::class);$life->executeApprovalFromPreview($f['pos_return']->id);
  $saleB=\Modules\Sale\Entities\Sale::where('setting_id',$f['setting_b']->id)->where('note','like','%'.$f['pos_return']->reference.'%')->firstOrFail();
  $source=$f['source_sale']->fresh();$transfer1=$saleB->incomingOwnerPaymentTransfers()->firstOrFail();
  $p2=\Modules\Sale\Entities\SalePayment::create(['sale_id'=>$source->id,'amount'=>100000,'date'=>'2026-11-07','reference'=>'STALE-PAY','payment_method'=>'CARD','stage_order'=>99]);
  $srd2=$f['sale_return_detail_printer']->replicate();$srd2->save();
  $stale=$transfer1->replicate();$stale->execution_key='STALE-TRANSFER';$stale->sale_return_detail_id=$srd2->id;$stale->original_sale_payment_id=$p2->id;$stale->payment_method='CARD';$stale->amount=100000;$stale->amount_minor=10000000;$stale->save();
  $p2->forceFill(['status'=>\Modules\Sale\Entities\SalePayment::STATUS_INVALIDATED,'invalidated_at'=>now()])->save();
  $f['return_line_printer']->forceFill(['resolution'=>'cash_return'])->save();
  $sr=$f['sale_return']->replicate();$sr->sale_id=$saleB->id;$sr->reference='STALE-REFUND';$sr->save();
  $srd=$f['sale_return_detail_printer']->replicate();$srd->sale_return_id=$sr->id;$srd->sub_total=500000;$srd->execution_context=['resolution'=>'cash_return','planned_amount'=>500000];$srd->save();
  $saleB->saleDetails()->update(['quantity'=>1,'sub_total'=>1725000]);
  $method=new \ReflectionMethod($life,'recalculateCashCorrectedSourceSale');$method->setAccessible(true);$method->invoke($life,$sr);
  $this->assertSame(10000000,(int) $stale->fresh()->amount_minor,'Transfer backed by an invalidated payment must be untouched');
  $this->assertSame(0,\Modules\Pos\Entities\PosOwnerPaymentTransferReversal::where('transfer_id',$stale->id)->count());
  $this->assertSame(1725000.0,$saleB->fresh()->getEffectivePaidAmount());
 }
}
