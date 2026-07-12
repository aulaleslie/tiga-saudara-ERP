<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Services\TransferAllocationPreviewService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferStockTransactionTest extends TestCase
{
    use RefreshDatabase;
    private $setting;
    private $originLocation;
    private $destinationLocation;
    private $product;

    public function setUp(): void
    {
        parent::setUp();
        
        // Create unique tenant data for each test
        [$this->setting, $this->originLocation] = $this->createTenantData('Tenant A');
        [$settingB, $this->destinationLocation] = $this->createTenantData('Tenant B');
        
        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product ' . uniqid(),
            'product_code' => 'TP-' . uniqid(),
            'serial_number_required' => 0,
            'product_cost' => 50000,
            'product_price' => 100000,
            'stock_managed' => true,
        ]);
    }

    /** @test */
    public function scanner_allocation_splits_across_non_tax_and_tax_buckets()
    {
        // Setup: non-tax stock is limited
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 3,
            'quantity_tax' => 10,
            'quantity' => 13,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $service = app(TransferAllocationPreviewService::class);
        
        // When requesting 5 units with only 3 non-tax available
        $allocation = $service->previewAllocation($stock, 5, false);

        // Should allocate 3 non-tax + 2 tax (spillover)
        $this->assertEquals(3, $allocation->allocatedNonTax);
        $this->assertEquals(2, $allocation->allocatedTax);
        $this->assertFalse($allocation->isInsufficient);
        $this->assertEquals(2, $allocation->mandatoryReturnQuantity);
    }

    /** @test */
    public function history_records_completion_action()
    {
        // Test that actual receipt workflow generates completion history
        $user = User::factory()->create(['is_active' => 1]);

        // Create stock at origin
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'quantity' => 10,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // For same-tenant transfers, both locations must be in the same setting
        // Create destination in same setting
        $sameSettingDest = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Same Setting Destination',
        ]);

        // Create transfer with draft service
        $lifecycleService = app(\Modules\Adjustment\Services\TransferLifecycleService::class);
        $transfer = $lifecycleService->createPending(
            $this->originLocation->id,
            $sameSettingDest->id,
            [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'quantities' => [
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 5,
                        'quantity_broken_tax' => 0,
                        'quantity_broken_non_tax' => 0,
                    ],
                    'serial_numbers' => null,
                ]
            ],
            $user->id
        );

        // Approve the transfer from origin setting
        $transfer = $lifecycleService->approve($transfer, $user->id, $this->setting->id);

        // Dispatch the transfer from origin setting
        $transfer = $lifecycleService->dispatch($transfer, $user->id, $this->setting->id);

        // Receive the transfer from destination setting (same setting, so still use $this->setting->id)
        $transfer = $lifecycleService->receive($transfer, $user->id, $this->setting->id);

        // Verify the receipt action was recorded
        $history = $transfer->refresh()->actionHistories()
            ->where('action', TransferActionHistory::ACTION_RECEIVED)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals(Transfer::STATUS_COMPLETED, $history->to_status);
        $this->assertEquals(TransferActionHistory::ACTION_RECEIVED, $history->action);
    }

    /** @test */
    public function http_create_preserves_pending_status()
    {
        // Verify HTTP transfer creation validates stock and creates in proper state
        $user = User::factory()->create(['is_active' => 1]);

        // Grant stockTransfers.create permission
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'stockTransfers.create']);
        $user->givePermissionTo($permission);

        // Set session setting on user
        $user->update(['setting_id' => $this->setting->id]);

        // Set the active tenant in the HTTP test session
        $this->withSession(['setting_id' => $this->setting->id]);

        // Setup required stock
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 5,
            'quantity_tax' => 5,
            'quantity' => 10,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $this->actingAs($user);

        // Send HTTP POST to create transfer (production creation atomically submits to PENDING)
        $response = $this->post(route('transfers.store'), [
            'origin_location' => $this->originLocation->id,
            'destination_location' => $this->destinationLocation->id,
            'product_ids' => [$this->product->id],
            'quantities' => [5],
        ]);

        // Should redirect to the new transfer
        $this->assertTrue($response->status() >= 300 && $response->status() < 400, "Expected redirect but got {$response->status()}");
        $transfer = Transfer::whereStatus(Transfer::STATUS_PENDING)
            ->where('created_by', $user->id)
            ->latest()
            ->first();

        $this->assertNotNull($transfer);
        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);
    }

    /** @test */
    public function revision_increments_on_update()
    {
        $user = User::factory()->create(['is_active' => 1]);

        // Create stock
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 5,
            'quantity' => 15,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Create transfer with draft service
        $draftService = app(\Modules\Adjustment\Services\TransferDraftService::class);
        $formState = new \Modules\Adjustment\DTOs\TransferFormState();
        $formState->originLocationId = $this->originLocation->id;
        $formState->destinationLocationId = $this->destinationLocation->id;
        $formState->lines = [];

        $transfer = $draftService->saveDraft($formState, $user, $this->setting->id);

        $this->assertEquals(1, $transfer->revision);

        // Update transfer through draft service (should increment revision)
        $formState->lines = [
            (object) [
                'productId' => $this->product->id,
                'requestedBaseQuantity' => 5,
                'isBrokenMode' => false,
                'isSerialNumberRequired' => false,
                'selectedSerials' => [],
            ]
        ];

        $updatedTransfer = $draftService->saveDraft($formState, $user, $this->setting->id, $transfer);
        
        // Revision should increment
        $this->assertGreaterThan(1, $updatedTransfer->revision);
    }

    /** @test */
    public function allocation_preview_detects_insufficient_stock()
    {
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 2,
            'quantity_tax' => 1,
            'quantity' => 3,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $service = app(TransferAllocationPreviewService::class);
        
        // Request more than available
        $allocation = $service->previewAllocation($stock, 10, false);

        $this->assertEquals(2, $allocation->allocatedNonTax);
        $this->assertEquals(1, $allocation->allocatedTax);
        $this->assertTrue($allocation->isInsufficient);
    }

    /** @test */
    public function broken_stock_allocation_works()
    {
        $stock = ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->originLocation->id,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'quantity' => 0,
            'broken_quantity' => 8,
            'broken_quantity_non_tax' => 3,
            'broken_quantity_tax' => 5,
        ]);

        $service = app(TransferAllocationPreviewService::class);
        
        // Allocate broken stock
        $allocation = $service->previewAllocation($stock, 5, true);

        $this->assertEquals(3, $allocation->allocatedNonTax); // all non-tax broken
        $this->assertEquals(2, $allocation->allocatedTax); // partial tax broken
        $this->assertFalse($allocation->isInsufficient);
    }

    protected function createTenantData(string $companyName): array
    {
        $uniqueId = uniqid();
        $currency = Currency::create([
            'currency_name' => 'Rupiah ' . $uniqueId,
            'code' => 'IDR' . substr($uniqueId, -2),
            'symbol' => 'RP',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => $companyName . ' ' . $uniqueId,
            'company_email' => strtolower(str_replace(' ', '', $companyName)) . $uniqueId . '@test.com',
            'company_phone' => '0800000000' . substr($uniqueId, 0, 3),
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => strtolower(str_replace(' ', '', $companyName)) . $uniqueId . '@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Warehouse ' . $companyName . ' ' . $uniqueId,
        ]);

        return [$setting, $location];
    }

    protected function loginAsUser()
    {
        $user = \App\Models\User::factory()->create();
        return $this->actingAs($user);
    }
}

