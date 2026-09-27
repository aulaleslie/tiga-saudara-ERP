<?php

namespace Modules\Product\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBarcodeAssignment;
use Modules\Product\Utils\BarcodeUtils;

class ProductBarcodeAssignmentService
{
    protected BarcodeIdentityService $identityService;

    public function __construct(BarcodeIdentityService $identityService)
    {
        $this->identityService = $identityService;
    }

    /**
     * Assigns a new barcode to a product, tracking the assignment in history
     * and ensuring cross-namespace uniqueness.
     *
     * @param int $productId
     * @param string $newBarcode
     * @param string|null $oldBarcodeSnapshot
     * @param User $actor
     * @return array
     */
    public function assign(int $productId, string $newBarcode, ?string $oldBarcodeSnapshot, User $actor): array
    {
        if (!$actor->can('products.barcodes.manage')) {
            return ['success' => false, 'error' => 'unauthorized'];
        }

        try {
            return DB::transaction(function () use ($productId, $newBarcode, $oldBarcodeSnapshot, $actor) {
                $product = Product::lockForUpdate()->find($productId);

                if (!$product) {
                    return ['success' => false, 'error' => 'not_found'];
                }

                $currentBarcode = $product->barcode;

                // Stale value protection
                $canonicalCurrent = BarcodeUtils::canonicalize($currentBarcode);
                $canonicalSnapshot = BarcodeUtils::canonicalize($oldBarcodeSnapshot);

                if ($canonicalCurrent !== $canonicalSnapshot) {
                    return [
                        'success' => false,
                        'error' => 'stale_state',
                        'current_barcode' => $currentBarcode,
                    ];
                }

                // No-op detection
                $canonicalNew = BarcodeUtils::canonicalize($newBarcode);
                if (!$canonicalNew || strlen($newBarcode) > 255) {
                    return ['success' => false, 'error' => 'invalid_barcode'];
                }

                if ($canonicalCurrent === $canonicalNew) {
                    return ['success' => true, 'status' => 'no_op'];
                }

                // Reserve in identity registry
                $reservation = $this->identityService->replace(
                    $currentBarcode ?? '',
                    $newBarcode,
                    $product->id,
                    null
                );

                if (!$reservation['success']) {
                    return $reservation; // Returns duplicate error with conflict context
                }

                // Update product
                $product->barcode = $newBarcode;
                try {
                    $product->save();
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'constraint')) {
                        throw new \Exception('duplicate_barcode_constraint');
                    }
                    throw $e;
                }

                // Record assignment history
                ProductBarcodeAssignment::create([
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'old_barcode' => $currentBarcode,
                    'new_barcode' => $newBarcode,
                    'action' => $currentBarcode ? ProductBarcodeAssignment::ACTION_REPLACE : ProductBarcodeAssignment::ACTION_INITIALIZE,
                    'actor_id' => $actor->id,
                ]);

                return ['success' => true, 'status' => 'assigned', 'product' => $product];
            });
        } catch (\Exception $e) {
            if ($e->getMessage() === 'duplicate_barcode_constraint') {
                return ['success' => false, 'error' => 'duplicate', 'conflict' => ['type' => 'unknown']];
            }
            throw $e;
        }
    }
}
