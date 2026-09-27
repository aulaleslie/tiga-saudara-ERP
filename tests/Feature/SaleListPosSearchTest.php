<?php

namespace Tests\Feature;

use App\Livewire\Sale\SaleTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleListPosSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $user = User::factory()->create();
        $this->actingAs($user);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        \Illuminate\Support\Facades\DB::table('locations')->insert([
            'id' => 1,
            'setting_id' => 1,
            'name' => 'Test Location',
        ]);

        \Illuminate\Support\Facades\DB::table('pos_terminals')->insert([
            'id' => 1,
            'setting_id' => 1,
            'code' => 'TERM-01',
            'name' => 'Terminal 1',
            'location_id' => 1,
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('pos_sessions')->insert([
            'id' => 1,
            'setting_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'opening_float_total' => 0,
        ]);

        session(['setting_id' => 1]);
    }

    private function createCustomer(): Customer
    {
        return Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);
    }

    private function createSale(Customer $customer, string $reference): Sale
    {
        return Sale::create([
            'date' => now(),
            'reference' => $reference,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'setting_id' => 1,
        ]);
    }

    public function test_search_sale_by_pos_transaction_code_for_inline_checkout(): void
    {
        $customer = $this->createCustomer();
        $sale1 = $this->createSale($customer, 'SL-POS-1');
        $sale2 = $this->createSale($customer, 'SL-POS-2');

        $transaction1 = PosTransaction::create([
            'setting_id' => 1,
            'code' => 'POS-TX-12345',
            'created_by' => 1,
            'owner_user_id' => 1,
            'last_saved_by' => 1,
            'source_pos_session_id' => 1,
            'date' => now(),
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'status' => 'COMPLETED',
        ]);

        $checkout1 = PosCheckout::create([
            'setting_id' => 1,
            'pos_transaction_id' => $transaction1->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'sale_id' => $sale1->id,
            'idempotency_key' => 'idemp-1',
            'payload_hash' => 'hash-1',
            'receipt_number' => 'RCP-123',
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'POS-TX-123')
            ->call('searchSubmit')
            ->assertSee('SL-POS-1')
            ->assertDontSee('SL-POS-2');
    }

    public function test_search_sale_by_receipt_number_for_inline_checkout(): void
    {
        $customer = $this->createCustomer();
        $sale1 = $this->createSale($customer, 'SL-POS-1');
        $sale2 = $this->createSale($customer, 'SL-POS-2');

        $checkout1 = PosCheckout::create([
            'setting_id' => 1,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'sale_id' => $sale1->id,
            'idempotency_key' => 'idemp-2',
            'payload_hash' => 'hash-2',
            'receipt_number' => 'RCP-12345',
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'RCP-123')
            ->call('searchSubmit')
            ->assertSee('SL-POS-1')
            ->assertDontSee('SL-POS-2');
    }

    public function test_search_sale_by_pos_transaction_code_for_split_checkout_returns_all_sales(): void
    {
        $customer = $this->createCustomer();
        $sale1 = $this->createSale($customer, 'SL-SPLIT-1');
        $sale2 = $this->createSale($customer, 'SL-SPLIT-2');
        $sale3 = $this->createSale($customer, 'SL-SPLIT-3'); // Unrelated

        $transaction = PosTransaction::create([
            'setting_id' => 1,
            'code' => 'POS-TX-SPLIT-999',
            'created_by' => 1,
            'owner_user_id' => 1,
            'last_saved_by' => 1,
            'source_pos_session_id' => 1,
            'date' => now(),
            'subtotal' => 2000,
            'tax_total' => 0,
            'grand_total' => 2000,
            'paid_total' => 2000,
            'status' => 'COMPLETED',
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => 1,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'pos_transaction_id' => $transaction->id,
            'idempotency_key' => 'idemp-3',
            'payload_hash' => 'hash-3',
            'receipt_number' => 'RCP-SPLIT',
            'subtotal' => 2000,
            'tax_total' => 0,
            'grand_total' => 2000,
            'paid_total' => 2000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'sale_id' => $sale1->id,
            'split_key' => 'tax_1',
            'subtotal' => 1000,
            'tax_total' => 0,
            'tax_bucket' => '[]',
            'grand_total' => 1000,
            'paid_total' => 1000,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'sale_id' => $sale2->id,
            'split_key' => 'tax_2',
            'subtotal' => 1000,
            'tax_total' => 0,
            'tax_bucket' => '[]',
            'grand_total' => 1000,
            'paid_total' => 1000,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'POS-TX-SPLIT')
            ->call('searchSubmit')
            ->assertSee('SL-SPLIT-1')
            ->assertSee('SL-SPLIT-2')
            ->assertDontSee('SL-SPLIT-3');
    }

    public function test_search_sale_by_receipt_number_for_split_checkout_returns_all_sales(): void
    {
        $customer = $this->createCustomer();
        $sale1 = $this->createSale($customer, 'SL-SPLIT-1');
        $sale2 = $this->createSale($customer, 'SL-SPLIT-2');
        $sale3 = $this->createSale($customer, 'SL-SPLIT-3'); // Unrelated

        $checkout = PosCheckout::create([
            'setting_id' => 1,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'idempotency_key' => 'idemp-4',
            'payload_hash' => 'hash-4',
            'receipt_number' => 'RCP-SPLIT-999',
            'subtotal' => 2000,
            'tax_total' => 0,
            'grand_total' => 2000,
            'paid_total' => 2000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'sale_id' => $sale1->id,
            'split_key' => 'tax_1',
            'subtotal' => 1000,
            'tax_total' => 0,
            'tax_bucket' => '[]',
            'grand_total' => 1000,
            'paid_total' => 1000,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'sale_id' => $sale2->id,
            'split_key' => 'tax_2',
            'subtotal' => 1000,
            'tax_total' => 0,
            'tax_bucket' => '[]',
            'grand_total' => 1000,
            'paid_total' => 1000,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'RCP-SPLIT-999')
            ->call('searchSubmit')
            ->assertSee('SL-SPLIT-1')
            ->assertSee('SL-SPLIT-2')
            ->assertDontSee('SL-SPLIT-3');
    }

    public function test_pos_sale_displays_its_transaction_code(): void
    {
        $customer = $this->createCustomer();
        $sale = $this->createSale($customer, 'SL-POS-DISPLAY');

        $transaction = PosTransaction::create([
            'setting_id' => 1,
            'code' => 'POS-TX-DISPLAY-123',
            'created_by' => 1,
            'owner_user_id' => 1,
            'last_saved_by' => 1,
            'source_pos_session_id' => 1,
            'date' => now(),
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'status' => 'COMPLETED',
        ]);

        PosCheckout::create([
            'setting_id' => 1,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'sale_id' => $sale->id,
            'idempotency_key' => 'idemp-display',
            'payload_hash' => 'hash-display',
            'receipt_number' => 'RCP-DISPLAY',
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->assertSee('POS-TX-DISPLAY-123');
    }

    public function test_placeholder_for_non_pos_and_unlinked_sales(): void
    {
        $customer = $this->createCustomer();
        
        // Case 1: Plain manual Sale
        $sale1 = $this->createSale($customer, 'SL-MANUAL');
        
        // Case 2: POS Sale where pos_checkouts.pos_transaction_id is NULL
        $sale2 = $this->createSale($customer, 'SL-UNLINKED');
        PosCheckout::create([
            'setting_id' => 1,
            'pos_transaction_id' => null,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'sale_id' => $sale2->id,
            'idempotency_key' => 'idemp-unlinked',
            'payload_hash' => 'hash-unlinked',
            'receipt_number' => 'RCP-UNLINKED',
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        // Case 3: POS Sale with linked transaction
        $sale3 = $this->createSale($customer, 'SL-LINKED');
        $transaction = PosTransaction::create([
            'setting_id' => 1,
            'code' => 'POS-TX-LINKED-123',
            'created_by' => 1,
            'owner_user_id' => 1,
            'last_saved_by' => 1,
            'source_pos_session_id' => 1,
            'date' => now(),
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'status' => 'COMPLETED',
        ]);
        PosCheckout::create([
            'setting_id' => 1,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => 1,
            'terminal_id' => 1,
            'cashier_user_id' => 1,
            'sale_id' => $sale3->id,
            'idempotency_key' => 'idemp-linked',
            'payload_hash' => 'hash-linked',
            'receipt_number' => 'RCP-LINKED',
            'subtotal' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_total' => 1000,
            'change_total' => 0,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->assertSee('SL-MANUAL')
            ->assertSee('SL-UNLINKED')
            ->assertSee('POS-TX-LINKED-123')
            ->assertSeeHtml('<span class="text-muted">-</span>'); // Should see placeholder
    }

    public function test_existing_search_fields_still_match(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'John Doe Search',
            'customer_email' => 'johndoesearch@example.com',
            'customer_phone' => '0812345678',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-FIND-ME',
            'imported_sales_reference_number' => 'IMP-FIND-ME',
            'tax_ref_no' => 'TAX-FIND-ME',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'setting_id' => 1,
        ]);
        
        // Setup tag
        $tag = \Spatie\Tags\Tag::findOrCreate('SearchTagMe');
        $sale->attachTag($tag);
        // Setup product
        \Illuminate\Support\Facades\DB::table('categories')->insert([
            'id' => 1,
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'setting_id' => 1,
            'created_by' => 1,
        ]);
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => 1,
            'category_id' => 1,
            'product_name' => 'ProductFindMe',
            'product_code' => 'PROD-FIND-ME',
            'product_price' => 1000,
            'product_cost' => 1000,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'setting_id' => 1,
        ]);

        // Setup details
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => 1,
            'product_name' => 'ProductFindMe',
            'product_code' => 'PROD-FIND-ME',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $decoy = $this->createSale($this->createCustomer(), 'SL-DECOY');

        // By Reference
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'SL-FIND-ME')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By imported ref
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'IMP-FIND-ME')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By tax ref
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'TAX-FIND-ME')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By customer name
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'John Doe Search')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By product name
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'ProductFindMe')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By product code
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'PROD-FIND-ME')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');

        // By tag
        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'SearchTagMe')
            ->call('searchSubmit')
            ->assertSee('SL-FIND-ME')
            ->assertDontSee('SL-DECOY');
    }

    public function test_no_per_row_queries_for_pos_code(): void
    {
        $customer = $this->createCustomer();

        // Create 5 sales
        for ($i = 1; $i <= 5; $i++) {
            $sale = $this->createSale($customer, "SL-POS-PERF-$i");
            $transaction = PosTransaction::create([
                'setting_id' => 1,
                'code' => "POS-TX-PERF-$i",
                'created_by' => 1,
                'owner_user_id' => 1,
                'last_saved_by' => 1,
                'source_pos_session_id' => 1,
                'date' => now(),
                'subtotal' => 1000,
                'tax_total' => 0,
                'grand_total' => 1000,
                'paid_total' => 1000,
                'status' => 'COMPLETED',
            ]);
            PosCheckout::create([
                'setting_id' => 1,
                'pos_transaction_id' => $transaction->id,
                'pos_session_id' => 1,
                'terminal_id' => 1,
                'cashier_user_id' => 1,
                'sale_id' => $sale->id,
                'idempotency_key' => "idemp-perf-$i",
                'payload_hash' => "hash-perf-$i",
                'receipt_number' => "RCP-PERF-$i",
                'subtotal' => 1000,
                'tax_total' => 0,
                'grand_total' => 1000,
                'paid_total' => 1000,
                'change_total' => 0,
                'status' => PosCheckout::STATUS_POSTED,
            ]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Livewire::test(SaleTable::class, ['settingId' => 1])->assertSee('POS-TX-PERF-1');
        $queries5 = \Illuminate\Support\Facades\DB::getQueryLog();
        $queryCount5 = count($queries5);
        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        // Create 5 more sales
        for ($i = 6; $i <= 10; $i++) {
            $sale = $this->createSale($customer, "SL-POS-PERF-$i");
            $transaction = PosTransaction::create([
                'setting_id' => 1,
                'code' => "POS-TX-PERF-$i",
                'created_by' => 1,
                'owner_user_id' => 1,
                'last_saved_by' => 1,
                'source_pos_session_id' => 1,
                'date' => now(),
                'subtotal' => 1000,
                'tax_total' => 0,
                'grand_total' => 1000,
                'paid_total' => 1000,
                'status' => 'COMPLETED',
            ]);
            PosCheckout::create([
                'setting_id' => 1,
                'pos_transaction_id' => $transaction->id,
                'pos_session_id' => 1,
                'terminal_id' => 1,
                'cashier_user_id' => 1,
                'sale_id' => $sale->id,
                'idempotency_key' => "idemp-perf-$i",
                'payload_hash' => "hash-perf-$i",
                'receipt_number' => "RCP-PERF-$i",
                'subtotal' => 1000,
                'tax_total' => 0,
                'grand_total' => 1000,
                'paid_total' => 1000,
                'change_total' => 0,
                'status' => PosCheckout::STATUS_POSTED,
            ]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Livewire::test(SaleTable::class, ['settingId' => 1])->assertSee('POS-TX-PERF-10');
        $queries10 = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $posQueries = collect($queries10)->filter(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'pos_checkouts') || str_contains($sql, 'pos_transactions');
        });

        // Eager loading should result in a fixed small number of queries for the POS tables,
        // regardless of whether there are 5 or 10 rows.
        // Usually 1 for pos_checkouts, 1 for pos_transactions (or 2 of each for checkoutSale path)
        $this->assertLessThan(5, $posQueries->count(), 'POS tables should be eager loaded, not queried per row');
    }
}
