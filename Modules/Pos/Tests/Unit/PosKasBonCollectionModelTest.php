<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosKasBonCollectionAllocation;
use Modules\Pos\Entities\PosKasBonCollectionMethod;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosKasBonCollectionModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected PosTerminal $terminal;
    protected PosTransaction $transaction;
    protected PaymentMethod $cashMethod;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Store 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->user = User::factory()->create();

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'pos_terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 100000,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'John Doe',
            'customer_phone' => '0812345678',
            'customer_email' => 'john@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
        ]);

        $this->transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'code' => 'TRX-TEST-001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::firstOrCreate(
            ['setting_id' => $this->setting->id, 'account_number' => '1100-TEST-1'],
            [
                'name' => 'Cash Account Test',
                'account_number' => '1100-TEST-1',
                'category' => 'Kas & Bank',
                'setting_id' => $this->setting->id,
            ]
        );

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash', 'setting_id' => $this->setting->id],
            ['is_cash' => true, 'is_active' => true, 'coa_id' => $coa->id]
        );
    }

    public function test_collection_header_creates_with_relationships_and_casts(): void
    {
        $collection = PosKasBonCollection::create([
            'pos_transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'pos_terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'reference' => 'KBC-20261020-001',
            'collected_at' => now(),
            'amount' => 150000.00,
            'cash_tender_amount' => 200000.00,
            'change_amount' => 50000.00,
            'idempotency_key' => 'idemp-123456',
            'request_hash' => hash('sha256', 'payload-test'),
            'review_snapshot' => ['total' => 500000, 'due' => 150000],
            'receipt_snapshot' => ['customer' => 'John Doe', 'amount' => 150000],
            'notes' => 'Test collection note',
        ]);

        $this->assertDatabaseHas('pos_kas_bon_collections', [
            'id' => $collection->id,
            'reference' => 'KBC-20261020-001',
            'idempotency_key' => 'idemp-123456',
        ]);

        $this->assertEquals('150000.00', $collection->amount);
        $this->assertEquals('200000.00', $collection->cash_tender_amount);
        $this->assertEquals('50000.00', $collection->change_amount);
        $this->assertIsArray($collection->review_snapshot);
        $this->assertEquals(150000, $collection->review_snapshot['due']);
        $this->assertIsArray($collection->receipt_snapshot);

        $this->assertInstanceOf(PosTransaction::class, $collection->transaction);
        $this->assertInstanceOf(PosSession::class, $collection->session);
        $this->assertInstanceOf(PosTerminal::class, $collection->terminal);
        $this->assertInstanceOf(User::class, $collection->user);
        $this->assertInstanceOf(Setting::class, $collection->setting);
    }

    public function test_collection_header_supports_nullable_terminal(): void
    {
        $terminalLessSession = PosSession::create([
            'setting_id' => $this->setting->id,
            'pos_terminal_id' => null,
            'cashier_user_id' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 2,
            'expected_cash_total' => 50000,
        ]);

        $collection = PosKasBonCollection::create([
            'pos_transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $terminalLessSession->id,
            'pos_terminal_id' => null,
            'user_id' => $this->user->id,
            'reference' => 'KBC-20261020-002',
            'collected_at' => now(),
            'amount' => 50000.00,
            'cash_tender_amount' => 50000.00,
            'change_amount' => 0.00,
            'idempotency_key' => 'idemp-terminal-less',
            'request_hash' => hash('sha256', 'payload-terminal-less'),
        ]);

        $this->assertNull($collection->pos_terminal_id);
        $this->assertNull($collection->terminal);
        $this->assertEquals($terminalLessSession->id, $collection->pos_session_id);
    }

    public function test_idempotency_key_is_unique_in_database(): void
    {
        PosKasBonCollection::create([
            'pos_transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'pos_terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'reference' => 'KBC-20261020-003',
            'collected_at' => now(),
            'amount' => 50000.00,
            'idempotency_key' => 'idemp-duplicate',
            'request_hash' => hash('sha256', 'payload-1'),
        ]);

        $this->expectException(QueryException::class);

        PosKasBonCollection::create([
            'pos_transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'pos_terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'reference' => 'KBC-20261020-004',
            'collected_at' => now(),
            'amount' => 50000.00,
            'idempotency_key' => 'idemp-duplicate',
            'request_hash' => hash('sha256', 'payload-2'),
        ]);
    }

    public function test_methods_and_allocations_relationships_and_uniqueness(): void
    {
        $collection = PosKasBonCollection::create([
            'pos_transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'pos_terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'reference' => 'KBC-20261020-005',
            'collected_at' => now(),
            'amount' => 100000.00,
            'cash_tender_amount' => 100000.00,
            'change_amount' => 0.00,
            'idempotency_key' => 'idemp-methods-test',
            'request_hash' => hash('sha256', 'payload-methods'),
        ]);

        $methodRow = PosKasBonCollectionMethod::create([
            'pos_kas_bon_collection_id' => $collection->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method_name' => 'Cash',
            'amount' => 100000.00,
            'tender_amount' => 100000.00,
            'change_amount' => 0.00,
            'sequence_order' => 1,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-TEST-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $salePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 100000.00,
            'date' => now()->toDateString(),
            'reference' => 'SP-TEST-001',
            'payment_method_id' => $this->cashMethod->id,
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $allocation = PosKasBonCollectionAllocation::create([
            'pos_kas_bon_collection_id' => $collection->id,
            'pos_kas_bon_collection_method_id' => $methodRow->id,
            'pos_transaction_id' => $this->transaction->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $salePayment->id,
            'amount' => 100000.00,
        ]);

        $this->assertCount(1, $collection->methods);
        $this->assertEquals($methodRow->id, $collection->methods->first()->id);
        $this->assertCount(1, $collection->allocations);
        $this->assertEquals($allocation->id, $collection->allocations->first()->id);
        $this->assertEquals($salePayment->id, $allocation->salePayment->id);

        // Verify sale_payment_id uniqueness
        $this->expectException(QueryException::class);
        PosKasBonCollectionAllocation::create([
            'pos_kas_bon_collection_id' => $collection->id,
            'pos_kas_bon_collection_method_id' => $methodRow->id,
            'pos_transaction_id' => $this->transaction->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $salePayment->id, // duplicate sale_payment_id
            'amount' => 50000.00,
        ]);
    }
}
