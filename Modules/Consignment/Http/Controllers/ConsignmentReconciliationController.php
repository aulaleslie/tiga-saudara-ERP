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
        abort_if(Gate::denies('consignments.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentReceivingDetail::with([
            'consignmentReceiving.receival.supplier',
            'consignmentReceiving.location',
            'product.baseUnit',
            'serialNumbers',
            'transaction',
            'reversalTransaction',
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

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $details = $query->latest('id')->paginate(25)->withQueryString();

        $suppliers = Supplier::orderBy('supplier_name')->get();
        $locations = Location::where('setting_id', $settingId)->consignment()->get();
        $products = Product::active()->where('stock_managed', true)->orderBy('product_name')->get();

        return view('consignment::reconciliation.index', compact('details', 'suppliers', 'locations', 'products'));
    }
}
