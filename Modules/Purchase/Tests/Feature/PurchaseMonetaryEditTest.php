<?php

namespace Modules\Purchase\Tests\Feature;

use App\Livewire\Purchase\EditForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseMonetaryEditService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Post-receipt monetary editing of a Purchase.
 *
 * Every case runs against a document with real received_note_details attached,
 * so a regression back to the delete-and-recreate path shows up as vanished
 * links rather than passing silently.
 */
class PurchaseMonetaryEditTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Supplier $supplier;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        foreach (['purchases.update', 'purchases.approved.edit', 'purchases.received.monetary.edit'] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['purchases.update', 'purchases.received.monetary.edit']);
        // Spatie caches the permission map process-wide; a stale map leaks in
        // from earlier suites during a full run and denies the route gate.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
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

        Cart::instance('purchase')->destroy();
    }

    private function makeProduct(int $categoryId, string $code): Product
    {
        return Product::create([
            'product_name' => 'Product ' . $code,
            'product_code' => $code,
            'product_quantity' => 50,
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
     * A received Purchase whose detail rows are already referenced by a
     * received note — the shape this workflow must never break.
     *
     * @param  array<int, array{product: Product, quantity: int, price: float}>  $lines
     */
    private function makeReceivedPurchase(array $lines, string $status = Purchase::STATUS_RECEIVED): Purchase
    {
        $total = array_sum(array_map(fn ($l) => $l['quantity'] * $l['price'], $lines));

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . fake()->unique()->numerify('####'),
            'status' => $status,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
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

        $receivedNoteId = DB::table('received_notes')->insertGetId([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DN-' . fake()->unique()->numerify('####'),
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $line) {
            $detail = $purchase->purchaseDetails()->create([
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
            ]);

            DB::table('received_note_details')->insert([
                'received_note_id' => $receivedNoteId,
                'po_detail_id' => $detail->id,
                'quantity_received' => $line['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $purchase->fresh('purchaseDetails');
    }

    public function test_authorized_user_changes_line_price_and_header_discount_in_place(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        $detailId = $purchase->purchaseDetails->first()->id;
        $linkIds = DB::table('received_note_details')->pluck('id')->all();

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->set('global_discount', 500)
            ->set('global_discount_type', 'fixed')
            ->set('shipping', 250)
            ->call('submit');

        $purchase->refresh();

        $this->assertSame(500.0, (float) $purchase->discount_amount);
        $this->assertSame(250.0, (float) $purchase->shipping_amount);
        $this->assertSame(9750.0, (float) $purchase->total_amount);
        $this->assertSame(9750.0, (float) $purchase->due_amount);

        // Row identity and its receipt link survive.
        $this->assertSame([$detailId], $purchase->purchaseDetails->pluck('id')->all());
        $this->assertSame($linkIds, DB::table('received_note_details')->pluck('id')->all());
        $this->assertSame(
            [$detailId],
            DB::table('received_note_details')->pluck('po_detail_id')->all()
        );
    }

    public function test_repeated_product_lines_map_to_their_own_detail_rows(): void
    {
        // Two lines for the same product: the case product-ID keying gets wrong.
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 4, 'price' => 1000.0],
            ['product' => $this->productA, 'quantity' => 6, 'price' => 2000.0],
        ]);

        [$firstId, $secondId] = $purchase->purchaseDetails->pluck('id')->all();

        $service = app(PurchaseMonetaryEditService::class);
        $cartItems = $this->cartFrom($purchase, [
            $firstId => ['unit_price' => 1500.0, 'sub_total' => 6000.0],
            $secondId => ['unit_price' => 2000.0, 'sub_total' => 12000.0],
        ]);

        $service->apply($purchase, $cartItems, [
            'global_discount' => 0,
            'global_discount_type' => 'fixed',
            'shipping' => 0,
            'is_pkp' => false,
        ]);

        $first = PurchaseDetail::find($firstId);
        $second = PurchaseDetail::find($secondId);

        // Each line took its own new price; quantities untouched.
        $this->assertSame(1500.0, (float) $first->unit_price);
        $this->assertSame(6000.0, (float) $first->sub_total);
        $this->assertSame(4, (int) $first->quantity);

        $this->assertSame(2000.0, (float) $second->unit_price);
        $this->assertSame(12000.0, (float) $second->sub_total);
        $this->assertSame(6, (int) $second->quantity);
    }

    public function test_quantity_change_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $purchase->purchaseDetails->first()->id;

        $cartItems = $this->cartFrom($purchase, [$detailId => ['qty' => 99]]);

        $this->assertRejected($purchase, $cartItems, 'Kuantitas');
        $this->assertSame(10, (int) $purchase->fresh()->purchaseDetails->first()->quantity);
    }

    public function test_product_change_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $purchase->purchaseDetails->first()->id;

        $cartItems = $this->cartFrom($purchase, [
            $detailId => ['product_id' => $this->productB->id],
        ]);

        $this->assertRejected($purchase, $cartItems, 'Produk');
        $this->assertSame(
            $this->productA->id,
            (int) $purchase->fresh()->purchaseDetails->first()->product_id
        );
    }

    public function test_added_line_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $purchase->purchaseDetails->first()->id;

        $cartItems = $this->cartFrom($purchase, [])
            ->push($this->cartRow(999, $this->productB->id, 1, 100.0, 100.0));

        $this->assertRejected($purchase, $cartItems, 'Jumlah baris');
        $this->assertCount(1, $purchase->fresh()->purchaseDetails);
        $this->assertSame($detailId, $purchase->fresh()->purchaseDetails->first()->id);
    }

    public function test_removed_line_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 4, 'price' => 1000.0],
            ['product' => $this->productB, 'quantity' => 6, 'price' => 2000.0],
        ]);

        $cartItems = $this->cartFrom($purchase, [])->take(1)->values();

        $this->assertRejected($purchase, $cartItems, 'Jumlah baris');
        $this->assertCount(2, $purchase->fresh()->purchaseDetails);
    }

    public function test_foreign_detail_id_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $other = $this->makeReceivedPurchase([
            ['product' => $this->productB, 'quantity' => 3, 'price' => 500.0],
        ]);

        $cartItems = $this->cartFrom($purchase, [])->map(function ($row) use ($other) {
            $row->options->put(
                PurchaseMonetaryEditService::DETAIL_ID_OPTION,
                $other->purchaseDetails->first()->id
            );

            return $row;
        });

        $this->assertRejected($purchase, $cartItems, 'tidak dikenali');
    }

    public function test_duplicate_detail_ids_are_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 4, 'price' => 1000.0],
            ['product' => $this->productA, 'quantity' => 6, 'price' => 2000.0],
        ]);

        $firstId = $purchase->purchaseDetails->first()->id;

        $cartItems = $this->cartFrom($purchase, [])->map(function ($row) use ($firstId) {
            $row->options->put(PurchaseMonetaryEditService::DETAIL_ID_OPTION, $firstId);

            return $row;
        });

        $this->assertRejected($purchase, $cartItems, 'duplikat');
    }

    public function test_document_outside_active_setting_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $cartItems = $this->cartFrom($purchase, []);

        session(['setting_id' => Setting::factory()->create()->id]);

        $this->assertRejected($purchase, $cartItems, 'bisnis yang sedang aktif');
    }

    public function test_total_below_recorded_payments_is_rejected(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        DB::table('purchase_payments')->insert([
            'purchase_id' => $purchase->id,
            'date' => now()->toDateString(),
            'reference' => 'PP-1',
            'amount' => 8000,
            'payment_method' => 'Cash',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentRowCount = DB::table('purchase_payments')->count();

        // Discount the document down to 2,000 — below the 8,000 already paid.
        $cartItems = $this->cartFrom($purchase, []);

        $this->assertRejected($purchase, $cartItems, 'pembayaran yang sudah tercatat', [
            'global_discount' => 8000,
            'global_discount_type' => 'fixed',
        ]);

        // Payment rows are untouched, and the header keeps its original total.
        $this->assertSame($paymentRowCount, DB::table('purchase_payments')->count());
        $this->assertSame(8000.0, (float) DB::table('purchase_payments')->value('amount'));
        $this->assertSame(10000.0, (float) $purchase->fresh()->total_amount);
    }

    public function test_monetary_edit_leaves_stock_and_product_prices_untouched(): void
    {
        $purchase = $this->makeReceivedPurchase([
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

        $detailId = $purchase->purchaseDetails->first()->id;
        $cartItems = $this->cartFrom($purchase, [
            $detailId => ['unit_price' => 1400.0, 'sub_total' => 14000.0],
        ]);

        app(PurchaseMonetaryEditService::class)->apply($purchase, $cartItems, [
            'global_discount' => 0,
            'global_discount_type' => 'fixed',
            'shipping' => 0,
            'is_pkp' => false,
        ]);

        $this->assertSame(14000.0, (float) $purchase->fresh()->total_amount);

        $price = ProductPrice::where('product_id', $this->productA->id)->first();
        $this->assertSame(800.0, (float) $price->last_purchase_price);
        $this->assertSame(750.0, (float) $price->average_purchase_price);
        $this->assertSame($stockBefore, (float) $this->productA->fresh()->product_quantity);
    }

    public function test_partially_received_purchase_is_also_editable(): void
    {
        $purchase = $this->makeReceivedPurchase(
            [['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0]],
            Purchase::STATUS_RECEIVED_PARTIALLY
        );

        $this->assertSame(Purchase::EDIT_MODE_MONETARY_ONLY, $purchase->resolveEditMode());

        $cartItems = $this->cartFrom($purchase, []);
        app(PurchaseMonetaryEditService::class)->apply($purchase, $cartItems, [
            'global_discount' => 10,
            'global_discount_type' => 'percentage',
            'shipping' => 0,
            'is_pkp' => false,
        ]);

        $this->assertSame(9000.0, (float) $purchase->fresh()->total_amount);
    }

    public function test_forged_pkp_context_cannot_alter_tax_treatment(): void
    {
        // The owning business is non-PKP, so tax must be stripped regardless of
        // what the request claims. PKP status is a property of the document's
        // business, not a submitted value.
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $purchase->forceFill(['is_tax_included' => true])->save();

        $detailId = $purchase->purchaseDetails->first()->id;

        $cartItems = $this->cartFrom($purchase, [])->map(function ($row) {
            // Forge a tax-bearing row alongside a forged PKP context.
            $row->options->put('product_tax', 1);
            $row->options->put('product_tax_amount', 1100.0);
            $row->options->put('sub_total', 11000.0);
            $row->options->put('sub_total_before_tax', 10000.0);

            return $row;
        });

        app(PurchaseMonetaryEditService::class)->apply($purchase, $cartItems, [
            'global_discount' => 0,
            'global_discount_type' => 'fixed',
            'shipping' => 0,
            // Forged: ignored in favour of the locked document's business.
            'is_pkp' => true,
            'is_tax_included' => true,
        ]);

        $purchase->refresh();
        $detail = PurchaseDetail::find($detailId);

        $this->assertSame(0.0, (float) $purchase->tax_amount);
        $this->assertFalse((bool) $purchase->is_tax_included);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertNull($detail->tax_id);
        // Non-PKP keeps the DPP as the authoritative line total.
        $this->assertSame(10000.0, (float) $detail->sub_total);
        $this->assertSame(10000.0, (float) $purchase->total_amount);
    }

    public function test_partial_payment_reconciles_summary_without_touching_payment_rows(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $this->addActivePayment($purchase, 4000);

        $detailId = $purchase->purchaseDetails->first()->id;

        // Corrected total 8,000 is above the 4,000 paid → PARTIAL.
        app(PurchaseMonetaryEditService::class)->apply(
            $purchase,
            $this->cartFrom($purchase, [$detailId => ['unit_price' => 800.0, 'sub_total' => 8000.0]]),
            ['global_discount' => 0, 'global_discount_type' => 'fixed', 'shipping' => 0]
        );

        $purchase->refresh();
        $this->assertSame(8000.0, (float) $purchase->total_amount);
        $this->assertSame(4000.0, (float) $purchase->paid_amount);
        $this->assertSame(4000.0, (float) $purchase->due_amount);
        $this->assertTrue(\App\Constants\PaymentStatus::matches(\App\Constants\PaymentStatus::PARTIAL, $purchase->payment_status), 'Expected PARTIAL, got ' . $purchase->payment_status);

        $this->assertPaymentRowsUnchanged([4000.0]);
    }

    public function test_fully_covering_payment_reconciles_to_paid(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $this->addActivePayment($purchase, 6000);

        $detailId = $purchase->purchaseDetails->first()->id;

        // Corrected total 6,000 exactly equals what has been paid → PAID.
        app(PurchaseMonetaryEditService::class)->apply(
            $purchase,
            $this->cartFrom($purchase, [$detailId => ['unit_price' => 600.0, 'sub_total' => 6000.0]]),
            ['global_discount' => 0, 'global_discount_type' => 'fixed', 'shipping' => 0]
        );

        $purchase->refresh();
        $this->assertSame(6000.0, (float) $purchase->total_amount);
        $this->assertSame(6000.0, (float) $purchase->paid_amount);
        $this->assertSame(0.0, (float) $purchase->due_amount);
        $this->assertTrue(\App\Constants\PaymentStatus::matches(\App\Constants\PaymentStatus::PAID, $purchase->payment_status), 'Expected PAID, got ' . $purchase->payment_status);

        $this->assertPaymentRowsUnchanged([6000.0]);
    }

    private function addActivePayment(Purchase $purchase, float $amount): void
    {
        DB::table('purchase_payments')->insert([
            'purchase_id' => $purchase->id,
            'date' => now()->toDateString(),
            'reference' => 'PP-' . fake()->unique()->numerify('####'),
            'amount' => $amount,
            'payment_method' => 'Cash',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<int, float>  $expectedAmounts */
    private function assertPaymentRowsUnchanged(array $expectedAmounts): void
    {
        $amounts = DB::table('purchase_payments')->orderBy('id')->pluck('amount')
            ->map(fn ($a) => (float) $a)->all();

        $this->assertSame($expectedAmounts, $amounts);
        $this->assertSame(
            count($expectedAmounts),
            DB::table('purchase_payments')->where('status', 'ACTIVE')->count()
        );
    }

    public function test_fractional_quantity_survives_a_monetary_edit(): void
    {
        // Weight-based units store quantity as decimal(15,3); the normalizer
        // casts quantity to int, so the edit must never write it back.
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        $detail = $purchase->purchaseDetails->first();
        $detail->forceFill(['quantity' => 23.7])->save();
        $purchase->refresh();

        app(PurchaseMonetaryEditService::class)->apply(
            $purchase,
            $this->cartFrom($purchase, [$detail->id => ['unit_price' => 1100.0, 'sub_total' => 26070.0]]),
            [
                'global_discount' => 0,
                'global_discount_type' => 'fixed',
                'shipping' => 0,
                'is_pkp' => false,
            ]
        );

        $after = PurchaseDetail::find($detail->id);
        $this->assertSame(23.7, (float) $after->quantity);
        $this->assertSame(1100.0, (float) $after->unit_price);
    }

    public function test_edit_screen_renders_in_monetary_only_mode(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);

        // The full page renders (exercising the locked controls) and labels the mode.
        $this->get(route('purchases.edit', $purchase->id))
            ->assertOk()
            ->assertSee('Mode Edit Moneter');

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertSet('editMode', Purchase::EDIT_MODE_MONETARY_ONLY);
    }

    public function test_legacy_http_update_cannot_reach_destructive_path(): void
    {
        $purchase = $this->makeReceivedPurchase([
            ['product' => $this->productA, 'quantity' => 10, 'price' => 1000.0],
        ]);
        $detailId = $purchase->purchaseDetails->first()->id;

        $response = $this->put(route('purchases.update', $purchase->id), [
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_amount' => 500,
        ]);

        $response->assertStatus(422);

        // Details and their receipt links are intact.
        $this->assertSame([$detailId], $purchase->fresh()->purchaseDetails->pluck('id')->all());
        $this->assertSame(1, DB::table('received_note_details')->where('po_detail_id', $detailId)->count());
        $this->assertSame(10000.0, (float) $purchase->fresh()->total_amount);
    }

    /**
     * Build cart rows mirroring the persisted details, applying per-detail overrides.
     *
     * @param  array<int, array<string, mixed>>  $overrides  keyed by detail ID
     */
    private function cartFrom(Purchase $purchase, array $overrides): \Illuminate\Support\Collection
    {
        return $purchase->fresh('purchaseDetails')->purchaseDetails
            ->map(function (PurchaseDetail $detail) use ($overrides) {
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
            'id' => $productId,
            'name' => 'Row ' . $detailId,
            'qty' => $qty,
            'price' => $unitPrice,
            'options' => collect([
                PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detailId,
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

    private function assertRejected(Purchase $purchase, $cartItems, string $expectedFragment, array $input = []): void
    {
        try {
            app(PurchaseMonetaryEditService::class)->apply($purchase, $cartItems, array_merge([
                'global_discount' => 0,
                'global_discount_type' => 'fixed',
                'shipping' => 0,
                'is_pkp' => false,
            ], $input));

            $this->fail('Expected the monetary edit to be rejected.');
        } catch (\App\Services\MonetaryEdit\MonetaryEditException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }
}
