<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Services\PosReplacementAccountingRepairService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

/**
 * Historical A -> B -> C replacement chains must preview and apply using proven, non-reused payment evidence.
 */
class PosReplacementAccountingChainRepairTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

 /** @test */
 public function full_historical_chain_previews_and_applies(): void{
 $f=$this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
 $r=$f['pos_return']->replicate();$r->reference='CHAIN-SECOND';$r->settled_at=now()->addMinute();$r->save();
 $sr=$f['sale_return']->replicate();$sr->pos_return_id=$r->id;$sr->sale_id=$f['replacement_sale']->id;$sr->reference='CHAIN-SR-SECOND';$sr->save();
 $line=$f['return_line_printer']->replicate();$line->pos_return_id=$r->id;$line->sale_return_id=$sr->id;$line->sale_id=$f['replacement_sale']->id;$line->sale_detail_id=$f['replacement_sale_detail']->id;$line->source_setting_id=$f['setting_b']->id;$line->returned_serial_id=$f['replacement_serial']->id;$line->save();
 $d=$f['sale_return_detail_printer']->replicate();$d->sale_return_id=$sr->id;$d->pos_return_line_id=$line->id;$d->sale_detail_id=$f['replacement_sale_detail']->id;$ctx=$d->execution_context;$ctx['replacement_serial_owner_setting_id']=$f['setting_unrelated']->id;$ctx['generated_replacement_sale_effects']['setting_id']=$f['setting_unrelated']->id;$d->execution_context=$ctx;$d->save();
 $s=$f['replacement_sale']->replicate();$s->setting_id=$f['setting_unrelated']->id;$s->reference='CHAIN-REPL-SECOND';$s->note='Generated from POS Return '.$r->reference.' cross-owner replacement. Original Sale: '.$f['replacement_sale']->reference;$s->save();
 $sd=$f['replacement_sale_detail']->replicate();$sd->sale_id=$s->id;$sd->save();
 $p=$f['replacement_payment']->replicate();$p->sale_id=$s->id;$p->reference='CROSS-OWNER-REPL/'.$r->reference;$p->save();
 $dis=$f['replacement_dispatch']->replicate();$dis->sale_id=$s->id;$dis->save();$dd=$f['replacement_dispatch_detail']->replicate();$dd->sale_id=$s->id;$dd->dispatch_id=$dis->id;$dd->sale_detail_id=$sd->id;$dd->pos_return_line_id=$line->id;$dd->save();
 $f['replacement_sale_detail']->update(['quantity'=>0,'sub_total'=>0]);$f['replacement_sale']->update(['total_amount'=>0,'paid_amount'=>0,'due_amount'=>0]);
 $svc=app(PosReplacementAccountingRepairService::class);$plan=$svc->plan($f['transaction']->id,$f['setting_a']->id);
 $this->assertSame('repairable', $plan['classification'], $plan['reason'] ?? '');
 $result=$svc->apply($f['transaction']->id,$f['setting_a']->id,$f['cashier']->id,'Review isolated chain',$plan['evidence_fingerprint']);
 $this->assertSame('repaired', $result['classification']);
 $this->assertEquals(2600000.0, $result['summary']['proposed_grand_total']);

 }

 /** @test */
 public function partial_historical_chain_previews_and_applies_without_reusing_consumed_funds(): void{
 $f=$this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
 $r=$f['pos_return']->replicate();$r->reference='CHAIN-SECOND';$r->settled_at=now()->addMinute();$r->save();
 $sr=$f['sale_return']->replicate();$sr->pos_return_id=$r->id;$sr->sale_id=$f['replacement_sale']->id;$sr->reference='CHAIN-SR-SECOND';$sr->save();
 $line=$f['return_line_printer']->replicate();$line->pos_return_id=$r->id;$line->sale_return_id=$sr->id;$line->sale_id=$f['replacement_sale']->id;$line->sale_detail_id=$f['replacement_sale_detail']->id;$line->source_setting_id=$f['setting_b']->id;$line->returned_serial_id=$f['replacement_serial']->id;$line->save();
 $d=$f['sale_return_detail_printer']->replicate();$d->sale_return_id=$sr->id;$d->pos_return_line_id=$line->id;$d->sale_detail_id=$f['replacement_sale_detail']->id;$ctx=$d->execution_context;$ctx['replacement_serial_owner_setting_id']=$f['setting_unrelated']->id;$ctx['generated_replacement_sale_effects']['setting_id']=$f['setting_unrelated']->id;$ctx['generated_replacement_sale_effects']['payment_amount']=1112500;$ctx['original_sale_correction_amount']=1112500;$d->quantity=0.5;$d->sub_total=1112500;$d->execution_context=$ctx;$d->save();
 $s=$f['replacement_sale']->replicate();$s->setting_id=$f['setting_unrelated']->id;$s->reference='CHAIN-REPL-SECOND';$s->note='Generated from POS Return '.$r->reference.' cross-owner replacement. Original Sale: '.$f['replacement_sale']->reference;$s->total_amount=1112500;$s->paid_amount=1112500;$s->save();
 $sd=$f['replacement_sale_detail']->replicate();$sd->sale_id=$s->id;$sd->quantity=0.5;$sd->sub_total=1112500;$sd->save();
 $p=$f['replacement_payment']->replicate();$p->sale_id=$s->id;$p->reference='CROSS-OWNER-REPL/'.$r->reference;$p->amount=1112500;$p->save();
 $dis=$f['replacement_dispatch']->replicate();$dis->sale_id=$s->id;$dis->save();$dd=$f['replacement_dispatch_detail']->replicate();$dd->sale_id=$s->id;$dd->dispatch_id=$dis->id;$dd->sale_detail_id=$sd->id;$dd->pos_return_line_id=$line->id;$dd->save();
 $f['replacement_sale_detail']->update(['quantity'=>0.5,'sub_total'=>1112500]);
 $svc=app(PosReplacementAccountingRepairService::class);$plan=$svc->plan($f['transaction']->id,$f['setting_a']->id);
 $this->assertSame('repairable', $plan['classification'], $plan['reason'] ?? '');
 $result=$svc->apply($f['transaction']->id,$f['setting_a']->id,$f['cashier']->id,'Review isolated chain',$plan['evidence_fingerprint']);
 $this->assertSame('repaired', $result['classification']);
 $this->assertEquals(2600000.0, $result['summary']['proposed_grand_total']);
 $transfers = PosOwnerPaymentTransfer::query()->orderBy('id')->get();
 $this->assertCount(2, $transfers);
 $this->assertSame(222500000, (int) $transfers[0]->amount_minor);
 $this->assertSame(111250000, (int) $transfers[1]->amount_minor);
 $this->assertSame((int) $transfers[0]->id, (int) $transfers[1]->predecessor_transfer_id);
 $this->assertSame((int) $transfers[0]->original_sale_payment_id, (int) $transfers[1]->original_sale_payment_id);
 $this->assertLessThanOrEqual(222500000, (int) $transfers->whereNull('predecessor_transfer_id')->sum('amount_minor'));

 }
}
