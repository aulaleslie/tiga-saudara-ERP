<?php

namespace App\Services\Notification;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;

class StockNotificationService
{
    protected NotificationService $notificationService;
    protected PermissionResolver $permissionResolver;

    public function __construct(NotificationService $notificationService, PermissionResolver $permissionResolver)
    {
        $this->notificationService = $notificationService;
        $this->permissionResolver = $permissionResolver;
    }

    public function checkGlobalStock(Product $product, float $previousQuantity, float $currentQuantity): void
    {
        $alert = (float) $product->product_stock_alert;

        if ($currentQuantity <= $alert && $previousQuantity > $alert) {
            $this->createGlobalStockNotifications($product);
        } elseif ($currentQuantity > $alert && $previousQuantity <= $alert) {
            $this->resolveGlobalStockNotifications($product);
        }
    }

    /**
     * @param float $previousQuantity The quantity the threshold decision is based on, BEFORE the change.
     * @param float $currentQuantity The quantity the threshold decision is based on, AFTER the change.
     *   For most callers this is the same figure as $stock->quantity (physical
     *   good+broken total). Breakage intentionally holds $stock->quantity
     *   invariant and instead passes the SELLABLE (good-only) quantity here,
     *   so the displayed "current / threshold" figure must reflect that same
     *   sellable quantity, not $stock->quantity -- otherwise a low-stock
     *   notification can render an unrelated, always-above-threshold physical
     *   total next to the alert.
     */
    public function checkLocationStock(ProductStock $stock, float $previousQuantity, float $currentQuantity): void
    {
        $product = $stock->product;
        if (!$product) {
            return;
        }

        $alert = (float) $product->product_stock_alert;

        if ($currentQuantity <= $alert && $previousQuantity > $alert) {
            $this->createLocationStockNotifications($stock, $currentQuantity);
        } elseif ($currentQuantity > $alert && $previousQuantity <= $alert) {
            $this->resolveLocationStockNotifications($stock);
        }
    }

    public function createGlobalStockNotifications(Product $product): void
    {
        $recipients = $this->permissionResolver->getLowStockRecipients($product->setting_id);

        foreach ($recipients as $user) {
            $this->notificationService->write([
                'user_id' => $user->id,
                'setting_id' => $product->setting_id,
                'location_id' => null,
                'category' => 'stock',
                'type' => 'global_low_stock',
                'title' => 'Stok Global Menipis',
                'message' => "Stok untuk produk {$product->product_name} menipis secara global ({$product->product_quantity} / {$product->product_stock_alert}).",
                'source_type' => Product::class,
                'source_id' => $product->id,
                'fingerprint' => "stock:global:{$product->id}:user:{$user->id}",
                'action_url' => route('products.show', $product->id),
                'metadata' => [
                    'current_quantity' => $product->product_quantity,
                    'threshold' => $product->product_stock_alert,
                ]
            ]);
        }
    }

    public function resolveGlobalStockNotifications(Product $product): void
    {
        $this->notificationService->resolveBySource(
            'stock',
            Product::class,
            $product->id,
            $product->setting_id
        );
    }

    /**
     * @param float|null $currentQuantity The quantity that actually crossed
     *   the threshold (per checkLocationStock's doc comment). Defaults to
     *   $stock->quantity for callers that check against the physical total,
     *   preserving their existing behavior.
     */
    public function createLocationStockNotifications(ProductStock $stock, ?float $currentQuantity = null): void
    {
        $product = $stock->product;
        $recipients = $this->permissionResolver->getLowStockRecipients($product->setting_id);
        $displayQuantity = $currentQuantity ?? (float) $stock->quantity;

        $locationName = $stock->location->location_name ?? 'Lokasi';

        foreach ($recipients as $user) {
            $this->notificationService->write([
                'user_id' => $user->id,
                'setting_id' => $product->setting_id,
                'location_id' => $stock->location_id,
                'category' => 'stock',
                'type' => 'location_low_stock',
                'title' => 'Stok Lokasi Menipis',
                'message' => "Stok untuk produk {$product->product_name} di {$locationName} menipis ({$displayQuantity} / {$product->product_stock_alert}).",
                'source_type' => ProductStock::class,
                'source_id' => $stock->id,
                'fingerprint' => "stock:location:{$stock->id}:user:{$user->id}",
                'action_url' => route('products.show', $product->id),
                'metadata' => [
                    'current_quantity' => $displayQuantity,
                    'threshold' => $product->product_stock_alert,
                    'location_name' => $locationName,
                ]
            ]);
        }
    }

    public function resolveLocationStockNotifications(ProductStock $stock): void
    {
        $this->notificationService->resolveBySource(
            'stock',
            ProductStock::class,
            $stock->id,
            $stock->product->setting_id,
            $stock->location_id
        );
    }
}
