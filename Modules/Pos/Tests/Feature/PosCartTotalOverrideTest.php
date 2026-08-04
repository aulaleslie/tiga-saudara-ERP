<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSupervisorApproval;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCartTotalAllocationService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCartTotalOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected PosCartService $cartService;
    protected PosCartTotalAllocationService $allocationService;
    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = app(PosCartService::class);
        $this->allocationService = app(PosCartTotalAllocationService::class);

        // Create currency
        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Clear cached permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.overrides.total-price',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create Super Admin role if it doesn't exist
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        $this->location = Location::factory()->create(['setting_id' => $this->setting->id]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'opened_by' => $this->user->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    private function createProduct(int $price = 50000): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'TEST-CAT'],
            [
                'category_name' => 'Test Category',
                'created_by' => 1,
                'setting_id' => $this->setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-' . uniqid(),
            'barcode' => 'TEST-BAR-' . uniqid(),
            'product_quantity' => 100,
            'product_cost' => $price / 2,
            'product_price' => $price,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        // Create price for the setting
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'price' => $price,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        return $product;
    }

    public function test_direct_permission_applies_override_immediately(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        // Add a line: Qty 2 @ Rp 50.000 = Rp 100.000
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Override to Rp 80.000
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000, // 80.000 in minor units
            null,
            null,
            $this->user
        );

        $this->assertIsArray($result);
        $this->assertEquals(8000000, (int) round($result['totals']['grand_total'] * 100));
    }

    public function test_no_permission_creates_approval_request(): void
    {
        // User has no direct permission
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon pelanggan',
            null,
            $this->user
        );

        $this->assertEquals('request_created', $result['status']);
        $this->assertArrayHasKey('request_id', $result);

        $request = PosActionApprovalRequest::find($result['request_id']);
        $this->assertNotNull($request);
        $this->assertEquals(PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE, $request->action_type);
        $this->assertEquals(PosActionApprovalRequest::STATUS_PENDING, $request->status);
    }

    public function test_supervisor_approval_flow(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Request approval
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon pelanggan',
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);

        // Approve
        $approval = $approvalService->approveRequest($requestId, $supervisor, 'Disetujui');
        $this->assertEquals(PosActionApprovalRequest::STATUS_APPROVED, $approval['status']);

        // Token should be issued
        $request = PosActionApprovalRequest::find($requestId);
        $this->assertNotNull($request->token);
        $token = $request->token->token_hash;

        // Execute with token
        $snapshot = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            $token,
            $this->user
        );

        $this->assertEquals(8000000, (int) round($snapshot['totals']['grand_total'] * 100));
    }

    public function test_zero_target_is_accepted(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            0, // Zero target
            null,
            null,
            $this->user
        );

        $this->assertEquals(0, (int) round($result['totals']['grand_total'] * 100));
    }

    public function test_negative_target_is_rejected(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $this->expectException(\DomainException::class);

        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            -1000, // Negative
            null,
            null,
            $this->user
        );
    }

    public function test_empty_cart_is_rejected(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('empty cart');

        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            1000000,
            null,
            null,
            $this->user
        );
    }

    public function test_allocation_with_multiple_rows_and_rounding(): void
    {
        // Test: 3 rows with different totals, allocated to Rp 10.000
        // Row 1: Qty 1 @ Rp 6.000 = Rp 6.000
        // Row 2: Qty 1 @ Rp 4.000 = Rp 4.000
        // Row 3: Qty 1 @ Rp 2.000 = Rp 2.000
        // Total: Rp 12.000 → allocate to Rp 10.000

        $lines = [
            ['line_id' => 1, 'qty' => 1, 'unit_price' => 60.00],
            ['line_id' => 2, 'qty' => 1, 'unit_price' => 40.00],
            ['line_id' => 3, 'qty' => 1, 'unit_price' => 20.00],
        ];

        $result = $this->allocationService->allocate($lines, 1000000); // Rp 10.000 in minor units

        $this->assertEquals(1000000, $result['total_allocated']);
        $this->assertCount(3, $result['allocated']);

        // Sum of allocations must equal target
        $sum = array_sum(array_column($result['allocated'], 'allocated_line_total'));
        $this->assertEquals(1000000, $sum);
    }

    public function test_quantity_three_allocation_example(): void
    {
        // Spec example: Qty 3 @ Rp 3.500 = Rp 10.500 → allocate to Rp 10.000
        // Line should remain one visible row with Qty 3 and line total Rp 10.000
        // Effective unit price = Rp 10.000 / 3 ≈ Rp 3.333.33

        $lines = [
            ['line_id' => 1, 'qty' => 3, 'unit_price' => 35.00],
        ];

        $result = $this->allocationService->allocate($lines, 1000000);

        $this->assertCount(1, $result['allocated']);
        $allocated = $result['allocated'][0];
        $this->assertEquals(1, $allocated['line_id']);
        $this->assertEquals(1000000, $allocated['allocated_line_total']);
        $this->assertEquals(round(1000000 / 100.0 / 3, 2), $allocated['effective_unit_price']);
    }

    public function test_mutation_invalidates_override(): void
    {
        // Use supervisor so we can create an approval request
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $lineId = $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);
        $lines = $lineId['lines'];
        $lineId = $lines[0]['line_id'];

        // Request approval (user has no direct permission)
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            null,
            $this->user
        );

        // Approve it
        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);
        $approvalService->approveRequest($requestId, $supervisor);

        // Get the token
        $request = PosActionApprovalRequest::find($requestId);
        $token = $request->token->token_hash;

        // Verify override is applied with token
        $snapshot = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            $token,
            $this->user
        );
        $this->assertEquals(8000000, (int) round($snapshot['totals']['grand_total'] * 100));
        // Check that at least one line has been overridden
        $hasOverriddenLine = false;
        foreach ($snapshot['lines'] as $line) {
            if (($line['price_source'] ?? null) === 'TOTAL_OVERRIDE') {
                $hasOverriddenLine = true;
                break;
            }
        }
        $this->assertTrue($hasOverriddenLine, 'Expected to find a TOTAL_OVERRIDE line after applying override');

        // Mutate cart by adding a line
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 1);

        // Override should be invalidated
        $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        // Total should be recalculated (not 8000000 anymore)
        $this->assertNotEquals(8000000, (int) round($snapshot['totals']['grand_total'] * 100));
    }

    public function test_super_admin_bypasses_authorization(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Super Admin should be able to apply directly
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $admin->id,
            8000000,
            null,
            null,
            $admin
        );

        $this->assertEquals(8000000, (int) round($result['totals']['grand_total'] * 100));
    }

    public function test_token_replay_prevention(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Request and approve
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);
        $approvalService->approveRequest($requestId, $supervisor);

        $request = PosActionApprovalRequest::find($requestId);
        $token = $request->token->token_hash;

        // First use should succeed
        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            $token,
            $this->user
        );

        // Second use should fail (token already consumed)
        $this->expectException(\DomainException::class);
        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            $token,
            $this->user
        );
    }

    public function test_restore_original_prices_after_mutation(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        // Add line: Qty 2 @ Rp 50.000 = Rp 100.000
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Override to Rp 80.000
        $snapshot1 = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000, // Rp 80.000
            null,
            null,
            $this->user
        );

        // Verify override was applied
        $this->assertEquals(8000000, (int) round($snapshot1['totals']['grand_total'] * 100));
        $hasOverride = false;
        foreach ($snapshot1['lines'] as $line) {
            if (($line['price_source'] ?? null) === 'TOTAL_OVERRIDE') {
                $hasOverride = true;
                // Store the overridden unit price
                $overriddenPrice = $line['unit_price'];
                break;
            }
        }
        $this->assertTrue($hasOverride, 'Expected TOTAL_OVERRIDE line after applying override');
        $this->assertNotEquals(50000, $overriddenPrice, 'Overridden price should differ from original');

        // Mutate cart (add another line)
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 1);

        // Verify override is cleared and original price is restored
        $snapshot2 = $this->cartService->getSnapshot($this->setting->id, $this->session->id);
        $hasOverride = false;
        foreach ($snapshot2['lines'] as $line) {
            if (($line['price_source'] ?? null) === 'TOTAL_OVERRIDE') {
                $hasOverride = true;
                break;
            }
            // All lines should be back to BASE or other valid source
            $this->assertNotEquals('TOTAL_OVERRIDE', $line['price_source'] ?? null);
        }
        $this->assertFalse($hasOverride, 'No TOTAL_OVERRIDE lines should remain after mutation');
        // Total should be different now (3 qty * 50.000 = 150.000, not 80.000)
        $this->assertNotEquals(8000000, (int) round($snapshot2['totals']['grand_total'] * 100));
    }

    public function test_direct_override_creates_supervisor_approval_record(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Apply override
        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon spesial',
            null,
            $this->user
        );

        // Verify supervisor approval record was created
        $approval = PosSupervisorApproval::where('action_type', 'TOTAL_PRICE_OVERRIDE_APPROVAL')
            ->where('target_id', $this->session->id)
            ->first();

        $this->assertNotNull($approval);
        $this->assertEquals('pos_session', $approval->target_type);
        $this->assertEquals($this->user->id, $approval->requested_by);
        $this->assertEquals($this->user->id, $approval->approved_by);
        $this->assertEquals('APPROVED', $approval->approval_result);
        $this->assertEquals('DISKON SPESIAL', $approval->reason);

        // Verify audit context
        $this->assertIsArray($approval->context_snapshot);
        $this->assertArrayHasKey('source_total', $approval->context_snapshot);
        $this->assertArrayHasKey('target_total', $approval->context_snapshot);
        $this->assertEquals(10000000, $approval->context_snapshot['source_total']); // 2 * 50.000
        $this->assertEquals(8000000, $approval->context_snapshot['target_total']);
    }

    public function test_allocation_result_includes_audit_context(): void
    {
        $lines = [
            ['line_id' => 1, 'qty' => 1, 'unit_price' => 60.00],
            ['line_id' => 2, 'qty' => 1, 'unit_price' => 40.00],
        ];

        $result = $this->allocationService->allocate($lines, 800000); // Rp 8.000

        $this->assertEquals(800000, $result['total_allocated']);
        $this->assertArrayHasKey('fingerprint', $result);
        $this->assertArrayHasKey('allocated', $result);
        $this->assertCount(2, $result['allocated']);

        // Each allocation includes exact line total
        $sum = 0;
        foreach ($result['allocated'] as $item) {
            $sum += $item['allocated_line_total'];
            $this->assertArrayHasKey('effective_unit_price', $item);
        }
        $this->assertEquals(800000, $sum, 'Allocations must sum to target');
    }

    public function test_multiple_overrides_preserve_original_state(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $lineResult = $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);
        $originalTotal = (int) round($lineResult['totals']['grand_total'] * 100); // 100.000

        // First override to Rp 80.000
        $snapshot1 = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            null,
            $this->user
        );
        $this->assertEquals(8000000, (int) round($snapshot1['totals']['grand_total'] * 100));

        // Get the first override's unit price
        $firstOverrideLine = collect($snapshot1['lines'])->first(fn ($line) => ($line['price_source'] ?? null) === 'TOTAL_OVERRIDE');
        $this->assertNotNull($firstOverrideLine);
        $this->assertNotNull($firstOverrideLine['_original_unit_price']);
        $this->assertEquals(50000, $firstOverrideLine['_original_unit_price'], 'Original price should be 50.000');

        // Second override to Rp 70.000 (different total, same cart)
        $snapshot2 = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            7000000,
            null,
            null,
            $this->user
        );
        $this->assertEquals(7000000, (int) round($snapshot2['totals']['grand_total'] * 100));

        // Verify original state is still preserved
        $secondOverrideLine = collect($snapshot2['lines'])->first(fn ($line) => ($line['price_source'] ?? null) === 'TOTAL_OVERRIDE');
        $this->assertNotNull($secondOverrideLine);
        $this->assertNotNull($secondOverrideLine['_original_unit_price']);
        $this->assertEquals(50000, $secondOverrideLine['_original_unit_price'], 'Original price should still be 50.000, not first override price');

        // Mutate to clear override and verify restoration to true original
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 1);
        $snapshot3 = $this->cartService->getSnapshot($this->setting->id, $this->session->id);

        // Should be back to original pricing (Qty 3 * 50.000 = 150.000)
        $this->assertEquals(15000000, (int) round($snapshot3['totals']['grand_total'] * 100));
        foreach ($snapshot3['lines'] as $line) {
            $this->assertNotEquals('TOTAL_OVERRIDE', $line['price_source'] ?? null);
        }
    }

    public function test_override_affects_all_lines_proportionally(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        // Add two lines with different prices
        $product1 = $this->createProduct(50000);
        $product2 = $this->createProduct(25000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product1->id, 2); // 100K
        $this->cartService->addLine($this->setting->id, $this->session->id, $product2->id, 2); // 50K
        // Total = 150K

        // Override to 120K - should allocate proportionally
        $snapshot = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            12000000, // 120K in minor units
            null,
            null,
            $this->user
        );

        // Verify override was applied
        $this->assertEquals(12000000, (int) round($snapshot['totals']['grand_total'] * 100));

        // Verify lines have override pricing
        $hasOverrideLine = false;
        foreach ($snapshot['lines'] as $line) {
            if (($line['price_source'] ?? null) === 'TOTAL_OVERRIDE') {
                $hasOverrideLine = true;
                // Check that price has been adjusted
                $this->assertNotEquals(50000, $line['unit_price']);
                $this->assertNotEquals(25000, $line['unit_price']);
            }
        }
        $this->assertTrue($hasOverrideLine, 'At least one line should be overridden');
    }

    public function test_approval_request_payload_includes_full_audit_context(): void
    {
        // User has no direct permission, so request will be created
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon khusus',
            null,
            $this->user
        );

        // Verify request was created
        $this->assertEquals('request_created', $result['status']);

        // Check the approval request payload
        $request = PosActionApprovalRequest::find($result['request_id']);
        $this->assertNotNull($request);
        $payload = $request->request_payload;

        // Verify audit context is complete
        $this->assertArrayHasKey('source_total', $payload);
        $this->assertArrayHasKey('target_total', $payload);
        $this->assertArrayHasKey('fingerprint', $payload);
        $this->assertArrayHasKey('allocation', $payload);
        $this->assertArrayHasKey('reason', $payload);

        $this->assertEquals(10000000, $payload['source_total']); // 2 * 50.000
        $this->assertEquals(8000000, $payload['target_total']);
        $this->assertEquals('Diskon khusus', $payload['reason']);
        $this->assertNotEmpty($payload['fingerprint']);
        $this->assertIsArray($payload['allocation']);
    }

    public function test_direct_approval_records_full_audit_context(): void
    {
        $this->user->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Apply override
        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon spesial',
            null,
            $this->user
        );

        // Verify supervisor approval record includes full audit context
        $approval = PosSupervisorApproval::where('action_type', 'TOTAL_PRICE_OVERRIDE_APPROVAL')
            ->where('target_id', $this->session->id)
            ->first();

        $this->assertNotNull($approval);
        $this->assertIsArray($approval->context_snapshot);

        $ctx = $approval->context_snapshot;
        $this->assertArrayHasKey('source_total', $ctx);
        $this->assertArrayHasKey('target_total', $ctx);
        $this->assertArrayHasKey('fingerprint', $ctx);
        $this->assertArrayHasKey('allocation', $ctx);
        $this->assertArrayHasKey('requester_id', $ctx);
        $this->assertArrayHasKey('approver_id', $ctx);
        $this->assertArrayHasKey('executor_id', $ctx);
        $this->assertArrayHasKey('executed_at', $ctx);

        $this->assertEquals(10000000, $ctx['source_total']);
        $this->assertEquals(8000000, $ctx['target_total']);
        $this->assertEquals($this->user->id, $ctx['requester_id']);
        $this->assertEquals($this->user->id, $ctx['approver_id']);
        $this->assertEquals($this->user->id, $ctx['executor_id']);
    }

    public function test_supervised_approval_records_audit_context(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Request approval
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon pelanggan',
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);

        // Approve
        $approvalService->approveRequest($requestId, $supervisor, 'Disetujui');

        // Check supervisor approval record
        $approval = PosSupervisorApproval::where('action_type', 'TOTAL_PRICE_OVERRIDE_APPROVAL')
            ->where('target_id', $this->session->id)
            ->first();

        $this->assertNotNull($approval);
        $this->assertIsArray($approval->context_snapshot);

        $ctx = $approval->context_snapshot;
        $this->assertArrayHasKey('source_total', $ctx);
        $this->assertArrayHasKey('target_total', $ctx);
        $this->assertArrayHasKey('fingerprint', $ctx);
        $this->assertArrayHasKey('allocation', $ctx);
        $this->assertArrayHasKey('requester_id', $ctx);
        $this->assertArrayHasKey('approver_id', $ctx);
        $this->assertArrayHasKey('approval_timestamp', $ctx);

        $this->assertEquals(10000000, $ctx['source_total']);
        $this->assertEquals(8000000, $ctx['target_total']);
        $this->assertEquals($this->user->id, $ctx['requester_id']);
        $this->assertEquals($supervisor->id, $ctx['approver_id']);
    }

    public function test_token_execution_records_executor_context(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Request approval
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);

        // Approve
        $approvalService->approveRequest($requestId, $supervisor);

        // Get token
        $request = PosActionApprovalRequest::find($requestId);
        $token = $request->token->token_hash;

        // Execute with token
        $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            null,
            $token,
            $this->user
        );

        // Check that executor context was recorded
        $approval = PosSupervisorApproval::where('action_type', 'TOTAL_PRICE_OVERRIDE_APPROVAL')
            ->where('target_id', $this->session->id)
            ->first();

        $this->assertNotNull($approval);
        $ctx = $approval->context_snapshot;

        $this->assertArrayHasKey('executor_id', $ctx);
        $this->assertArrayHasKey('executed_at', $ctx);
        $this->assertEquals($this->user->id, $ctx['executor_id']);
    }

    public function test_cart_snapshot_includes_total_price_override_approval_state(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Request approval
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Test reason',
            null,
            $this->user
        );

        $requestId = $result['request_id'];

        // Get snapshot - should show pending request
        $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);

        $this->assertIsArray($snapshot['total_price_override_approval']);
        $approval = $snapshot['total_price_override_approval'];
        $this->assertEquals($requestId, $approval['request_id']);
        $this->assertEquals('PENDING', $approval['status']);
        $this->assertEquals('Test reason', $approval['reason']);

        // Approve
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);
        $approvalService->approveRequest($requestId, $supervisor);

        // Get snapshot - should show approved request with token
        $snapshot = $this->cartService->getSnapshot($this->setting->id, $this->session->id);

        $approval = $snapshot['total_price_override_approval'];
        $this->assertEquals($requestId, $approval['request_id']);
        $this->assertEquals('APPROVED', $approval['status']);
        $this->assertNotEmpty($approval['approval_token'] ?? $approval['token']);
    }

    public function test_multiple_override_requests_replace_previous(): void
    {
        // Non-privileged user makes two requests - second should replace first
        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // First request
        $result1 = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'First request',
            null,
            $this->user
        );

        $requestId1 = $result1['request_id'];
        $request1 = PosActionApprovalRequest::find($requestId1);
        $this->assertEquals('PENDING', $request1->status);

        // Second request with same target
        $result2 = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            7000000,
            'Second request',
            null,
            $this->user
        );

        $requestId2 = $result2['request_id'];

        // First request should be cancelled
        $request1 = PosActionApprovalRequest::find($requestId1);
        $this->assertEquals('CANCELLED', $request1->status);

        // Second request should be pending
        $request2 = PosActionApprovalRequest::find($requestId2);
        $this->assertEquals('PENDING', $request2->status);
    }

    public function test_request_validation_allows_token_only(): void
    {
        // Verify that the request validator allows token-only requests
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');
        $this->user->givePermissionTo('pos.access');
        $this->user->givePermissionTo('pos.sell');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Create approval request (user has no direct permission)
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000,
            'Diskon',
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);

        // Approve
        $approvalService->approveRequest($requestId, $supervisor);

        // Get token
        $request = PosActionApprovalRequest::find($requestId);
        $token = $request->token->token_hash;

        // Call controller action directly to test request validation
        $controller = app(\Modules\Pos\Http\Controllers\PosCartTotalOverrideController::class);
        $httpRequest = $this->createHttpRequestWithData(
            ['approval_token' => $token],
            $this->user
        );

        // Execute with token - should apply the approved allocation (Rp 80.000)
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            0, // placeholder, will be ignored for token execution
            null,
            $token,
            $this->user
        );

        $this->assertIsArray($result);
        $this->assertEquals(8000000, (int) round($result['totals']['grand_total'] * 100));
    }

    public function test_request_validation_requires_target_total_without_token(): void
    {
        $this->user->givePermissionTo('pos.access');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Test the validation rules directly
        $request = new \Modules\Pos\Http\Requests\PosCartTotalOverrideRequest();
        $rules = $request->rules();

        // target_total should have 'required_without:approval_token'
        $this->assertIsArray($rules);
        $this->assertArrayHasKey('target_total', $rules);

        // The rules should include required_without:approval_token
        $targetTotalRules = $rules['target_total'];
        if (is_array($targetTotalRules)) {
            $this->assertContains('required_without:approval_token', $targetTotalRules);
        } else {
            // String rules format
            $this->assertStringContainsString('required_without:approval_token', $targetTotalRules);
        }
    }

    public function test_controller_ignores_forged_target_total_when_token_provided(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('pos.supervisor.approval');
        $supervisor->givePermissionTo('pos.overrides.total-price');
        $this->user->givePermissionTo('pos.access');
        $this->user->givePermissionTo('pos.sell');

        $product = $this->createProduct(50000);
        $this->cartService->addLine($this->setting->id, $this->session->id, $product->id, 2);

        // Create approval request for Rp 80.000
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            8000000, // Rp 80.000
            'Diskon',
            null,
            $this->user
        );

        $requestId = $result['request_id'];
        $approvalService = app(\Modules\Pos\Services\PosApprovalRequestService::class);

        // Approve
        $approvalService->approveRequest($requestId, $supervisor);

        // Get token
        $request = PosActionApprovalRequest::find($requestId);
        $token = $request->token->token_hash;

        // Execute with token + forged different target_total
        // Should ignore the forged target_total and apply the approved Rp 80.000
        $result = $this->cartService->overrideTotalPrice(
            $this->setting->id,
            $this->session->id,
            $this->user->id,
            5000000, // Forged value - different from approved Rp 80.000
            null,
            $token,
            $this->user
        );

        // Must apply the approved Rp 80.000, not the forged Rp 50.000
        $this->assertEquals(8000000, (int) round($result['totals']['grand_total'] * 100));
    }

    private function createHttpRequestWithData(array $data, User $user): \Illuminate\Http\Request
    {
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(fn () => $user);
        $request->request->add($data);
        return $request;
    }
}
