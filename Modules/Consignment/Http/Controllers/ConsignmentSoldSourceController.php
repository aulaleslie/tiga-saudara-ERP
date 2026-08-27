<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentReturnEligibilityService;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class ConsignmentSoldSourceController extends Controller
{
    protected ConsignmentSoldSourceDiscoveryService $discoveryService;
    protected ConsignmentReturnEligibilityService $eligibilityService;

    public function __construct(
        ConsignmentSoldSourceDiscoveryService $discoveryService,
        ConsignmentReturnEligibilityService $eligibilityService
    ) {
        $this->discoveryService = $discoveryService;
        $this->eligibilityService = $eligibilityService;
    }

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentSoldSource::forSetting($settingId)
            ->with(['sale', 'product', 'location', 'serials.serialNumber']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->filled('has_blocker')) {
            $query->where('has_reconstruction_blocker', (bool) $request->has_blocker);
        }

        $sources = $query->latest('id')->paginate(25)->withQueryString();

        // Calculate eligibility for each source
        foreach ($sources as $source) {
            $source->eligibility = $this->eligibilityService->calculateSoldEligibility($source);
        }

        $products = Product::active()->where('stock_managed', true)->orderBy('product_name')->get();
        $locations = Location::where('setting_id', $settingId)->consignment()->get();

        return view('consignment::sold_sources.index', compact('sources', 'products', 'locations'));
    }

    public function discover(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);
        $settingId = (int) session('setting_id');

        $previewOnly = $request->boolean('preview');
        $result = $this->discoveryService->discoverForSetting($settingId, $previewOnly);

        $msg = "Discovery completed: {$result['created']} created, {$result['existing']} existing, {$result['excluded']} excluded, {$result['blocked']} blocked.";
        toast($msg, 'info');

        return redirect()->route('consignments.sold-sources.index');
    }
}
