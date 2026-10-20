<?php

namespace Modules\Pos\Console;

use App\Models\User;
use Illuminate\Console\Command;
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
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class VerifyKasBonMySqlCollectionCommand extends Command
{
    protected $signature = 'pos:verify-kas-bon-mysql';
    protected $description = 'Verify CASH and Transfer Kas Bon collection on the local MySQL database';

    public function handle(): int
    {
        $this->info('Starting MySQL Kas Bon CASH and Transfer collection verification...');

        $defaultConnection = config('database.default');
        $this->info("Active default database connection: {$defaultConnection}");

        if ($defaultConnection !== 'mysql') {
            $this->error("FAILED: Default database connection must be 'mysql' to ensure all model writes and settlement transactions execute on the verified MySQL database. Got: '{$defaultConnection}'.");
            return 1;
        }

        $connectionConfig = config("database.connections.{$defaultConnection}");
        $driver = $connectionConfig['driver'] ?? null;
        if ($driver !== 'mysql') {
            $this->error("FAILED: Connection '{$defaultConnection}' driver must be 'mysql', got: '{$driver}'.");
            return 1;
        }

        $resolvedConnection = DB::connection();
        if ($resolvedConnection->getName() !== 'mysql' || $resolvedConnection->getDriverName() !== 'mysql') {
            $this->error("FAILED: Resolved DB connection must be named 'mysql' with 'mysql' driver. Got name: '{$resolvedConnection->getName()}', driver: '{$resolvedConnection->getDriverName()}'.");
            return 1;
        }

        if (!Schema::hasColumn('pos_kas_bon_collection_methods', 'reference')) {
            $this->error('FAILED: pos_kas_bon_collection_methods does not have reference column on the MySQL connection.');
            return 1;
        }
        $this->info('Confirmed: pos_kas_bon_collection_methods.reference column exists in MySQL.');

        $testTrxCode = null;
        $testCashCollRef = null;
        $testTrfCollRef = null;

        try {
            DB::transaction(function () use (&$testTrxCode, &$testCashCollRef, &$testTrfCollRef) {
                $setting = Setting::firstOrCreate(['id' => 1], [
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

                $session = PosSession::where('setting_id', $setting->id)->where('status', 'OPEN')->first();
                if ($session) {
                    $cashier = $session->cashier;
                    $terminal = $session->terminal ?? PosTerminal::firstOrCreate(
                        ['setting_id' => $setting->id, 'code' => 'TMY1'],
                        ['name' => 'Terminal MySQL 01', 'is_active' => true]
                    );
                } else {
                    $cashier = User::factory()->create(['name' => 'Kasir Pelaksana']);
                    $terminal = PosTerminal::firstOrCreate(
                        ['setting_id' => $setting->id, 'code' => 'TMY1'],
                        ['name' => 'Terminal MySQL 01', 'is_active' => true]
                    );
                    $session = PosSession::create([
                        'setting_id' => $setting->id,
                        'terminal_id' => $terminal->id,
                        'cashier_user_id' => $cashier->id,
                        'opened_at' => now(),
                        'status' => PosSession::STATUS_OPEN,
                        'active_marker' => 1,
                        'expected_cash_total' => 200000,
                    ]);
                    PosSessionCashEvent::create([
                        'setting_id' => $setting->id,
                        'pos_session_id' => $session->id,
                        'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
                        'direction' => PosSessionCashEvent::DIRECTION_IN,
                        'amount' => 200000,
                        'performed_by' => $cashier->id,
                        'occurred_at' => now(),
                    ]);
                }

                $collectPerm = Permission::firstOrCreate(['name' => 'pos.kasBon.collect', 'guard_name' => 'web']);
                $posAccessPerm = Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);
                $cashier->givePermissionTo([$posAccessPerm, $collectPerm]);

                $coaCash = ChartOfAccount::firstOrCreate(
                    ['setting_id' => $setting->id, 'account_number' => '1101-CASH-MY'],
                    ['name' => 'Kas Toko MySQL', 'category' => 'Kas & Bank']
                );

                $coaBank = ChartOfAccount::firstOrCreate(
                    ['setting_id' => $setting->id, 'account_number' => '1102-BANK-MY'],
                    ['name' => 'Bank BCA MySQL', 'category' => 'Kas & Bank']
                );

                $cashMethod = PaymentMethod::find(1) ?? PaymentMethod::firstOrCreate(
                    ['name' => 'CASH'],
                    ['is_cash' => true, 'is_active' => true, 'coa_id' => $coaCash->id]
                );

                $transferMethod = PaymentMethod::find(3) ?? PaymentMethod::firstOrCreate(
                    ['name' => 'TRANSFER'],
                    ['is_cash' => false, 'is_active' => true, 'requires_reference' => true, 'coa_id' => $coaBank->id]
                );

                $debtMethod = PaymentMethod::where('name', 'LIKE', '%kas bon%')->orWhere('name', 'LIKE', '%utang%')->first()
                    ?? PaymentMethod::firstOrCreate(
                        ['name' => 'Kas Bon'],
                        ['is_cash' => false, 'is_active' => true, 'coa_id' => $coaBank->id]
                    );

                SettingPosPaymentMethod::firstOrCreate(
                    ['setting_id' => $setting->id, 'payment_method_id' => $cashMethod->id],
                    ['is_enabled' => true]
                );

                SettingPosPaymentMethod::firstOrCreate(
                    ['setting_id' => $setting->id, 'payment_method_id' => $transferMethod->id],
                    ['is_enabled' => true]
                );

                $customer = Customer::firstOrCreate(
                    ['customer_name' => 'Pelanggan MySQL'],
                    [
                        'customer_phone' => '081234567899',
                        'customer_email' => 'customermy@example.com',
                        'city' => 'Jakarta',
                        'country' => 'Indonesia',
                        'address' => 'Jl. MySQL No. 1',
                    ]
                );

                $category = Category::firstOrCreate(
                    ['category_code' => 'CATMY01'],
                    ['category_name' => 'Umum MySQL', 'created_by' => $cashier->id, 'setting_id' => $setting->id]
                );

                $unit = Unit::firstOrCreate(
                    ['name' => 'Pcs'],
                    ['short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]
                );

                $product = Product::firstOrCreate(
                    ['product_code' => 'PRD-MY-001'],
                    [
                        'product_name' => 'Barang MySQL',
                        'product_cost' => 50000,
                        'product_price' => 100000,
                        'product_unit' => $unit->id,
                        'category_id' => $category->id,
                        'setting_id' => $setting->id,
                    ]
                );

                $location = Location::firstOrCreate(
                    ['setting_id' => $setting->id],
                    ['name' => 'Gudang Utama MySQL']
                );

                $trxCode = 'TRX-MYSQL-' . time();
                $receiptNumber = 'RCP-MYSQL-' . time();
                $totalAmount = 150000.0;

                $trx = PosTransaction::create([
                    'setting_id' => $setting->id,
                    'pos_terminal_id' => $terminal->id,
                    'source_pos_session_id' => $session->id,
                    'customer_id' => $customer->id,
                    'code' => $trxCode,
                    'status' => PosTransaction::STATUS_COMPLETED,
                    'created_by' => $cashier->id,
                    'owner_user_id' => $cashier->id,
                    'last_saved_by' => $cashier->id,
                    'notes' => 'Kas bon transaction for MySQL verify',
                ]);

                $line = PosTransactionLine::create([
                    'pos_transaction_id' => $trx->id,
                    'line_no' => 1,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->product_name,
                    'product_code_snapshot' => $product->product_code,
                    'qty' => 1,
                    'unit_price' => $totalAmount,
                    'subtotal' => $totalAmount,
                ]);

                $sale = Sale::create([
                    'date' => now(),
                    'reference' => 'SL-' . $trxCode,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'tax_percentage' => 0,
                    'tax_amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => $totalAmount,
                    'paid_amount' => 0,
                    'due_amount' => $totalAmount,
                    'status' => Sale::STATUS_DISPATCHED,
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'Kas Bon',
                    'setting_id' => $setting->id,
                ]);

                SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'quantity' => 1,
                    'price' => $totalAmount,
                    'unit_price' => $totalAmount,
                    'sub_total' => $totalAmount,
                    'product_discount_amount' => 0,
                    'product_discount_type' => 'fixed',
                    'product_tax_amount' => 0,
                    'pos_transaction_line_id' => $line->id,
                ]);

                $checkout = PosCheckout::create([
                    'setting_id' => $setting->id,
                    'pos_transaction_id' => $trx->id,
                    'pos_session_id' => $session->id,
                    'terminal_id' => $terminal->id,
                    'cashier_user_id' => $cashier->id,
                    'customer_id' => $customer->id,
                    'status' => PosCheckout::STATUS_POSTED,
                    'idempotency_key' => 'idemp-' . $trxCode,
                    'payload_hash' => hash('sha256', $trxCode),
                    'subtotal' => $totalAmount,
                    'grand_total' => $totalAmount,
                    'paid_total' => 0,
                    'debt_amount' => $totalAmount,
                    'receipt_number' => $receiptNumber,
                    'note' => 'Kas Bon Awal MySQL',
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
                    'source_setting_id' => $setting->id,
                    'source_location_id' => $location->id,
                    'subtotal' => $totalAmount,
                    'grand_total' => $totalAmount,
                    'paid_total' => 0,
                ]);

                $reviewService = app(PosKasBonReviewService::class);
                $settlementService = app(PosKasBonSettlementService::class);

                // 1. CASH collection: Rp50,000 with free text Keterangan "Cicilan kedua tunai"
                $review1 = $reviewService->review($trx);
                $cashCollection = $settlementService->collect(
                    posTransactionId: $trx->id,
                    receivingSessionId: $session->id,
                    actorUserId: $cashier->id,
                    fingerprint: $review1['fingerprint'],
                    amountToPay: 50000,
                    methods: [
                        [
                            'payment_method_id' => $cashMethod->id,
                            'amount' => 50000,
                            'note' => 'Cicilan kedua tunai',
                        ],
                    ],
                    idempotencyKey: 'idemp-my-cash-' . time()
                );

                $cashRow = PosKasBonCollectionMethod::where('pos_kas_bon_collection_id', $cashCollection->id)->first();
                $this->info("CASH Collection Verified: Amount={$cashRow->amount}, Note='{$cashRow->notes}', Reference=" . var_export($cashRow->reference, true));
                if ($cashRow->notes !== 'Cicilan kedua tunai' || $cashRow->reference !== null) {
                    throw new \RuntimeException('CASH row mismatch');
                }

                // 2. Transfer collection: Rp100,000 with separate note & reference
                $review2 = $reviewService->review($trx);
                $transferCollection = $settlementService->collect(
                    posTransactionId: $trx->id,
                    receivingSessionId: $session->id,
                    actorUserId: $cashier->id,
                    fingerprint: $review2['fingerprint'],
                    amountToPay: 100000,
                    methods: [
                        [
                            'payment_method_id' => $transferMethod->id,
                            'amount' => 100000,
                            'note' => 'Pelunasan transfer BCA',
                            'reference' => 'TRF-BCA-88776655',
                        ],
                    ],
                    idempotencyKey: 'idemp-my-trf-' . time()
                );

                $transferRow = PosKasBonCollectionMethod::where('pos_kas_bon_collection_id', $transferCollection->id)->first();
                $this->info("TRANSFER Collection Verified: Amount={$transferRow->amount}, Note='{$transferRow->notes}', Reference='{$transferRow->reference}'");
                if ($transferRow->notes !== 'Pelunasan transfer BCA' || $transferRow->reference !== 'TRF-BCA-88776655') {
                    throw new \RuntimeException('TRANSFER row mismatch');
                }

                $testTrxCode = $trxCode;
                $testCashCollRef = $cashCollection->reference;
                $testTrfCollRef = $transferCollection->reference;

                $this->info('All CASH and Transfer collections verified successfully in MySQL.');

                // Clean rollback so test artifacts do not pollute development DB
                throw new \Exception('ROLLBACK_CLEAN');
            });
        } catch (\Exception $e) {
            if ($e->getMessage() !== 'ROLLBACK_CLEAN') {
                $this->error('Error during MySQL verification: ' . $e->getMessage());
                return 1;
            }
        }

        // Verify genuine rollback: assert that test artifacts do not exist on the MySQL connection
        if ($testTrxCode && PosTransaction::where('code', $testTrxCode)->exists()) {
            $this->error("FAILED: Test transaction {$testTrxCode} persisted outside transaction rollback!");
            return 1;
        }
        if ($testCashCollRef && PosKasBonCollection::where('reference', $testCashCollRef)->exists()) {
            $this->error("FAILED: Test cash collection {$testCashCollRef} persisted outside transaction rollback!");
            return 1;
        }
        if ($testTrfCollRef && PosKasBonCollection::where('reference', $testTrfCollRef)->exists()) {
            $this->error("FAILED: Test transfer collection {$testTrfCollRef} persisted outside transaction rollback!");
            return 1;
        }

        $this->info('SUCCESS: Local MySQL CASH and Transfer collection verified and database cleaned.');
        return 0;
    }
}
