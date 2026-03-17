<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Services\PosCheckoutOwnershipPriorityAllocationService;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Tests\TestCase;

class POSCheckoutOwnershipPriorityAllocationTest extends TestCase
{
    use RefreshDatabase;

    private PosCheckoutOwnershipPriorityAllocationService $allocationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocationService = app(PosCheckoutOwnershipPriorityAllocationService::class);
    }

    /**
     * Scenario 1: Non-cash < Terminal-owner share
     * Cash should fill remaining balances proportionally.
     */
    public function test_non_cash_less_than_terminal_owner_share()
    {
        // Total: 30000 + 30000 = 60000
        // Payments: 1000 non-cash + 50000 cash = 51000 (should be 60000)
        // Let's use: 1000 non-cash + 59000 cash = 60000
        $context = [
            'terminal_setting_id' => 1,
            'payments' => [
                ['payment_method_id' => 2, 'amount_minor_units' => 100000, 'is_cash' => false],    // 1000 non-cash
                ['payment_method_id' => 1, 'amount_minor_units' => 5900000, 'is_cash' => true],    // 59000 cash
            ],
            'groups' => [
                ['split_key' => 'setting1', 'source_setting_id' => 1, 'grand_total_minor' => 3000000],
                ['split_key' => 'setting2', 'source_setting_id' => 2, 'grand_total_minor' => 3000000],
            ],
        ];

        $result = $this->allocationService->allocate($context);
        $allocations = $result['allocations'];

        // Non-cash (1000 = 100000 minor units) should go entirely to terminal-owned group (setting1)
        $setting1NonCash = $this->getPaymentGroupAllocation($allocations, 0, 'setting1');
        $this->assertEquals(100000, $setting1NonCash);

        // Setting2 receives no non-cash
        $setting2NonCash = $this->getPaymentGroupAllocation($allocations, 0, 'setting2');
        $this->assertEquals(0, $setting2NonCash);

        // Cash (59000 = 5900000 minor units) should split proportionally: Setting1 needs 29000, Setting2 needs 30000
        // Ratio: 29000:30000
        // Setting1 gets: 5900000 × (29/59) = 2900000
        // Setting2 gets: 5900000 × (30/59) = 3000000
        $setting1Cash = $this->getPaymentGroupAllocation($allocations, 1, 'setting1');
        $setting2Cash = $this->getPaymentGroupAllocation($allocations, 1, 'setting2');

        $this->assertEquals(2900000, $setting1Cash);
        $this->assertEquals(3000000, $setting2Cash);

        // Validate totals
        $this->assertEquals(3000000, 100000 + $setting1Cash);  // setting1 total
        $this->assertEquals(3000000, 0 + $setting2Cash);       // setting2 total

        // Validate grand total reconciliation
        $totalAllocated = 100000 + 2900000 + 0 + 3000000;
        $this->assertEquals(6000000, $totalAllocated);
    }

    /**
     * Scenario 2: Non-cash > Terminal-owner share
     * Non-cash overflow should be allocated proportionally.
     */
    public function test_non_cash_greater_than_terminal_owner_share()
    {
        // Total: 30000 + 30000 = 60000
        // Payments: 50000 non-cash + 10000 cash = 60000
        $context = [
            'terminal_setting_id' => 1,
            'payments' => [
                ['payment_method_id' => 2, 'amount_minor_units' => 5000000, 'is_cash' => false], // 50000 non-cash
                ['payment_method_id' => 1, 'amount_minor_units' => 1000000, 'is_cash' => true],  // 10000 cash
            ],
            'groups' => [
                ['split_key' => 'setting1', 'source_setting_id' => 1, 'grand_total_minor' => 3000000],
                ['split_key' => 'setting2', 'source_setting_id' => 2, 'grand_total_minor' => 3000000],
            ],
        ];

        $result = $this->allocationService->allocate($context);
        $allocations = $result['allocations'];

        // Non-cash (50000): First 30000 goes to setting1 (terminal-owned)
        $setting1NonCash = $this->getPaymentGroupAllocation($allocations, 0, 'setting1');
        $this->assertEquals(3000000, $setting1NonCash);

        // Remaining 20000 non-cash goes to setting2 (only group with balance remaining)
        $setting2NonCash = $this->getPaymentGroupAllocation($allocations, 0, 'setting2');
        $this->assertEquals(2000000, $setting2NonCash);

        // Cash (10000) goes to setting2 (still needs 10000)
        $setting1Cash = $this->getPaymentGroupAllocation($allocations, 1, 'setting1');
        $setting2Cash = $this->getPaymentGroupAllocation($allocations, 1, 'setting2');

        $this->assertEquals(0, $setting1Cash);
        $this->assertEquals(1000000, $setting2Cash);

        // Validate totals
        $this->assertEquals(3000000, $setting1NonCash);         // setting1 total
        $this->assertEquals(3000000, $setting2NonCash + $setting2Cash); // setting2 total
    }

    /**
     * Test that allocation matrix reconciles correctly.
     */
    public function test_allocation_matrix_reconciliation()
    {
        $context = [
            'terminal_setting_id' => 1,
            'payments' => [
                ['payment_method_id' => 1, 'amount_minor_units' => 5000000, 'is_cash' => true],
                ['payment_method_id' => 2, 'amount_minor_units' => 1000000, 'is_cash' => false],
            ],
            'groups' => [
                ['split_key' => 'setting1', 'source_setting_id' => 1, 'grand_total_minor' => 3000000],
                ['split_key' => 'setting2', 'source_setting_id' => 2, 'grand_total_minor' => 3000000],
            ],
        ];

        $result = $this->allocationService->allocate($context);
        $allocations = $result['allocations'];

        // Verify all allocations sum correctly
        $totalAllocated = array_sum(array_map(
            fn(array $a) => (int) ($a['allocated_amount_minor_units'] ?? 0),
            $allocations
        ));

        $this->assertEquals(6000000, $totalAllocated);

        // Verify per-group allocations
        $setting1Total = array_sum(array_map(
            fn(array $a) => (int) ($a['allocated_amount_minor_units'] ?? 0),
            array_filter($allocations, fn(array $a) => $a['split_key'] === 'setting1')
        ));

        $setting2Total = array_sum(array_map(
            fn(array $a) => (int) ($a['allocated_amount_minor_units'] ?? 0),
            array_filter($allocations, fn(array $a) => $a['split_key'] === 'setting2')
        ));

        $this->assertEquals(3000000, $setting1Total);
        $this->assertEquals(3000000, $setting2Total);
    }

    /**
     * Test that invalid allocation contexts throw errors.
     */
    public function test_invalid_context_throws_error()
    {
        $this->expectException(PosCheckoutValidationException::class);

        $context = [
            'terminal_setting_id' => 0,  // Invalid
            'payments' => [],
            'groups' => [],
        ];

        $this->allocationService->allocate($context);
    }

    /**
     * Test that allocation fails when groups are not fully covered.
     */
    public function test_insufficient_payment_throws_error()
    {
        $this->expectException(PosCheckoutValidationException::class);

        $context = [
            'terminal_setting_id' => 1,
            'payments' => [
                ['payment_method_id' => 1, 'amount_minor_units' => 1000000, 'is_cash' => true],  // 10000
            ],
            'groups' => [
                ['split_key' => 'setting1', 'source_setting_id' => 1, 'grand_total_minor' => 3000000],  // 30000 needed
                ['split_key' => 'setting2', 'source_setting_id' => 2, 'grand_total_minor' => 3000000],  // 30000 needed
            ],
        ];

        $this->allocationService->allocate($context);
    }

    /**
     * Helper: Get allocation for a specific payment and group.
     */
    private function getPaymentGroupAllocation(array $allocations, int $paymentIndex, string $splitKey): int
    {
        foreach ($allocations as $allocation) {
            if ($allocation['payment_index'] === $paymentIndex && $allocation['split_key'] === $splitKey) {
                return (int) $allocation['allocated_amount_minor_units'];
            }
        }
        return 0;
    }
}
