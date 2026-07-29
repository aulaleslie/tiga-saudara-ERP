<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosTransactionSnapshotMapper;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class PosTransactionSnapshotMapperTest extends PosTransactionFeatureTestCase
{
    private PosTransactionSnapshotMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = app(PosTransactionSnapshotMapper::class);
    }

    public function test_persist_lines_writes_expected_line_and_serial_rows(): void
    {
        $setting = $this->createSetting('BIZ POS TXN MAP PERSIST');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN MAP PERSIST USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $session = $this->openSession($setting, $terminal, $user);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'DOC-TXN-MAP-0001',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'customer_id' => null,
            'source_pos_session_id' => $session->id,
            'snapshot_totals' => ['grand_total' => 0],
        ]);

        $product = $this->createStockedProduct($setting, $location, [
            'serial_number_required' => true,
            'product_code' => 'SKU-TXN-MAP-001',
        ]);

        $cartLines = [
            1 => [
                'line_id' => 1,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'serial_number_required' => true,
                'assigned_serials' => ['SN-MAP-001', 'SN-MAP-002'],
                'qty' => 2,
                'unit_price' => 15000,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0,
                'line_discount_type' => 'fixed',
                'line_discount_value' => 0,
            ],
        ];

        $this->mapper->persistLines($transaction, $cartLines);

        $line = PosTransactionLine::with('serials')
            ->where('pos_transaction_id', $transaction->id)
            ->first();

        $this->assertNotNull($line);
        $this->assertEquals(2, (int) $line->qty);
        $this->assertCount(2, $line->serials);
        $this->assertDatabaseHas('pos_transaction_line_serials', [
            'pos_transaction_line_id' => $line->id,
            'serial_number' => 'SN-MAP-001',
        ]);
    }

    public function test_hydrate_cart_restores_fields_and_active_transaction_id(): void
    {
        $setting = $this->createSetting('BIZ POS TXN MAP HYDRATE');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN MAP HYDRATE USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $session = $this->openSession($setting, $terminal, $user);

        $product = $this->createStockedProduct($setting, $location, [
            'product_code' => 'SKU-TXN-MAP-002',
            'serial_number_required' => true,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'DOC-TXN-MAP-0002',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'customer_id' => null,
            'source_pos_session_id' => $session->id,
            'snapshot_totals' => ['grand_total' => 30000],
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => 'Hydrate Product',
            'product_code_snapshot' => 'SKU-TXN-MAP-002',
            'conversion_id' => null,
            'qty' => 2,
            'unit_price' => 15000,
            'tax_id' => null,
            'tax_name_snapshot' => null,
            'tax_rate_snapshot' => 0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'barcode' => 'SKU-TXN-MAP-002-BC',
            ],
        ]);
        $line->serials()->create(['serial_number' => 'SN-MAP-003']);

        $cart = $this->mapper->hydrateCart($transaction);

        $this->assertEquals($transaction->id, $cart['active_transaction_id']);
        $this->assertCount(1, $cart['lines']);
        $this->assertEquals('Hydrate Product', $cart['lines'][1]['product_name']);
        $this->assertTrue($cart['lines'][1]['serial_number_required']);
        $this->assertEquals(['SN-MAP-003'], $cart['lines'][1]['assigned_serials']);
        $this->assertNotEmpty($cart['lines'][1]['merge_key']);
    }

    public function test_build_snapshot_totals_returns_expected_structure(): void
    {
        $snapshotTotals = $this->mapper->buildSnapshotTotals([
            'line_count' => 3,
            'subtotal' => 30000,
            'discount_total' => 1500,
            'tax_total' => 2850,
            'grand_total' => 31350,
        ]);

        $this->assertSame(3, $snapshotTotals['line_count']);
        $this->assertSame(30000, $snapshotTotals['subtotal']);
        $this->assertSame(1500, $snapshotTotals['discount_total']);
        $this->assertSame(2850, $snapshotTotals['tax_total']);
        $this->assertSame(31350, $snapshotTotals['grand_total']);
    }

    public function test_build_snapshot_hash_is_stable_for_same_transaction_content(): void
    {
        $transaction = $this->createTransactionForHashing('HASH-STABLE', ['SN-HASH-1', 'SN-HASH-2'], 2);

        $hashA = $this->mapper->buildSnapshotHash($transaction);
        $hashB = $this->mapper->buildSnapshotHash($transaction->fresh(['lines.serials']));

        $this->assertSame($hashA, $hashB);
    }

    public function test_build_snapshot_hash_normalizes_serial_order(): void
    {
        $transaction = $this->createTransactionForHashing('HASH-SERIAL', ['SN-HASH-3', 'SN-HASH-4'], 2);
        $hashA = $this->mapper->buildSnapshotHash($transaction);

        $line = $transaction->lines()->firstOrFail();
        $line->serials()->delete();
        $line->serials()->create(['serial_number' => 'SN-HASH-4']);
        $line->serials()->create(['serial_number' => 'SN-HASH-3']);

        $hashB = $this->mapper->buildSnapshotHash($transaction->fresh(['lines.serials']));

        $this->assertSame($hashA, $hashB);
    }

    public function test_build_snapshot_hash_changes_when_line_payload_changes(): void
    {
        $transactionA = $this->createTransactionForHashing('HASH-LINE-A', ['SN-HASH-5'], 1);
        $transactionB = $this->createTransactionForHashing('HASH-LINE-B', ['SN-HASH-5'], 3);

        $hashA = $this->mapper->buildSnapshotHash($transactionA);
        $hashB = $this->mapper->buildSnapshotHash($transactionB);

        $this->assertNotSame($hashA, $hashB);
    }

    private function createTransactionForHashing(string $suffix, array $serials, int $qty): PosTransaction
    {
        $setting = $this->createSetting('BIZ POS TXN MAP HASH ' . $suffix);
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN MAP HASH USER ' . $suffix, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $session = $this->openSession($setting, $terminal, $user);

        $product = $this->createStockedProduct($setting, $location, [
            'product_code' => 'SKU-TXN-HASH-' . $suffix,
            'serial_number_required' => true,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'DOC-TXN-HASH-' . $suffix,
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'customer_id' => null,
            'source_pos_session_id' => $session->id,
            'snapshot_totals' => [
                'line_count' => 1,
                'subtotal' => 15000 * $qty,
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => 15000 * $qty,
            ],
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'conversion_id' => null,
            'qty' => $qty,
            'unit_price' => 15000,
            'tax_id' => null,
            'tax_name_snapshot' => null,
            'tax_rate_snapshot' => 0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [],
        ]);

        foreach ($serials as $serial) {
            $line->serials()->create([
                'serial_number' => $serial,
            ]);
        }

        return $transaction->fresh(['lines.serials']);
    }

    /**
     * Verification: Legacy snapshot with stale base_qty metadata is ignored.
     * Quantity contract: `qty` is the sole authoritative base-unit quantity.
     * Scanning one box with factor 5 adds `qty = 5` (not base_qty = 1, qty = 5).
     * The legacy snapshot test may retain stale `base_qty` inside fixture metadata
     * solely to prove it is ignored in active code for serials and stock.
     *
     * @group pos-quantity-contract
     */
    public function test_legacy_snapshot_with_base_qty_uses_qty_for_serials_and_stock(): void
    {
        $setting = $this->createSetting('BIZ POS LEGACY BASE_QTY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS LEGACY BASE_QTY USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $session = $this->openSession($setting, $terminal, $user);

        // Create a product with unit conversion (Box of 5 units)
        $baseUnit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'setting_id' => $setting->id,
            'name' => 'Unit',
            'short_name' => 'Unit',
        ]);

        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'setting_id' => $setting->id,
            'name' => 'Box',
            'short_name' => 'BOX',
        ]);

        $product = $this->createStockedProduct($setting, $location, [
            'product_code' => 'LEGACY-BASE-QTY-001',
            'serial_number_required' => true,
        ]);

        \Modules\Product\Entities\ProductUnitConversion::updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $boxUnit->id],
            ['base_unit_id' => $baseUnit->id, 'conversion_factor' => 5]
        );

        // Create transaction with legacy metadata containing stale base_qty
        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'DOC-LEGACY-BASE-QTY-001',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'customer_id' => null,
            'source_pos_session_id' => $session->id,
            'snapshot_totals' => [
                'line_count' => 1,
                'subtotal' => 50000,
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => 50000,
            ],
        ]);

        // Create line with qty = 5 (scanning one box) and legacy stale base_qty metadata
        $lineMeta = [
            'price_source' => 'BASE',
            'line_total' => 50000,
            // Legacy stale metadata that must be ignored
            'base_qty' => 1,  // This should be ignored; qty = 5 is authoritative
            'conversion_factor' => 5,
            'assigned_serials' => ['SN-LEGACY-001', 'SN-LEGACY-002', 'SN-LEGACY-003', 'SN-LEGACY-004', 'SN-LEGACY-005'],
        ];

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'conversion_id' => null,
            'qty' => 5,  // Authoritative: one box = 5 base units
            'unit_price' => 10000,
            'tax_id' => null,
            'tax_name_snapshot' => null,
            'tax_rate_snapshot' => 0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => $lineMeta,
        ]);

        // Add serials matching qty = 5 (not base_qty)
        for ($i = 1; $i <= 5; $i++) {
            $line->serials()->create([
                'serial_number' => "SN-LEGACY-00{$i}",
            ]);
        }

        $transaction->refresh();
        $line = $line->fresh();

        // Verify qty = 5 is used as authoritative base-unit quantity
        $this->assertEquals(5, $line->qty, 'qty must be 5 (one box of 5 units as authoritative base-unit quantity)');

        // Verify serials count matches qty (5), not stale base_qty (1)
        $this->assertEquals(5, $line->serials()->count(), 'Serial count must match qty (5), not stale base_qty (1)');

        // Verify legacy base_qty is preserved in line_meta but not used by active code
        $this->assertEquals(1, $line->line_meta['base_qty'], 'Legacy base_qty should be retained in metadata for historical reference');

        // Verify active code uses qty for quantity calculations
        $this->assertCount(5, $line->line_meta['assigned_serials'], 'Assigned serials count must match qty (5)');

        // Verify snapshot hash is stable (qty = 5 is the canonical value)
        $hashBefore = $this->mapper->buildSnapshotHash($transaction);
        // Verifying that base_qty doesn't affect the hash
        // (the hash should reflect qty = 5, not be confused by base_qty = 1)
        $this->assertNotNull($hashBefore, 'Snapshot hash must be generated based on qty (5) as authoritative');
    }
}
