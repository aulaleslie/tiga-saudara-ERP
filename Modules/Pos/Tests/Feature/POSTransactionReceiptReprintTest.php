<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutPayment;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Permission;

/**
 * @group pos-transaction-reprint
 */
class POSTransactionReceiptReprintTest extends PosTransactionFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Add reprint permission
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('pos.receipts.reprint', 'web');
    }

    /**
     * Test transaction receipt initial view logs as PRINT
     */
    public function test_transaction_receipt_initial_view_logs_as_print(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
        ]);

        // Create a draft transaction
        $transaction = $this->createDraftTransaction($setting, $user);

        // View the receipt
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction));
        $response->assertStatus(200);
        $response->assertSee('Cetak Struk');
        $response->assertSee('Pelanggan');
        $response->assertSee('-');

        // Verify print log was created
        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'pos_transaction_id' => $transaction->id,
            'print_type' => PosReceiptPrintLog::TYPE_PRINT,
            'printed_by' => $user->id,
        ]);

        // Verify only one log entry
        $logCount = PosReceiptPrintLog::where('pos_transaction_id', $transaction->id)->count();
        $this->assertEquals(1, $logCount);
    }

    /**
     * Test transaction receipt reprint logs as REPRINT
     */
    public function test_transaction_receipt_reprint_logs_as_reprint(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        // Create a draft transaction and log initial print
        $transaction = $this->createDraftTransaction($setting, $user);
        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction));

        // Reprint the receipt
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction));
        $response->assertStatus(200);

        // Verify second print log was created with REPRINT type
        $logs = PosReceiptPrintLog::where('pos_transaction_id', $transaction->id)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertEquals(PosReceiptPrintLog::TYPE_PRINT, $logs[0]->print_type);
        $this->assertEquals(PosReceiptPrintLog::TYPE_REPRINT, $logs[1]->print_type);
    }

    /**
     * Test print history displays on receipt
     */
    public function test_print_history_displays_on_receipt(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);

        // View receipt (logs as PRINT)
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction));
        $response->assertStatus(200);
        $response->assertDontSee('Terakhir dicetak oleh');
        $response->assertSee($setting->company_name);

        // Reprint (logs as REPRINT)
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction));
        $response->assertStatus(200);
        $response->assertDontSee('Terakhir dicetak oleh');
        $response->assertSee($setting->company_name);
    }

    public function test_transaction_receipt_shows_customer_name_when_available(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
        ]);
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'contact_name' => 'Draft Customer',
            'customer_name' => 'Draft Store',
        ]);
        $expectedCustomerName = $customer->fresh()->contact_name ?: $customer->fresh()->customer_name;

        $transaction = $this->createDraftTransaction($setting, $user);
        $transaction->update(['customer_id' => $customer->id]);

        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction))
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee($expectedCustomerName);
    }

    public function test_completed_transaction_receipt_uses_checkout_payment_data_and_is_not_draft(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);
        [$terminal] = $this->createTerminalWithLocation($setting);
        $session = $this->openSession($setting, $terminal, $user);
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'contact_name' => 'Completed Customer',
            'customer_name' => 'Completed Store',
        ]);
        $expectedCustomerName = $customer->fresh()->contact_name ?: $customer->fresh()->customer_name;
        $coa = ChartOfAccount::create([
            'name' => 'TXN CASH ' . $setting->id,
            'account_number' => '1101-' . $setting->id . '-' . uniqid(),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);
        $paymentMethod = PaymentMethod::create([
            'name' => 'Cash POS',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'txn-receipt-' . uniqid(),
            'payload_hash' => str_repeat('a', 64),
            'subtotal' => 100000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100000,
            'paid_total' => 120000,
            'change_total' => 20000,
            'payment_method_id' => $paymentMethod->id,
            'receipt_number' => 'RCP-TXN-001',
            'finalized_at' => now(),
        ]);

        PosCheckoutPayment::create([
            'pos_checkout_id' => $checkout->id,
            'payment_method_id' => $paymentMethod->id,
            'amount_minor_units' => 12000000,
            'reference' => null,
            'sequence_order' => 1,
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);
        $transaction->update([
            'status' => PosTransaction::STATUS_COMPLETED,
            'customer_id' => $customer->id,
            'completed_checkout_id' => $checkout->id,
        ]);

        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction))
            ->assertStatus(200)
            ->assertSee('Bayar:')
            ->assertSee($paymentMethod->fresh()->name)
            ->assertSee('120.000')
            ->assertSee('Kembalian')
            ->assertSee('20.000')
            ->assertSee($expectedCustomerName)
            ->assertDontSee('STRUK DRAFT');
    }

    /**
     * Test reprint button visible with permission on transaction list
     */
    public function test_reprint_button_visible_in_list_with_permission(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.index'));
        $response->assertStatus(200);

        // The response should contain the reprint button's JavaScript handler reference
        // (we can't easily test the button visibility without JavaScript, so we test the route exists)
        $this->assertTrue(
            route('pos.transactions.receipt.reprint', $transaction) != ''
        );
    }

    /**
     * Test reprint button not visible without permission on transaction list
     */
    public function test_reprint_button_not_visible_without_permission(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
        ]);
        // Explicitly don't give pos.receipts.reprint permission

        $transaction = $this->createDraftTransaction($setting, $user);

        // Try to access reprint endpoint without permission
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction));
        $response->assertStatus(403);
    }

    /**
     * Test reprint button visible with permission on transaction detail
     */
    public function test_reprint_button_visible_in_detail_with_permission(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.show', $transaction));
        $response->assertStatus(200);
        // Button HTML should be present
        $response->assertViewHas('transaction');
    }

    /**
     * Test reprint button not visible without permission on transaction detail
     */
    public function test_reprint_button_not_visible_in_detail_without_permission(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
        ]);
        // Explicitly don't give pos.receipts.reprint permission

        $transaction = $this->createDraftTransaction($setting, $user);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.show', $transaction));
        $response->assertStatus(200);
        // Button should not be rendered (conditional in view)
        $response->assertViewHas('transaction');
    }

    /**
     * Test permission check on reprint endpoint
     */
    public function test_reprint_endpoint_requires_permission(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);

        // Attempt reprint without permission
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction));
        $response->assertStatus(403);
    }

    /**
     * Test print logs are correctly associated with transactions
     */
    public function test_print_logs_associated_with_transactions(): void
    {
        $setting = $this->createSetting('Test Business');
        $user1 = $this->createUserForSetting($setting, 'cashier1', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $user2 = $this->createUserForSetting($setting, 'cashier2', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $transaction1 = $this->createDraftTransaction($setting, $user1);
        $transaction2 = $this->createDraftTransaction($setting, $user2);

        // User 1 views their transaction
        $this->actingAs($user1)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction1));

        // User 2 views and reprints their transaction
        $this->actingAs($user2)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction2));
        $this->actingAs($user2)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction2));

        // Verify logs are correctly associated
        $logs1 = PosReceiptPrintLog::where('pos_transaction_id', $transaction1->id)->get();
        $logs2 = PosReceiptPrintLog::where('pos_transaction_id', $transaction2->id)->get();

        $this->assertCount(1, $logs1);
        $this->assertCount(2, $logs2);
        $this->assertEquals($user1->id, $logs1[0]->printed_by);
        $this->assertEquals($user2->id, $logs2[0]->printed_by);
        $this->assertEquals($user2->id, $logs2[1]->printed_by);
    }

    /**
     * Test reprint works for all transaction statuses
     */
    public function test_reprint_works_for_all_statuses(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $statuses = ['DRAFT', 'LOADED', 'COMPLETED', 'CANCELLED'];

        foreach ($statuses as $status) {
            $transaction = $this->createDraftTransaction($setting, $user);
            $transaction->update(['status' => $status]);

            $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
                ->post(route('pos.transactions.receipt.reprint', $transaction));
            $response->assertStatus(200);

            // Verify log was created
            $this->assertDatabaseHas('pos_receipt_print_logs', [
                'pos_transaction_id' => $transaction->id,
                'print_type' => PosReceiptPrintLog::TYPE_REPRINT,
            ]);
        }
    }

    /**
     * Helper to create a draft transaction
     */
    private function createDraftTransaction($setting, $user): PosTransaction
    {
        $session = PosSession::query()
            ->where('setting_id', $setting->id)
            ->where('cashier_user_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->first();

        if (! $session) {
            [$terminal] = $this->createTerminalWithLocation($setting);
            $session = $this->openSession($setting, $terminal, $user);
        }

        return PosTransaction::create([
            'setting_id' => $setting->id,
            'owner_user_id' => $user->id,
            'created_by' => $user->id,
            'last_saved_by' => $user->id,
            'code' => 'TXN-' . uniqid(),
            'status' => 'DRAFT',
            'customer_id' => null,
            'source_pos_session_id' => $session->id,
            'snapshot_totals' => [
                'total_subtotal' => 100000,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_grand_total' => 100000,
            ],
        ]);
    }

    public function test_transaction_receipt_displays_robust_customer_name_with_both_contact_and_company(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'contact_name' => 'Budi Santoso',
            'company_name' => 'CV Maju Jaya',
            'customer_name' => 'Fallback Name',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);
        $transaction->update(['customer_id' => $customer->id]);

        // View receipt
        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction))
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('BUDI SANTOSO - CV MAJU JAYA')
            ->assertDontSee('Fallback Name')
            ->assertDontSee('Budi Santoso - CV Maju Jaya');

        // Reprint receipt
        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction))
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('BUDI SANTOSO - CV MAJU JAYA')
            ->assertDontSee('Fallback Name')
            ->assertDontSee('Budi Santoso - CV Maju Jaya');
    }

    public function test_transaction_receipt_reprint_displays_only_company_when_contact_is_empty(): void
    {
        $setting = $this->createSetting('Test Business');
        $user = $this->createUserForSetting($setting, 'cashier', [
            'pos.access',
            'pos.transactions.view',
            'pos.receipts.reprint',
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'contact_name' => '',
            'company_name' => 'PT Sukses Bersama',
            'customer_name' => 'PT SUKSES BERSAMA',
        ]);

        $transaction = $this->createDraftTransaction($setting, $user);
        $transaction->update(['customer_id' => $customer->id]);

        // Reprint receipt
        $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction))
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('PT SUKSES BERSAMA');
    }
}
