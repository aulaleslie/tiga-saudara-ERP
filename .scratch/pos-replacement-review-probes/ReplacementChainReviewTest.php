<?php
use Modules\Pos\Services\PosReplacementAccountingRepairService;
class ReplacementChainReviewTest extends Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase {
 use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
 public function createApplication(){ $app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$p='/tmp/spec-review-chain-testing.sqlite';touch($p);$app['config']->set('database.default','sqlite');$app['config']->set('database.connections.sqlite.database',$p);return $app; }
 public function test_chain_probe(){
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
 $svc=app(PosReplacementAccountingRepairService::class);$plan=$svc->plan($f['transaction']->id,$f['setting_a']->id);fwrite(STDERR,json_encode(['classification'=>$plan['classification'],'reason'=>$plan['reason'],'transfers'=>$plan['planned_transfers']])."\n");
 $this->assertSame('repairable', $plan['classification'], $plan['reason'] ?? '');
 $result=$svc->apply($f['transaction']->id,$f['setting_a']->id,$f['cashier']->id,'Review isolated chain',$plan['evidence_fingerprint']);
 $this->assertSame('repaired', $result['classification']);
 $this->assertEquals(2600000.0, $result['summary']['proposed_grand_total']);

 }
}
