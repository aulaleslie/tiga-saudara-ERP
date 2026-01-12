<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;

class PurchasesReturnSettlementController extends Controller
{
    public function store(Request $request, PurchaseReturn $purchaseReturn)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function submit(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function approve(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.approve'), 403);
        // TODO: Implement logic
    }

    public function reject(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.approve'), 403);
        // TODO: Implement logic
    }

    public function execute(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.execute'), 403);
        // TODO: Implement logic
    }

    public function dispatchStock(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.dispatch'), 403);
        // TODO: Implement logic
    }

    public function receiveStock(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.receive'), 403);
        // TODO: Implement logic
    }
}
