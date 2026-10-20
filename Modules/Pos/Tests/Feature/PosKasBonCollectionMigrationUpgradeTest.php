<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosKasBonCollectionMethod;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosKasBonReviewService;
use Modules\Pos\Services\PosKasBonSettlementService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosKasBonCollectionMigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Location $location;
    protected User $cashier;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $transferMethod;
    protected PaymentMethod $debtMethod;
    protected Customer $customer;
    protected Product $product;
    protected PosKasBonSettlementService $settlementService;
    protected PosKasBonReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();

        $collectPerm = Permission::firstOrCreate(['name' => 'pos.kasBon.collect', 'guard_name' => 'web']);
        $posAccessPerm = Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->givePermissionTo([$collectPerm, $posAccessPerm]);

        $this->setting = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Toko Kas Bon Maju',
            'company_email' => 'toko1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'toko1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->cashier = User::factory()->create(['name' => 'Kasir Pelaksana']);
        $this->cashier->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $this->location = Location::create(['name' => 'Gudang Utama', 'setting_id' => $this->setting->id]);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 01',
            'code' => 'T01',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 200000,
        ]);

        PosSessionCashEvent::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 200000,
            'performed_by' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        $coaCash = ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => '1101-CASH-UPG',
            'name' => 'Kas Toko Upgrade',
            'category' => 'Kas & Bank',
        ]);

        $coaBank = ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => '1102-BANK-UPG',
            'name' => 'Bank BCA Upgrade',
            'category' => 'Kas & Bank',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'setting_id' => $this->setting->id,
            'name' => 'Cash',
            'is_cash' => true,
            'is_active' => true,
            'coa_id' => $coaCash->id,
        ]);

        $this->transferMethod = PaymentMethod::create([
            'setting_id' => $this->setting->id,
            'name' => 'Transfer Bank BCA',
            'is_cash' => false,
            'is_active' => true,
            'requires_reference' => true,
            'coa_id' => $coaBank->id,
        ]);

        $this->debtMethod = PaymentMethod::create([
            'setting_id' => $this->setting->id,
            'name' => 'Kas Bon',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coaBank->id,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->cashMethod->id,
            'is_enabled' => true,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->transferMethod->id,
            'is_enabled' => true,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Pelanggan Setia',
            'customer_phone' => '081234567890',
            'customer_email' => 'customer@example.com',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl. Sudirman No. 1',
        ]);

        $category = Category::create([
            'category_code' => 'CAT01',
            'category_name' => 'Umum',
            'created_by' => $this->cashier->id,
            'setting_id' => $this->setting->id,
        ]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);

        $this->product = Product::create([
            'product_name' => 'Barang Kas Bon',
            'product_code' => 'PRD-KB-UPG',
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => $unit->id,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->settlementService = app(PosKasBonSettlementService::class);
        $this->reviewService = app(PosKasBonReviewService::class);
    }

    public function test_upgrade_path_from_older_table_definition_preserves_data_and_adds_reference(): void
    {
        $migrationFile = base_path('Modules/Pos/Database/Migrations/2026_10_20_000002_add_reference_to_pos_kas_bon_collection_methods_table.php');
        $this->assertFileExists($migrationFile);
        $migration = require $migrationFile;

        // 1. Roll back to older table definition (without reference column)
        $migration->down();
        $this->assertFalse(
            Schema::hasColumn('pos_kas_bon_collection_methods', 'reference'),
            'Reference column must not exist in older table definition.'
        );

        // 2. Insert existing collection and method row using older schema definition
        $trxFixture = $this->createKasBonTransaction('TRX-UPG-001', 'RCP-UPG-001', 200000);
        $trx = $trxFixture['transaction'];

        $legacyCollection = PosKasBonCollection::create([
            'pos_transaction_id' => $trx->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'pos_terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'reference' => 'KBC-UPG-LEGACY-01',
            'collected_at' => now(),
            'amount' => 50000.00,
            'cash_tender_amount' => 50000.00,
            'change_amount' => 0.00,
            'idempotency_key' => 'idemp-legacy-01',
            'request_hash' => hash('sha256', 'legacy-payload'),
            'notes' => 'Existing legacy collection before upgrade',
        ]);

        $legacyMethodId = DB::table('pos_kas_bon_collection_methods')->insertGetId([
            'pos_kas_bon_collection_id' => $legacyCollection->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method_name' => 'Cash',
            'amount' => 50000.00,
            'tender_amount' => 50000.00,
            'change_amount' => 0.00,
            'sequence_order' => 1,
            'notes' => 'Legacy cash note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('pos_kas_bon_collection_methods', [
            'id' => $legacyMethodId,
            'notes' => 'Legacy cash note',
        ]);

        // 3. Apply the incremental upgrade migration
        $migration->up();

        $this->assertTrue(
            Schema::hasColumn('pos_kas_bon_collection_methods', 'reference'),
            'Reference column must exist after upgrade migration.'
        );

        // 4. Verify pre-existing data is preserved and reference is null for older rows
        $preservedRow = DB::table('pos_kas_bon_collection_methods')->where('id', $legacyMethodId)->first();
        $this->assertNotNull($preservedRow);
        $this->assertEquals('Legacy cash note', $preservedRow->notes);
        $this->assertEquals(50000.00, (float) $preservedRow->amount);
        $this->assertNull($preservedRow->reference, 'Legacy row should have null reference.');

        // 5. Test idempotency: re-running up() should safely do nothing without exception
        $migration->up();
        $this->assertTrue(Schema::hasColumn('pos_kas_bon_collection_methods', 'reference'));

        // 6. Verify subsequent CASH collection on upgraded database
        $trxFixture2 = $this->createKasBonTransaction('TRX-UPG-002', 'RCP-UPG-002', 150000);
        $trx2 = $trxFixture2['transaction'];
        $review2 = $this->reviewService->review($trx2);

        $cashCollection = $this->settlementService->collect(
            posTransactionId: $trx2->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review2['fingerprint'],
            amountToPay: 50000,
            methods: [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 50000,
                    'note' => 'Cicilan kedua tunai',
                ],
            ],
            idempotencyKey: 'idemp-upg-cash-01'
        );

        $cashMethodRow = PosKasBonCollectionMethod::where('pos_kas_bon_collection_id', $cashCollection->id)->first();
        $this->assertNotNull($cashMethodRow);
        $this->assertEquals(50000.0, (float) $cashMethodRow->amount);
        $this->assertEquals('Cicilan kedua tunai', $cashMethodRow->notes);
        $this->assertNull($cashMethodRow->reference);

        // 7. Verify subsequent Transfer collection on upgraded database
        $review2Updated = $this->reviewService->review($trx2);
        $transferCollection = $this->settlementService->collect(
            posTransactionId: $trx2->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review2Updated['fingerprint'],
            amountToPay: 60000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 60000,
                    'note' => 'Titipan transfer BCA',
                    'reference' => 'TRF-UPGRADE-123',
                ],
            ],
            idempotencyKey: 'idemp-upg-transfer-01'
        );

        $transferMethodRow = PosKasBonCollectionMethod::where('pos_kas_bon_collection_id', $transferCollection->id)->first();
        $this->assertNotNull($transferMethodRow);
        $this->assertEquals(60000.0, (float) $transferMethodRow->amount);
        $this->assertEquals('Titipan transfer BCA', $transferMethodRow->notes);
        $this->assertEquals('TRF-UPGRADE-123', $transferMethodRow->reference);
    }

    protected function createKasBonTransaction(string $trxCode, string $receiptNumber, float $amount): array
    {
        $trx = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'pos_terminal_id' => $this->terminal->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'code' => $trxCode,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'notes' => 'Kas bon transaction for upgrade test',
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-' . $trxCode,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Kas Bon',
            'setting_id' => $this->setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => $amount,
            'unit_price' => $amount,
            'sub_total' => $amount,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'pos_transaction_line_id' => $line->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'idemp-' . $trxCode,
            'payload_hash' => hash('sha256', $trxCode),
            'subtotal' => $amount,
            'grand_total' => $amount,
            'paid_total' => 0,
            'debt_amount' => $amount,
            'receipt_number' => $receiptNumber,
            'note' => 'Kas Bon Awal',
            'metadata' => [
                'is_debt' => true,
            ],
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_' . $trxCode,
            'tax_bucket' => 'notax',
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'subtotal' => $amount,
            'grand_total' => $amount,
            'paid_total' => 0,
        ]);

        return ['transaction' => $trx, 'sale' => $sale];
    }
}
