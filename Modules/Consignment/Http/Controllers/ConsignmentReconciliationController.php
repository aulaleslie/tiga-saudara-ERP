<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class ConsignmentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentReceivingDetail::with([
            'consignmentReceiving.receival.supplier',
            'consignmentReceiving.location',
            'product.baseUnit',
            'serialNumbers',
            'transaction',
            'reversalTransaction',
            'receiptAllocations.line.confirmation.purchase.purchasePayments',
            'receiptAllocations.line.soldSource.dispatchDetail.sale.checkoutSale.checkout.transaction',
            'receiptAllocations.line.soldSource.dispatchDetail.sale.posCheckout.transaction',
        ])
        ->whereHas('consignmentReceiving', function ($q) use ($settingId, $request) {
            $q->where('setting_id', $settingId);

            if ($request->filled('status')) {
                $q->where('status', $request->status);
            } else {
                $q->whereIn('status', ['APPROVED', 'REVERSED']);
            }

            if ($request->filled('supplier_id')) {
                $q->whereHas('receival', fn ($r) => $r->where('supplier_id', $request->supplier_id));
            }

            if ($request->filled('location_id')) {
                $q->where('location_id', $request->location_id);
            }
        });

        if ($request->filled('billing_status')) {
            if ($request->billing_status === 'READY') {
                $query->whereHas('receiptAllocations.line.confirmation', function ($q) {
                    $q->where('status', 'APPROVED')->where('is_ready_for_billing', true)->whereNull('purchase_id');
                });
            } elseif ($request->billing_status === 'BILLED') {
                $query->whereHas('receiptAllocations.line.confirmation', function ($q) {
                    $q->whereNotNull('purchase_id');
                });
            }
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('serial_number')) {
            $query->whereHas('serialNumbers', function ($q) use ($request) {
                $q->where('serial_number', 'like', '%' . $request->serial_number . '%');
            });
        }

        if ($request->filled('transaction_reference')) {
            $query->whereHas('receiptAllocations.line.soldSource.dispatchDetail.sale', function ($sale) use ($request) {
                $sale->where('reference', 'like', '%' . $request->transaction_reference . '%')
                     ->orWhereHas('checkoutSale.checkout.transaction', fn($pos) => $pos->where('code', 'like', '%' . $request->transaction_reference . '%'))
                     ->orWhereHas('posCheckout.transaction', fn($pos) => $pos->where('code', 'like', '%' . $request->transaction_reference . '%'));
            });
        }

        if ($request->filled('confirmation_status')) {
            $query->whereHas('receiptAllocations.line.confirmation', function ($q) use ($request) {
                $q->where('status', $request->confirmation_status);
            });
        }

        $details = $query->latest('id')->paginate(25)->withQueryString();
        
        $blockerQuery = \Modules\Consignment\Entities\ConsignmentSoldSource::with(['dispatchDetail.sale.checkoutSale.checkout.transaction', 'dispatchDetail.sale.posCheckout.transaction', 'dispatchDetail.product'])
            ->where('setting_id', $settingId)
            ->where('has_reconstruction_blocker', true);
            
        if ($request->filled('product_id')) {
            $blockerQuery->whereHas('dispatchDetail', fn($q) => $q->where('product_id', $request->product_id));
        }
        if ($request->filled('transaction_reference')) {
            $blockerQuery->whereHas('dispatchDetail.sale', function ($sale) use ($request) {
                $sale->where('reference', 'like', '%' . $request->transaction_reference . '%')
                     ->orWhereHas('checkoutSale.checkout.transaction', fn($pos) => $pos->where('code', 'like', '%' . $request->transaction_reference . '%'))
                     ->orWhereHas('posCheckout.transaction', fn($pos) => $pos->where('code', 'like', '%' . $request->transaction_reference . '%'));
            });
        }
        
        $blockers = $blockerQuery->latest('id')->get();

        $eligibilityService = app(\Modules\Consignment\Services\ConsignmentReturnEligibilityService::class);
        $returnedQuantities = [];
        
        foreach ($details as $d) {
            foreach ($d->receiptAllocations as $ra) {
                if ($ra->line && $ra->line->soldSource) {
                    $ddId = $ra->line->soldSource->dispatch_detail_id;
                    if (!isset($returnedQuantities[$ddId])) {
                        $returnedQuantities[$ddId] = $eligibilityService->getEffectiveReturnQuantity($ddId);
                    }
                }
            }
        }

        // Suppliers and products are shared master data: not scoped by setting.
        // Locations stay setting-scoped as transactional infrastructure.
        $suppliers = Supplier::orderBy('supplier_name')->get();
        $locations = Location::where('setting_id', $settingId)->consignment()->get();
        $products = Product::active()
            ->where('stock_managed', true)
            ->orderBy('product_name')
            ->get();

        return view('consignment::reconciliation.index', compact('details', 'suppliers', 'locations', 'products', 'returnedQuantities', 'blockers'));
    }
}
