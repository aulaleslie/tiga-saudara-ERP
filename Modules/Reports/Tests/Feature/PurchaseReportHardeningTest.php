<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\People\Entities\Supplier;
use App\Models\User;
use Spatie\Tags\Tag;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PurchaseReportHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'purchaseReports.access']);
        Role::create(['name' => 'Staff']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    protected function makeSupplier(string $name = 'Supplier 1', ?int $settingId = null): Supplier
    {
        static $counter = 0;
        $counter++;
        return Supplier::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'supplier_name' => $name,
            'supplier_email' => "s{$counter}@test.com",
            'supplier_phone' => (string) $counter,
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
    }

    protected function makePurchase(Supplier $supplier, array $overrides = []): Purchase
    {
        static $ref = 0;
        $ref++;
        return Purchase::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'PR-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    protected function makePayment(Purchase $purchase, float $amount, string $status = null): PurchasePayment
    {
        static $pref = 0;
        $pref++;
        return PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'reference' => 'PP-' . str_pad($pref, 4, '0', STR_PAD_LEFT),
            'amount' => $amount,
            'status' => $status ?? PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
        ]);
    }

    protected function makePurchaseDetail(Purchase $purchase, array $overrides = []): PurchaseDetail
    {
        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    // ─── Page render ───────────────────────────────────────────────────────────

    /** @test */
    public function it_can_render_the_purchase_report_page()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));
        $response->assertStatus(200);
    }

    /** @test */
    public function it_shows_purchase_report_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertSee('Daftar Pembelian');
    }

    /** @test */
    public function it_hides_purchase_report_menu_for_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertDontSee('Daftar Pembelian');
    }

    /** @test */
    public function it_renders_page_title_and_breadcrumb_as_daftar_pembelian()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.purchase-report.index'))
            ->assertStatus(200)
            ->assertSee('Daftar Pembelian');
    }

    // ─── Task 1.2: Default date range is current month ─────────────────────────

    /** @test */
    public function it_defaults_start_and_end_date_to_current_month()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    // ─── Task 1.3: One purchase with multiple details → multiple report rows ───

    /** @test */
    public function it_returns_one_row_per_purchase_detail_for_a_single_purchase()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product B']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product C']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 3;
            });
    }

    /** @test */
    public function it_produces_separate_rows_for_each_detail_of_different_purchases()
    {
        $supplier = $this->makeSupplier();
        $p1 = $this->makePurchase($supplier, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $p2 = $this->makePurchase($supplier, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $this->makePurchaseDetail($p1, ['product_name' => 'P1-A']);
        $this->makePurchaseDetail($p1, ['product_name' => 'P1-B']);
        $this->makePurchaseDetail($p2, ['product_name' => 'P2-A']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 3;
            });
    }

    // ─── Task 1.4: Bahasa Indonesia labels, no Tipe transaksi/product/Gudang filters

    /** @test */
    public function it_does_not_render_a_tipe_transaksi_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.purchase-report.index'))
            ->assertStatus(200)
            ->assertDontSee('Tipe transaksi');
    }

    /** @test */
    public function it_does_not_render_a_product_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));
        $response->assertStatus(200);
        // Ensure no product-filter input is present
        $response->assertDontSee('wire:model="productId"');
        $response->assertDontSee('wire:model="productSearch"');
    }

    /** @test */
    public function it_does_not_render_a_gudang_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));
        $response->assertStatus(200);
        $response->assertDontSee('wire:model="locationId"');
        $response->assertDontSee('wire:model="locationSearch"');
    }

    /** @test */
    public function it_renders_bahasa_indonesia_filter_labels()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-report.index'));
        $response->assertStatus(200);
        $response->assertSee('Tanggal awal');
        $response->assertSee('Tanggal akhir');
        $response->assertSee('Supplier');
        $response->assertSee('Status Dokumen');
        $response->assertSee('Status Pembayaran');
        $response->assertSee('Grup dengan tag');
    }

    // ─── Task 1.5: Supplier/tag min 2-char lookup ──────────────────────────────

    /** @test */
    public function it_only_triggers_supplier_lookup_after_min_chars()
    {
        $this->makeSupplier('Test Supplier');
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('supplierSearch', 'T')
            ->assertSet('supplierOptions', [])
            ->set('supplierSearch', 'Te')
            ->assertCount('supplierOptions', 1);
    }

    /** @test */
    public function it_only_triggers_tag_lookup_after_min_chars_and_respects_locale()
    {
        $tag = Tag::create(['name' => ['en' => 'Test Tag', 'id' => 'Tag Tes']]);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('tagSearch', 'T')
            ->assertSet('tagOptions', [])
            ->set('tagSearch', 'Te')
            ->assertCount('tagOptions', 1)
            ->assertSet('tagOptions.0.id', $tag->id);
    }

    // ─── Task 1.6: Multi-select OR behavior ───────────────────────────────────

    /** @test */
    public function it_filters_by_multiple_suppliers_with_or_semantics()
    {
        $s1 = $this->makeSupplier('Alpha Supplier');
        $s2 = $this->makeSupplier('Beta Supplier');
        $s3 = $this->makeSupplier('Gamma Supplier');
        $p1 = $this->makePurchase($s1, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $p2 = $this->makePurchase($s2, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $p3 = $this->makePurchase($s3, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $this->makePurchaseDetail($p1);
        $this->makePurchaseDetail($p2);
        $this->makePurchaseDetail($p3);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('supplierIds', [$s1->id, $s2->id])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($p1, $p2, $p3) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($p1->id, $ids)
                    && in_array($p2->id, $ids)
                    && !in_array($p3->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_tags_with_or_semantics()
    {
        $tagA = Tag::create(['name' => ['en' => 'TagA']]);
        $tagB = Tag::create(['name' => ['en' => 'TagB']]);
        $tagC = Tag::create(['name' => ['en' => 'TagC']]);

        $supplier = $this->makeSupplier();
        $pA = $this->makePurchase($supplier, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $pB = $this->makePurchase($supplier, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $pC = $this->makePurchase($supplier, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $pA->attachTag($tagA);
        $pB->attachTag($tagB);
        $pC->attachTag($tagC);
        $this->makePurchaseDetail($pA);
        $this->makePurchaseDetail($pB);
        $this->makePurchaseDetail($pC);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('tagIds', [$tagA->id, $tagB->id])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($pA, $pB, $pC) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($pA->id, $ids)
                    && in_array($pB->id, $ids)
                    && !in_array($pC->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_document_statuses_with_or_semantics()
    {
        $supplier = $this->makeSupplier();
        $pApproved = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_APPROVED,
        ]);
        $pReceived = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_RECEIVED,
        ]);
        $pDrafted = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_DRAFTED,
        ]);
        $this->makePurchaseDetail($pApproved);
        $this->makePurchaseDetail($pReceived);
        $this->makePurchaseDetail($pDrafted);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('documentStatuses', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($pApproved, $pReceived, $pDrafted) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($pApproved->id, $ids)
                    && in_array($pReceived->id, $ids)
                    && !in_array($pDrafted->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_payment_statuses_with_or_semantics()
    {
        $supplier = $this->makeSupplier();
        $pUnpaid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $pPartial = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 500,
            'due_amount' => 500,
        ]);
        $pPaid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePayment($pPartial, 500);
        $this->makePayment($pPaid, 1000);
        $this->makePurchaseDetail($pUnpaid);
        $this->makePurchaseDetail($pPartial);
        $this->makePurchaseDetail($pPaid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID', 'PARTIAL'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($pUnpaid, $pPartial, $pPaid) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($pUnpaid->id, $ids)
                    && in_array($pPartial->id, $ids)
                    && !in_array($pPaid->id, $ids);
            });
    }

    // ─── Task 1.7: Derived payment status ignores invalidated payments ─────────

    /** @test */
    public function it_derives_payment_status_as_unpaid_when_all_payments_are_invalidated()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $this->makePayment($purchase, 1000, PurchasePayment::STATUS_INVALIDATED);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('purchase_id')->contains($purchase->id);
            });
    }

    /** @test */
    public function it_derives_payment_status_as_partial_using_active_payments_only()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        // One active payment of 300 and one invalidated of 700 → derived = PARTIAL
        $this->makePayment($purchase, 300);
        $this->makePayment($purchase, 700, PurchasePayment::STATUS_INVALIDATED);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['PARTIAL'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('purchase_id')->contains($purchase->id);
            });
    }

    /** @test */
    public function it_derives_payment_status_from_header_amounts_when_no_payment_rows_exist()
    {
        $supplier = $this->makeSupplier();
        $partial = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 500,
            'due_amount' => 500,
        ]);
        $paid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePurchaseDetail($partial);
        $this->makePurchaseDetail($paid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($partial, $paid) {
                $rows = $purchases->keyBy('purchase_id');

                return \App\Services\Reports\PurchaseReportQueryService::mapRow($rows[$partial->id])['Status Pembayaran'] === 'Terbayar Sebagian'
                    && \App\Services\Reports\PurchaseReportQueryService::mapRow($rows[$paid->id])['Status Pembayaran'] === 'Lunas';
            });
    }

    /** @test */
    public function it_filters_payment_status_from_header_amounts_when_no_payment_rows_exist()
    {
        $supplier = $this->makeSupplier();
        $unpaid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $paid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePurchaseDetail($unpaid);
        $this->makePurchaseDetail($paid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['PAID'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($unpaid, $paid) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($paid->id, $ids) && !in_array($unpaid->id, $ids);
            });
    }

    /** @test */
    public function it_ignores_stale_header_amounts_when_payment_rows_exist()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePayment($purchase, 1000, PurchasePayment::STATUS_INVALIDATED);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('purchase_id')->contains($purchase->id);
            });
    }

    // ─── Task 1.8: Gudang column from approved receiving-note locations ─────────

    /** @test */
    public function it_populates_gudang_column_from_approved_receiving_note_location()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $detail = $this->makePurchaseDetail($purchase);

        $location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Utama',
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $location->id,
            'date' => now()->format('Y-m-d'),
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 1,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $row = $purchases->first();
                return $row && stripos($row->gudang ?? '', 'Gudang Utama') !== false;
            });
    }

    /** @test */
    public function it_joins_multiple_distinct_approved_receiving_locations_in_gudang_without_duplicating_rows()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $detail = $this->makePurchaseDetail($purchase);

        $loc1 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Gudang A']);
        $loc2 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Gudang B']);

        $rn1 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $loc1->id,
            'date' => now()->format('Y-m-d'),
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        $rn2 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $loc2->id,
            'date' => now()->format('Y-m-d'),
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create(['received_note_id' => $rn1->id, 'po_detail_id' => $detail->id, 'quantity_received' => 1]);
        ReceivedNoteDetail::create(['received_note_id' => $rn2->id, 'po_detail_id' => $detail->id, 'quantity_received' => 1]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                // Only 1 detail → 1 row, Gudang contains both location names
                if ($purchases->count() !== 1) {
                    return false;
                }
                $gudang = $purchases->first()->gudang ?? '';
                return stripos($gudang, 'Gudang A') !== false && stripos($gudang, 'Gudang B') !== false;
            });
    }

    // ─── Existing behaviour: date filters & scope ──────────────────────────────

    /** @test */
    public function it_filters_purchases_by_date_range()
    {
        $supplier = $this->makeSupplier();
        $p1 = $this->makePurchase($supplier, ['date' => '2026-01-15', 'due_date' => '2026-01-15']);
        $p2 = $this->makePurchase($supplier, ['date' => '2026-02-01', 'due_date' => '2026-02-01']);
        $this->makePurchaseDetail($p1);
        $this->makePurchaseDetail($p2);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class, ['isGlobal' => false])
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-01-01')
            ->set('endDate', '2026-01-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 1;
            });
    }

    /** @test */
    public function it_enforces_setting_id_scope_in_non_global_mode()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '987654321',
            'notification_email' => 'other-notify@example.com',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $s1 = $this->makeSupplier('S1', $this->setting->id);
        $s2 = $this->makeSupplier('S2', $otherSetting->id);
        $p1 = $this->makePurchase($s1, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $p2 = $this->makePurchase($s2, ['date' => now()->startOfMonth()->format('Y-m-d'), 'setting_id' => $otherSetting->id]);
        $this->makePurchaseDetail($p1);
        $this->makePurchaseDetail($p2);

        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->every(fn($row) => $row->purchase->setting_id === $this->setting->id);
            });
    }

    /** @test */
    public function it_shows_empty_state_when_no_records_match()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2020-01-01')
            ->set('endDate', '2020-01-01')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 0;
            });
    }

    /** @test */
    public function it_rejects_end_date_before_start_date()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-02-01')
            ->set('endDate', '2026-01-01')
            ->call('applyFilters')
            ->assertHasErrors(['endDate']);
    }

    /** @test */
    public function it_rejects_invalid_document_status_value()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('documentStatuses', ['INVALID_STATUS'])
            ->call('applyFilters')
            ->assertHasErrors(['documentStatuses.*']);
    }

    /** @test */
    public function it_rejects_invalid_payment_status_value()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('paymentStatuses', ['INVALID_PAYMENT'])
            ->call('applyFilters')
            ->assertHasErrors(['paymentStatuses.*']);
    }

    /** @test */
    public function it_rejects_nonexistent_supplier()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('supplierIds', [99999])
            ->call('applyFilters')
            ->assertHasErrors(['supplierIds.*']);
    }

    /** @test */
    public function it_rejects_nonexistent_tag()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('tagIds', [99999])
            ->call('applyFilters')
            ->assertHasErrors(['tagIds.*']);
    }

    /** @test */
    public function it_updates_dates_when_period_preset_changes_without_auto_filtering()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('periodPreset', 'this_month')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->assertSet('filterTriggered', false);
    }

    /** @test */
    public function it_filters_by_date_basis_transaction_date_and_due_date()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->subDays(5)->format('Y-m-d'),
            'due_date' => now()->addDays(5)->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->subDays(6)->format('Y-m-d'))
            ->set('endDate', now()->subDays(4)->format('Y-m-d'))
            ->set('dateBasis', 'transaction_date')
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('purchase_id')->contains($purchase->id);
            });

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->addDays(4)->format('Y-m-d'))
            ->set('endDate', now()->addDays(6)->format('Y-m-d'))
            ->set('dateBasis', 'due_date')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('purchase_id')->contains($purchase->id);
            });
    }

    /** @test */
    public function it_filters_document_status()
    {
        $supplier = $this->makeSupplier();
        $pApproved = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_APPROVED,
        ]);
        $pDrafted = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_DRAFTED,
        ]);
        $this->makePurchaseDetail($pApproved);
        $this->makePurchaseDetail($pDrafted);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('documentStatuses', [Purchase::STATUS_APPROVED])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($pApproved, $pDrafted) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($pApproved->id, $ids) && !in_array($pDrafted->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_unpaid_derived_payment_status()
    {
        $supplier = $this->makeSupplier();
        $pUnpaid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
        ]);
        $pPaid = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
        ]);
        $this->makePayment($pPaid, 1000);
        $this->makePurchaseDetail($pUnpaid);
        $this->makePurchaseDetail($pPaid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID'])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($pUnpaid, $pPaid) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($pUnpaid->id, $ids) && !in_array($pPaid->id, $ids);
            });
    }

    /** @test */
    public function it_exports_normalized_status_label()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Purchase::STATUS_APPROVED,
        ]);
        $detail = $this->makePurchaseDetail($purchase);

        $mapped = \App\Services\Reports\PurchaseReportQueryService::mapRow($detail);

        $this->assertEquals('Disetujui', $mapped['Status Dokumen']);
    }
}
