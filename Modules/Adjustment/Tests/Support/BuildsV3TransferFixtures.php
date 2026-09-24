<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Support;

use App\Models\User;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3AllocationService;
use Modules\Adjustment\Services\TransferV3DispatchExecutor;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;

/**
 * Two businesses (A non-PKP, B PKP) with two locations in A and one in B,
 * a non-serialized product stocked at A1/A2 and a serialized product with
 * serials at A1 and B1.
 */
trait BuildsV3TransferFixtures
{
    protected User $actor;
    protected Setting $businessA;
    protected Setting $businessB;
    protected Location $a1;
    protected Location $a2;
    protected Location $b1;
    protected Product $bulk;
    protected Product $serialized;
    protected Tax $tax;
    /** @var array<int, ProductSerialNumber> */
    protected array $serials = [];

    protected const PERMISSIONS = [
        'stockTransfers.access',
        'stockTransfers.show',
        'stockTransfers.create',
        'stockTransfers.edit',
        'stockTransfers.approval',
        'stockTransfers.receive',
        'stockTransfers.receive.approval',
        'stockTransfers.cancel-dispatch',
        'stockTransfers.view-history',
        'stockTransfers.view-system-stock',
        'stockTransfers.dispatch',
        'stockTransfers.dispatch.create',
        'stockTransfers.dispatch.approval',
        'stockTransfers.archive',
    ];

    protected function buildV3Fixtures(): void
    {
        config(['stock_transfers.v3_creation_enabled' => true]);

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->actor = User::factory()->create();
        $this->businessA = Setting::factory()->create(['company_name' => 'Bisnis A', 'is_pkp' => false]);
        $this->businessB = Setting::factory()->create(['company_name' => 'Bisnis B', 'is_pkp' => true]);

        $this->a1 = Location::create(['setting_id' => $this->businessA->id, 'name' => 'Gudang A1', 'is_active' => true]);
        $this->a2 = Location::create(['setting_id' => $this->businessA->id, 'name' => 'Gudang A2', 'is_active' => true]);
        $this->b1 = Location::create(['setting_id' => $this->businessB->id, 'name' => 'Gudang B1', 'is_active' => true]);

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true, 'is_active' => true]);

        $this->bulk = Product::create([
            'setting_id' => $this->businessA->id,
            'product_name' => 'Kabel Curah',
            'product_code' => 'BULK-' . uniqid(),
            'product_quantity' => 15,
            'broken_quantity' => 2,
            'product_cost' => 1000,
            'product_price' => 1500,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        $this->serialized = Product::create([
            'setting_id' => $this->businessA->id,
            'product_name' => 'Laptop Serial',
            'product_code' => 'SER-' . uniqid(),
            'product_quantity' => 3,
            'product_cost' => 1000,
            'product_price' => 1500,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);

        $this->stock($this->bulk, $this->a1, nonTax: 6, tax: 4, brokenNonTax: 2);
        $this->stock($this->bulk, $this->a2, nonTax: 5);
        $this->stock($this->serialized, $this->a1, nonTax: 1, tax: 1);
        $this->stock($this->serialized, $this->b1, tax: 1);

        $this->serials[] = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->a1->id, 'serial_number' => 'SN-A1-1', 'tax_id' => null]);
        $this->serials[] = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->a1->id, 'serial_number' => 'SN-A1-2', 'tax_id' => $this->tax->id]);
        $this->serials[] = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->b1->id, 'serial_number' => 'SN-B1-1', 'tax_id' => $this->tax->id]);

        session(['setting_id' => $this->businessA->id]);
    }

    protected function grant(User $user, array $permissions): void
    {
        $user->givePermissionTo($permissions);
    }

    protected function stock(Product $product, Location $location, int $nonTax = 0, int $tax = 0, int $brokenNonTax = 0, int $brokenTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $nonTax + $tax + $brokenNonTax + $brokenTax,
            'quantity_non_tax' => $nonTax,
            'quantity_tax' => $tax,
            'broken_quantity' => $brokenNonTax + $brokenTax,
            'broken_quantity_non_tax' => $brokenNonTax,
            'broken_quantity_tax' => $brokenTax,
        ]);
    }

    protected function stockAt(Product $product, Location $location): ?ProductStock
    {
        return ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
    }

    protected function goodsLines(int $bulkQuantity = 10, bool $withSerials = true): array
    {
        $lines = [['product_id' => $this->bulk->id, 'quantity' => $bulkQuantity, 'serial_ids' => []]];

        if ($withSerials) {
            $lines[] = [
                'product_id' => $this->serialized->id,
                'quantity' => 3,
                'serial_ids' => array_map(fn ($serial) => $serial->id, $this->serials),
            ];
        }

        return $lines;
    }

    protected function submittedTransfer(int $bulkQuantity = 10, bool $withSerials = true): Transfer
    {
        return app(TransferV3GoodsService::class)->createAndSubmit(
            Transfer::CONDITION_GOOD,
            $this->goodsLines($bulkQuantity, $withSerials),
            $this->actor,
            $this->businessA->id
        );
    }

    /**
     * Bulk: 6 from A1 -> B1 and 4 from A2 -> A1. Serials: A1 group -> A2, B1 group -> A1.
     */
    protected function saveCompletePlan(Transfer $transfer, ?array $rows = null, ?array $serialDestinations = null): Transfer
    {
        $transfer->refresh();

        return app(TransferV3AllocationService::class)->saveProgress(
            $transfer,
            $this->actor,
            $this->businessA->id,
            (int) $transfer->current_request_revision_id,
            (int) $transfer->approval_configuration_revision,
            $serialDestinations ?? [
                "{$this->serialized->id}:{$this->a1->id}" => $this->a2->id,
                "{$this->serialized->id}:{$this->b1->id}" => $this->a1->id,
            ],
            $rows ?? [
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
                ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 4],
            ]
        );
    }

    protected function dispatchTransfer(Transfer $transfer, ?string $operationKey = null): Transfer
    {
        $transfer->refresh();

        return app(TransferV3DispatchExecutor::class)->approveAndDispatch(
            $transfer,
            $this->actor,
            $this->businessA->id,
            (int) $transfer->current_request_revision_id,
            (int) $transfer->approval_configuration_revision,
            $operationKey
        );
    }
}
