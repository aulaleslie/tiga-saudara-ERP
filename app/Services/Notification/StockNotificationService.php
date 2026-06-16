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

    public function checkLocationStock(ProductStock $stock, float $previousQuantity, float $currentQuantity): void
    {
        $product = $stock->product;
        if (!$product) {
            return;
        }

        $alert = (float) $product->product_stock_alert;

        if ($currentQuantity <= $alert && $previousQuantity > $alert) {
            $this->createLocationStockNotifications($stock);
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

    public function createLocationStockNotifications(ProductStock $stock): void
    {
        $product = $stock->product;
        $recipients = $this->permissionResolver->getLowStockRecipients($product->setting_id);
        
        $locationName = $stock->location->location_name ?? 'Lokasi';

        foreach ($recipients as $user) {
            $this->notificationService->write([
                'user_id' => $user->id,
                'setting_id' => $product->setting_id,
                'location_id' => $stock->location_id,
                'category' => 'stock',
                'type' => 'location_low_stock',
                'title' => 'Stok Lokasi Menipis',
                'message' => "Stok untuk produk {$product->product_name} di {$locationName} menipis ({$stock->quantity} / {$product->product_stock_alert}).",
                'source_type' => ProductStock::class,
                'source_id' => $stock->id,
                'fingerprint' => "stock:location:{$stock->id}:user:{$user->id}",
                'action_url' => route('products.show', $product->id),
                'metadata' => [
                    'current_quantity' => $stock->quantity,
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
