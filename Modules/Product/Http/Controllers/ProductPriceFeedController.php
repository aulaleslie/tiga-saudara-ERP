<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\Services\ProductPriceFeedQueryService;

class ProductPriceFeedController extends Controller
{
    public function __construct(
        private readonly ProductPriceFeedQueryService $feedService = new ProductPriceFeedQueryService()
    ) {
    }

    /**
     * Display the full update history page.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'setting_id' => 'nullable|integer|exists:settings,id',
            'event_type' => 'nullable|string|in:product_created,product_price_updated,bundle_created,bundle_price_updated',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $user = auth()->user();
        $filters = [
            'search' => $validated['search'] ?? null,
            'setting_id' => $validated['setting_id'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];

        $events = $this->feedService->getFeedEvents($user, $filters, 20);
        $businesses = $this->feedService->getVisibleBusinesses($user);

        return view('product::feed.index', [
            'events' => $events,
            'businesses' => $businesses,
            'filters' => $filters,
        ]);
    }

    /**
     * Return event detail JSON for modal display.
     */
    public function show(int $id): JsonResponse
    {
        $user = auth()->user();
        $detail = $this->feedService->getEventDetail($user, $id);

        if (! $detail) {
            return response()->json(['message' => 'Event not found or access denied.'], 404);
        }

        return response()->json($detail);
    }
}
