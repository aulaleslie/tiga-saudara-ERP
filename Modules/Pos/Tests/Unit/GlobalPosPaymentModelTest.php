<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\GlobalPosPaymentAllocation;
use Modules\Pos\Entities\GlobalPosPaymentBatch;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalPosPaymentModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected PaymentMethod $paymentMethod;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Setting 1',
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
        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '08123456789',
            'customer_email' => 'cust@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
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

        $this->paymentMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);
    }

    public function test_batch_and_allocation_creation_and_relationships()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-001',
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
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $salePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'reference' => 'SP-001',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => $this->paymentMethod->name,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $posTransaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $batch = GlobalPosPaymentBatch::create([
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'date' => now()->toDateString(),
            'reference' => 'GPP-BATCH-001',
            'payment_method_id' => $this->paymentMethod->id,
            'note' => 'Batch note',
            'idempotency_key' => 'idemp-12345',
            'total_amount' => 50000,
        ]);

        $allocation = GlobalPosPaymentAllocation::create([
            'global_pos_payment_batch_id' => $batch->id,
            'pos_transaction_id' => $posTransaction->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $salePayment->id,
            'amount' => 50000,
        ]);

        $this->assertEquals($batch->id, $allocation->batch->id);
        $this->assertEquals($posTransaction->id, $allocation->posTransaction->id);
        $this->assertEquals($sale->id, $allocation->sale->id);
        $this->assertEquals($salePayment->id, $allocation->salePayment->id);

        $this->assertCount(1, $batch->allocations);
        $this->assertEquals($allocation->id, $batch->allocations->first()->id);

        $this->assertCount(1, $posTransaction->globalPosPaymentAllocations);
        $this->assertEquals($allocation->id, $posTransaction->globalPosPaymentAllocations->first()->id);

        $this->assertNotNull($salePayment->globalPosPaymentAllocation);
        $this->assertEquals($allocation->id, $salePayment->globalPosPaymentAllocation->id);
    }

    public function test_batch_cascade_deletes_allocations()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-002',
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
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $salePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'reference' => 'SP-002',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => $this->paymentMethod->name,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $posTransaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-002',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $batch = GlobalPosPaymentBatch::create([
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'date' => now()->toDateString(),
            'reference' => 'GPP-BATCH-002',
            'payment_method_id' => $this->paymentMethod->id,
            'total_amount' => 50000,
        ]);

        $allocation = GlobalPosPaymentAllocation::create([
            'global_pos_payment_batch_id' => $batch->id,
            'pos_transaction_id' => $posTransaction->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $salePayment->id,
            'amount' => 50000,
        ]);

        $batch->delete();

        $this->assertDatabaseMissing('global_pos_payment_allocations', ['id' => $allocation->id]);
    }
}
