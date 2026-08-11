<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use App\Services\MonetaryEdit\MonetaryEditException;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleMonetaryEditService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Post-dispatch monetary editing of a Sale.
 *
 * Every case runs against a document with real dispatch details, bundle rows,
 * and non-null cost snapshots, so a regression back to
 * SaleService::updateSale() shows up as vanished links or rewritten HPP rather
 * than passing silently.
 */
class SaleMonetaryEditTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Customer $customer;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        foreach (['sales.edit', 'sales.approved.edit', 'sales.dispatched.monetary.edit'] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.edit', 'sales.dispatched.monetary.edit']);
        // Spatie caches the permission map process-wide; a stale map leaks in
        // from earlier suites during a full run and denies the route gate.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_code' => 'C-01',
            'category_name' => 'Cat 1',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->productA = $this->makeProduct($category->id, 'TEST-01');
        $this->productB = $this->makeProduct($category->id, 'TEST-02');

        Cart::instance('sale')->destroy();
    }

    private function makeProduct(int $categoryId, string $code): Product
    {
        return Product::create([
            'product_name' => 'Product ' . $code,
            'product_code' => $code,
            'product_quantity' => 100,
            'product_price' => 1000,
            'product_cost' => 800,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'category_id' => $categoryId,
            'product_barcode_symbology' => 'CODE128',
            'setting_id' => $this->setting->id,
        ]);
    }

    /**
     * A dispatched Sale carrying the links this workflow must preserve:
     * dispatch details, cost snapshots, and (optionally) bundle rows.
     *
     * @param  array<int, array{product: Product, quantity: int, price: float}>  $lines
     */
    private function makeDispatchedSale(array $lines, string $status = Sale::STATUS_DISPATCHED, bool $withBundle = false): Sale
    {
        $total = array_sum(array_map(fn ($l) => $l['quantity'] * $l['price'], $lines));

        $sale = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SL-' . fake()->unique()->numerify('####'),
            'status' => $status,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->setting->id,
            'total_amount' => $total,
            'due_amount' => $total,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $dispatchId = DB::table('dispatches')->insertGetId([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $line) {
            $detail = SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->product_name,
                'product_code' => $line['product']->product_code,
                'quantity' => $line['quantity'],
                'price' => $line['price'],
                'unit_price' => $line['price'],
                'sub_total' => $line['quantity'] * $line['price'],
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                // Non-null HPP snapshot: must survive the edit untouched.
                'cost_unit_snapshot' => 800.123456,
                'cost_total_snapshot' => round(800.123456 * $line['quantity'], 2),
                'cost_snapshot_source' => 'average_cost',
                'cost_snapshot_at' => now(),
            ]);

            // Dispatch rows link to the sale by sale_id + product_id; they are
            // preserved because the edit never deletes the document's lines.
            DB::table('dispatch_details')->insert([
                'dispatch_id' => $dispatchId,
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'dispatched_quantity' => $line['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($withBundle) {
                DB::table('sale_bundle_items')->insert([
                    'sale_detail_id' => $detail->id,
                    'sale_id' => $sale->id,
                    'bundle_id' => 1,
                    'bundle_item_id' => 1,
                    'product_id' => $this->productB->id,
                    'name' => 'Bundled Component',
                    'price' => 0,
                    'quantity' => $line['quantity'],
                    'sub_total' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $sale->fresh('saleDetails');
    }

    public function test_authorized_user_changes_line_price_in_place(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ], Sale::STATUS_DISPATCHED, true);

        $detail = $sale->saleDetails->first();
        $detailId = $detail->id;
        $snapshotBefore = (float) $detail->cost_unit_snapshot;
        $dispatchLinkIds = DB::table('dispatch_details')->pluck('id')->all();
        $bundleIds = DB::table('sale_bundle_items')->pluck('id')->all();

        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, [
            $detailId => ['unit_price' => 1200.0, 'sub_total' => 12000.0],
        ]), ['is_pkp' => false]);

        $sale->refresh();

        $this->assertSame(12000.0, (float) $sale->total_amount);
        $this->assertSame(12000.0, (float) $sale->due_amount);

        // Row identity, dispatch links, bundle rows, and cost snapshot survive.
        $this->assertSame([$detailId], $sale->saleDetails->pluck('id')->all());
        $this->assertSame($dispatchLinkIds, DB::table('dispatch_details')->pluck('id')->all());
        $this->assertSame(
            [(int) $this->productA->id],
            DB::table('dispatch_details')->pluck('product_id')->map(fn ($v) => (int) $v)->all()
        );
        $this->assertSame($bundleIds, DB::table('sale_bundle_items')->pluck('id')->all());
        $this->assertSame([$detailId], DB::table('sale_bundle_items')->pluck('sale_detail_id')->all());

        $after = SaleDetails::find($detailId);
        $this->assertSame(1200.0, (float) $after->unit_price);
        $this->assertSame(10, (int) $after->quantity);
        $this->assertSame($snapshotBefore, (float) $after->cost_unit_snapshot);
        $this->assertSame('AVERAGE_COST', strtoupper((string) $after->cost_snapshot_source));
        $this->assertNotNull($after->cost_total_snapshot);
    }

    public function test_existing_global_discount_and_shipping_are_preserved(): void
    {
        // Regression: the earlier monetary path hardcoded both to zero and
        // overwrote the stored values.
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $sale->forceFill(['discount_amount' => 500, 'shipping_amount' => 250])->save();

        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, []), ['is_pkp' => false]);

        $sale->refresh();
        $this->assertSame(500.0, (float) $sale->discount_amount);
        $this->assertSame(250.0, (float) $sale->shipping_amount);
        $this->assertSame(9750.0, (float) $sale->total_amount);
    }

    public function test_repeated_product_lines_map_to_their_own_detail_rows(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 4, 'price' => 1000.0],
            ['product' => $this->productA, 'quantity' => 6, 'price' => 2000.0],
        ]);

        [$firstId, $secondId] = $sale->saleDetails->pluck('id')->all();

        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, [
            $firstId => ['unit_price' => 1500.0, 'sub_total' => 6000.0],
            $secondId => ['unit_price' => 2000.0, 'sub_total' => 12000.0],
        ]), ['is_pkp' => false]);

        $first = SaleDetails::find($firstId);
        $second = SaleDetails::find($secondId);

        $this->assertSame(1500.0, (float) $first->unit_price);
        $this->assertSame(4, (int) $first->quantity);
        $this->assertSame(2000.0, (float) $second->unit_price);
        $this->assertSame(6, (int) $second->quantity);
    }

    public function test_quantity_change_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $sale->saleDetails->first()->id;

        $this->assertRejected($sale, $this->cartFrom($sale, [$detailId => ['qty' => 99]]), 'Kuantitas');
        $this->assertSame(10, (int) $sale->fresh()->saleDetails->first()->quantity);
    }

    public function test_product_change_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $sale->saleDetails->first()->id;

        $this->assertRejected(
            $sale,
            $this->cartFrom($sale, [$detailId => ['product_id' => $this->productB->id]]),
            'Produk'
        );
        $this->assertSame($this->productA->id, (int) $sale->fresh()->saleDetails->first()->product_id);
    }

    public function test_added_line_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        $cartItems = $this->cartFrom($sale, [])
            ->push($this->cartRow(999, $this->productB->id, 1, 100.0, 100.0));

        $this->assertRejected($sale, $cartItems, 'Jumlah baris');
        $this->assertCount(1, $sale->fresh()->saleDetails);
    }

    public function test_removed_line_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 4, 'price' => 1000.0],
            ['product' => $this->productB, 'quantity' => 6, 'price' => 2000.0],
        ]);

        $this->assertRejected($sale, $this->cartFrom($sale, [])->take(1)->values(), 'Jumlah baris');
        $this->assertCount(2, $sale->fresh()->saleDetails);
    }

    public function test_foreign_detail_id_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $other = $this->makeDispatchedSale([
            ['product' => $this->productB, 'quantity' => 3, 'price' => 500.0],
        ]);

        $foreignId = $other->saleDetails->first()->id;
        $cartItems = $this->cartFrom($sale, [])->map(function ($row) use ($foreignId) {
            $row->id = $foreignId;

            return $row;
        });

        $this->assertRejected($sale, $cartItems, 'tidak dikenali');
    }

    public function test_document_outside_active_setting_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $cartItems = $this->cartFrom($sale, []);

        session(['setting_id' => Setting::factory()->create()->id]);

        $this->assertRejected($sale, $cartItems, 'bisnis yang sedang aktif');
    }

    public function test_total_below_recorded_payments_is_rejected(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        DB::table('sale_payments')->insert([
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'SP-1',
            'amount' => 8000,
            'payment_method' => 'Cash',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detailId = $sale->saleDetails->first()->id;
        $cartItems = $this->cartFrom($sale, [
            $detailId => ['unit_price' => 200.0, 'sub_total' => 2000.0],
        ]);

        $this->assertRejected($sale, $cartItems, 'pembayaran yang sudah tercatat');

        // Payment rows untouched; header keeps its original total.
        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertSame(8000.0, (float) DB::table('sale_payments')->value('amount'));
        $this->assertSame(10000.0, (float) $sale->fresh()->total_amount);
    }

    public function test_payment_status_is_derived_without_touching_payment_rows(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        DB::table('sale_payments')->insert([
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'SP-1',
            'amount' => 6000,
            'payment_method' => 'Cash',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sale->forceFill(['paid_amount' => 6000])->save();

        $detailId = $sale->saleDetails->first()->id;

        // New total 6,000 exactly equals what has been paid → Paid.
        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, [
            $detailId => ['unit_price' => 600.0, 'sub_total' => 6000.0],
        ]), ['is_pkp' => false]);

        $sale->refresh();
        $this->assertSame(6000.0, (float) $sale->total_amount);
        // Stored uppercase by the BaseModel string mutator.
        $this->assertSame('PAID', $sale->payment_status);
        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertSame(6000.0, (float) DB::table('sale_payments')->value('amount'));
    }

    public function test_monetary_edit_leaves_stock_and_product_prices_untouched(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        ProductPrice::create([
            'product_id' => $this->productA->id,
            'setting_id' => $this->setting->id,
            'last_purchase_price' => 800,
            'average_purchase_price' => 750,
            'sale_price' => 1500,
        ]);

        $stockBefore = (float) $this->productA->fresh()->product_quantity;
        $detailId = $sale->saleDetails->first()->id;

        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, [
            $detailId => ['unit_price' => 1400.0, 'sub_total' => 14000.0],
        ]), ['is_pkp' => false]);

        $price = ProductPrice::where('product_id', $this->productA->id)->first();
        $this->assertSame(800.0, (float) $price->last_purchase_price);
        $this->assertSame(750.0, (float) $price->average_purchase_price);
        $this->assertSame($stockBefore, (float) $this->productA->fresh()->product_quantity);
    }

    public function test_partially_dispatched_sale_is_also_editable(): void
    {
        $sale = $this->makeDispatchedSale(
            [['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0]],
            Sale::STATUS_DISPATCHED_PARTIALLY
        );

        $this->assertSame(Sale::EDIT_MODE_MONETARY_ONLY, $sale->resolveEditMode());

        $detailId = $sale->saleDetails->first()->id;
        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, [
            $detailId => ['unit_price' => 900.0, 'sub_total' => 9000.0],
        ]), ['is_pkp' => false]);

        $this->assertSame(9000.0, (float) $sale->fresh()->total_amount);
    }

    public function test_forged_pkp_context_cannot_alter_tax_treatment(): void
    {
        // The owning business is non-PKP, so tax must be stripped regardless of
        // what the request claims. PKP status is a property of the document's
        // business, not a submitted value.
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $sale->forceFill(['is_tax_included' => true])->save();

        $detailId = $sale->saleDetails->first()->id;

        $cartItems = $this->cartFrom($sale, [])->map(function ($row) {
            // Forge a tax-bearing row alongside a forged PKP context.
            $row->options->put('product_tax', 1);
            $row->options->put('product_tax_amount', 1100.0);
            $row->options->put('sub_total', 11000.0);
            $row->options->put('sub_total_before_tax', 10000.0);

            return $row;
        });

        app(SaleMonetaryEditService::class)->apply($sale, $cartItems, [
            // Forged: ignored in favour of the locked document's business.
            'is_pkp' => true,
            'is_tax_included' => true,
        ]);

        $sale->refresh();
        $detail = SaleDetails::find($detailId);

        $this->assertSame(0.0, (float) $sale->tax_amount);
        $this->assertFalse((bool) $sale->is_tax_included);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertNull($detail->tax_id);
        // Non-PKP keeps the DPP as the authoritative line total.
        $this->assertSame(10000.0, (float) $detail->sub_total);
        $this->assertSame(10000.0, (float) $sale->total_amount);
    }

    public function test_submitted_header_discount_and_shipping_are_persisted(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ], Sale::STATUS_DISPATCHED, true);

        $this->addActivePayment($sale, 1000);

        $detail = $sale->saleDetails->first();
        $detailId = $detail->id;
        $snapshotUnitBefore = (float) $detail->cost_unit_snapshot;
        $snapshotTotalBefore = (float) $detail->cost_total_snapshot;
        $dispatchRowsBefore = DB::table('dispatch_details')->orderBy('id')
            ->get(['id', 'sale_id', 'product_id', 'dispatched_quantity'])->toArray();
        $bundleIdsBefore = DB::table('sale_bundle_items')->orderBy('id')->pluck('id')->all();
        $stockBefore = (float) $this->productA->fresh()->product_quantity;

        // Line price 1,200 x 10 = 12,000; less 500 fixed discount; plus 250 shipping.
        app(SaleMonetaryEditService::class)->apply(
            $sale,
            $this->cartFrom($sale, [$detailId => ['unit_price' => 1200.0, 'sub_total' => 12000.0]]),
            [
                'global_discount' => 500,
                'global_discount_type' => 'fixed',
                'shipping' => 250,
            ]
        );

        $sale->refresh();

        $this->assertSame(500.0, (float) $sale->discount_amount);
        $this->assertSame(250.0, (float) $sale->shipping_amount);
        $this->assertSame(11750.0, (float) $sale->total_amount);

        // Line monetary change landed on the same row.
        $after = SaleDetails::find($detailId);
        $this->assertSame(1200.0, (float) $after->unit_price);
        $this->assertSame(12000.0, (float) $after->sub_total);
        $this->assertSame(10, (int) $after->quantity);

        // Everything outside the monetary whitelist is untouched.
        $this->assertSame([$detailId], $sale->saleDetails->pluck('id')->all());
        $this->assertEquals($dispatchRowsBefore, DB::table('dispatch_details')->orderBy('id')
            ->get(['id', 'sale_id', 'product_id', 'dispatched_quantity'])->toArray());
        $this->assertSame($bundleIdsBefore, DB::table('sale_bundle_items')->orderBy('id')->pluck('id')->all());
        $this->assertSame($snapshotUnitBefore, (float) $after->cost_unit_snapshot);
        $this->assertSame($snapshotTotalBefore, (float) $after->cost_total_snapshot);
        $this->assertSame($stockBefore, (float) $this->productA->fresh()->product_quantity);
        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertSame(1000.0, (float) DB::table('sale_payments')->value('amount'));
    }

    public function test_submitted_zero_discount_clears_a_stored_discount(): void
    {
        // An explicit zero is a real edit, not an absent value to fall back from.
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $sale->forceFill(['discount_amount' => 500, 'shipping_amount' => 250])->save();

        app(SaleMonetaryEditService::class)->apply($sale, $this->cartFrom($sale, []), [
            'global_discount' => 0,
            'global_discount_type' => 'fixed',
            'shipping' => 0,
        ]);

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->discount_amount);
        $this->assertSame(0.0, (float) $sale->shipping_amount);
        $this->assertSame(10000.0, (float) $sale->total_amount);
    }

    private function addActivePayment(Sale $sale, float $amount): void
    {
        DB::table('sale_payments')->insert([
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'SP-' . fake()->unique()->numerify('####'),
            'amount' => $amount,
            'payment_method' => 'Cash',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_edit_screen_renders_in_monetary_only_mode(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        // The full page renders (exercising the locked controls) and labels the mode.
        $this->get(route('sales.edit', $sale->id))
            ->assertOk()
            ->assertSee('Mode Edit Moneter');
    }

    public function test_legacy_http_update_cannot_reach_destructive_path(): void
    {
        $sale = $this->makeDispatchedSale([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ], Sale::STATUS_DISPATCHED, true);

        $detailId = $sale->saleDetails->first()->id;
        $snapshotBefore = (float) $sale->saleDetails->first()->cost_unit_snapshot;

        $response = $this->put(route('sales.update', $sale->id), [
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'reference' => $sale->reference,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 9000,
            'paid_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_method' => 'Cash',
        ]);

        $response->assertStatus(422);

        // Details, dispatch links, bundles, and snapshots are all intact.
        $this->assertSame([$detailId], $sale->fresh()->saleDetails->pluck('id')->all());
        $this->assertSame(1, DB::table('dispatch_details')->where('sale_id', $sale->id)->count());
        $this->assertSame(1, DB::table('sale_bundle_items')->where('sale_detail_id', $detailId)->count());
        $this->assertSame($snapshotBefore, (float) SaleDetails::find($detailId)->cost_unit_snapshot);
        $this->assertSame(10000.0, (float) $sale->fresh()->total_amount);
    }

    /**
     * Build cart rows mirroring the persisted details, applying per-detail overrides.
     *
     * Sale cart rows are keyed by the persisted sale_details.id.
     *
     * @param  array<int, array<string, mixed>>  $overrides  keyed by detail ID
     */
    private function cartFrom(Sale $sale, array $overrides): Collection
    {
        return $sale->fresh('saleDetails')->saleDetails
            ->map(function (SaleDetails $detail) use ($overrides) {
                $o = $overrides[$detail->id] ?? [];

                return $this->cartRow(
                    $detail->id,
                    $o['product_id'] ?? $detail->product_id,
                    $o['qty'] ?? (float) $detail->quantity,
                    $o['unit_price'] ?? (float) $detail->unit_price,
                    $o['sub_total'] ?? (float) $detail->sub_total,
                );
            })->values();
    }

    private function cartRow(int $detailId, int $productId, float $qty, float $unitPrice, float $subTotal): object
    {
        return (object) [
            'id' => $detailId,
            'name' => 'Row ' . $detailId,
            'qty' => $qty,
            'price' => $unitPrice,
            'options' => collect([
                'product_id' => $productId,
                'unit_price' => $unitPrice,
                'sub_total' => $subTotal,
                'sub_total_before_tax' => $subTotal,
                'product_tax_amount' => 0.0,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'code' => 'CODE',
            ]),
        ];
    }

    private function assertRejected(Sale $sale, $cartItems, string $expectedFragment): void
    {
        try {
            app(SaleMonetaryEditService::class)->apply($sale, $cartItems, ['is_pkp' => false]);

            $this->fail('Expected the monetary edit to be rejected.');
        } catch (MonetaryEditException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }
}
