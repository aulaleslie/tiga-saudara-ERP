<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Modules\Product\Entities\Product;
use Modules\Product\Services\SerialConversionEligibilityService;
use Modules\Product\Services\SerialConversionExecutionService;
use Modules\Product\Services\SerialConversionPoolAggregator;
use Modules\Product\Services\SerialConversionValidationService;

class SerialConversionController extends Controller
{
    public function __construct(
        protected SerialConversionEligibilityService $eligibilityService,
        protected SerialConversionPoolAggregator $poolAggregator,
        protected SerialConversionValidationService $validationService,
        protected SerialConversionExecutionService $executionService
    ) {}

    /**
     * Display the conversion page for an eligible product or search.
     */
    public function show(Request $request, ?Product $product = null): View|RedirectResponse
    {
        abort_if(Gate::denies('products.convert_existing_stock_to_serialized'), 403);

        if (! $product && $request->has('product_id')) {
            $product = Product::find($request->input('product_id'));
        }

        if (! $product) {
            return view('product::products.convert-to-serialized', [
                'product' => null,
                'eligibility' => null,
                'pools' => [],
            ]);
        }

        $eligibility = $this->eligibilityService->checkEligibility($product);

        $stocks = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
            ->with(['location.setting'])
            ->get();

        $pools = $this->poolAggregator->aggregate($stocks);

        return view('product::products.convert-to-serialized', [
            'product' => $product,
            'eligibility' => $eligibility,
            'pools' => $pools,
        ]);
    }

    /**
     * AJAX endpoint to validate a scanned serial.
     */
    public function validateScan(Request $request): JsonResponse
    {
        abort_if(Gate::denies('products.convert_existing_stock_to_serialized'), 403);

        $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
            'session_serials' => ['nullable', 'array'],
            'session_serials.*' => ['string', 'max:255'],
        ]);

        $result = $this->validationService->validateSerial(
            (string) $request->input('serial_number'),
            (array) $request->input('session_serials', [])
        );

        if (! $result['valid']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    /**
     * Handle the final conversion submission.
     */
    public function convert(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        abort_if(Gate::denies('products.convert_existing_stock_to_serialized'), 403);

        $validated = $request->validate([
            'expected_pools' => ['required', 'array'],
            'scanned_serials' => ['required', 'array'],
        ]);

        $result = $this->executionService->executeConversion(
            $product,
            (array) $validated['expected_pools'],
            (array) $validated['scanned_serials']
        );

        if ($request->wantsJson()) {
            if (! $result['success']) {
                return response()->json($result, 422);
            }

            return response()->json($result);
        }

        if (! $result['success']) {
            return redirect()->back()
                ->withErrors(['conversion' => $result['message']])
                ->withInput();
        }

        return redirect()->route('products.show', $product->id)
            ->with('success', $result['message']);
    }
}
