<?php
use Illuminate\Support\Facades\DB;
require __DIR__.'/boot.php';
foreach (DB::table('pos_owner_payment_transfers')->orderBy('id')->get(['id','source_sale_id','replacement_sale_id','original_sale_payment_id','predecessor_transfer_id','amount_minor']) as $t) echo "T{$t->id} {$t->source_sale_id}->{$t->replacement_sale_id} pay={$t->original_sale_payment_id} pred={$t->predecessor_transfer_id} minor={$t->amount_minor}\n";
foreach (DB::table('sale_payments')->orderBy('id')->get(['id','sale_id','amount','status','payment_method']) as $p) echo "P{$p->id} sale={$p->sale_id} amt={$p->amount} {$p->status} {$p->payment_method}\n";
echo 'reversals='.DB::table('pos_owner_payment_transfer_reversals')->count()."\n";
