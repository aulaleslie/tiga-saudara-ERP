<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseRowTotalRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'PT Test Purchase',
            'company_email' => 'test@purchase.com',
            'company_phone' => '081234567890',
            'notification_email' => 'notify@purchase.com',
            'is_pkp' => true,
            'row_total_rounding_increment' => 100.00,
        ]);

        $this->user = User::factory()->create();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.create', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.update', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('documents.business.override', 'web');
        $this->user->givePermissionTo(['purchases.create', 'purchases.update', 'documents.business.override']);
        $this->actingAs($this->user);

        session([
            'setting_id' => $this->setting->id,
            'user_settings' => [
                'setting_id' => $this->setting->id,
                'business_name' => $this->setting->company_name,
            ],
        ]);

        $unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Barang B',
            'product_code' => 'BRG-B',
            'product_quantity' => 50,
            'product_cost' => 50000,
            'product_price' => 70000,
            'product_unit' => $unit->id,
        ]);

        \Modules\Product\Entities\ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'last_purchase_price' => 71171.135,
        ]);

        Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.00,
            'is_default' => true,
        ]);
    }

    public function test_automatic_purchase_row_total_is_exact_and_not_rounded_to_an_increment(): void
    {
        Cart::instance('purchase')->destroy();

        Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 50,
                'product_unit' => 'Pcs',
                'price' => 71171.135,
                'unit_price' => 71171.135,
            ]);

        // The cart rounds DPP to the cent (71,171.14) and taxes that, giving
        // 78,999.97. The configured increment of 100 would previously have pushed
        // this to 79,000.00; the Purchase total is now kept exactly as computed.
        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(78999.97, round($cartItem->options->sub_total, 2));
        $this->assertEquals(71171.14, round($cartItem->options->sub_total_before_tax, 2));
        $this->assertEquals(7828.83, round($cartItem->options->product_tax_amount, 2));
        $this->assertEqualsWithDelta(
            $cartItem->options->sub_total,
            $cartItem->options->sub_total_before_tax + $cartItem->options->product_tax_amount,
            0.01
        );
    }

    /**
     * Neither an automatic nor a manually priced row applies increment rounding:
     * the increment previously lifted the automatic row to 79,000 while the manual
     * row bypassed it, a gap of ~Rp40. Both are now kept exact, and the only
     * remaining difference is the one-cent DPP tax-order artifact.
     */
    public function test_neither_automatic_nor_manual_row_applies_increment_rounding(): void
    {
        Cart::instance('purchase')->destroy();

        $comp = Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 50,
                'product_unit' => 'Pcs',
                'price' => 71171.135,
                'unit_price' => 71171.135,
            ]);

        $automaticTotal = round(Cart::instance('purchase')->content()->first()->options->sub_total, 2);

        $cartItem = Cart::instance('purchase')->content()->first();
        $comp->set('unit_price.' . $cartItem->rowId, 71171.135)
            ->call('updatePrice', $cartItem->rowId, $this->product->id);

        $manualTotal = round(Cart::instance('purchase')->content()->first()->options->sub_total, 2);

        // The automatic row taxes a cent-rounded DPP (71,171.14 -> 78,999.97) while
        // the manual row prices the submitted 71,171.135 directly (78,999.96). Both
        // are exact -- neither is lifted to 79,000 by an increment -- and they differ
        // only by that pre-existing one-cent DPP rounding order.
        $this->assertEquals(78999.97, $automaticTotal);
        $this->assertEquals(78999.96, $manualTotal);
        $this->assertEqualsWithDelta($automaticTotal, $manualTotal, 0.01);
    }

    /**
     * A Purchase orders new inventory, so its quantity must not be capped by stock
     * on hand. The stock ceiling belongs to outbound carts (sale, purchase_return)
     * only; applying it here would make a zero-stock product impossible to buy.
     */
    public function test_purchase_quantity_may_exceed_current_stock(): void
    {
        Cart::instance('purchase')->destroy();

        $comp = Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                // Only 5 on hand, but 20 are being ordered.
                'product_quantity' => 5,
                'product_unit' => 'Pcs',
                'price' => 71171.135,
                'unit_price' => 71171.135,
            ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;
        $comp->call('updateQuantityDirect', $rowId, $this->product->id, 20);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(20.0, (float) $cartItem->qty, 'Purchase quantity must not be capped by stock on hand.');
    }

    /**
     * The stock ceiling must remain in force for outbound carts.
     */
    public function test_purchase_return_quantity_is_still_capped_by_stock(): void
    {
        Cart::instance('purchase_return')->destroy();

        $comp = Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_return'])
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 5,
                'product_unit' => 'Pcs',
                'price' => 71171.135,
                'unit_price' => 71171.135,
            ]);

        $rowId = Cart::instance('purchase_return')->content()->first()->rowId;
        $comp->call('updateQuantityDirect', $rowId, $this->product->id, 20);

        $cartItem = Cart::instance('purchase_return')->content()->first();
        $this->assertEquals(5.0, (float) $cartItem->qty, 'Outbound carts must stay capped at available stock.');

        Cart::instance('purchase_return')->destroy();
    }

    public function test_manual_unit_price_in_purchase_keeps_its_exact_total(): void
    {
        Cart::instance('purchase')->destroy();

        $comp = Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 50,
                'product_unit' => 'Pcs',
                'price' => 71171.135,
                'unit_price' => 71171.135,
            ]);

        $cartItem = Cart::instance('purchase')->content()->first();

        $comp->set('unit_price.' . $cartItem->rowId, 71171.135)
            ->call('updatePrice', $cartItem->rowId, $this->product->id);

        $cartItemUpdated = Cart::instance('purchase')->content()->first();
        $this->assertEquals('manual_unit_price', $cartItemUpdated->options->pricing_source);
        // 71,171.135 * 1.11 = 78,999.96, kept exactly rather than lifted to 79,000.
        $this->assertEquals(78999.96, round($cartItemUpdated->options->sub_total, 2));
    }

    public function test_purchase_edit_business_change_reprices_the_row_exactly(): void
    {
        $tax = Tax::first();
        $targetSetting = Setting::factory()->create([
            'company_name' => 'PT Target Business',
            'company_email' => 'target@purchase.com',
            'company_phone' => '081234567891',
            'notification_email' => 'target@purchase.com',
            'is_pkp' => true,
            'row_total_rounding_increment' => 50.00, // target uses 50 increment
        ]);

        // Attach user to target setting
        $this->user->settings()->attach($targetSetting->id, ['role_id' => 1]);

        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'sup@a.com',
            'supplier_phone' => '0812345',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-0001',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id, // Source setting has increment 100
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 79000,
            'paid_amount' => 0,
            'due_amount' => 79000,
            'created_by' => $this->user->id,
        ]);

        \Modules\Product\Entities\ProductPrice::where('product_id', $this->product->id)->update([
            'last_purchase_price' => 71126.13,
        ]);

        // Unit price ex-tax 71126.13 * 1.11 = 78,949.9943 raw subtotal. The row was
        // stored as 78,900.00 under the old increment behavior; moving business
        // reprices it, and Purchase now keeps the exact cent value rather than
        // applying either business's configured increment.
        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 71126.13,
            'unit_price' => 71126.13,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 78900,
            'product_tax_amount' => 7823.87,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'automatic',
        ]);

        Category::create([
            'setting_id' => $targetSetting->id,
            'category_code' => 'CAT-2',
            'category_name' => 'Category 2',
            'created_by' => $this->user->id,
        ]);
        session([
            'setting_id' => $targetSetting->id,
            'user_settings' => [
                'setting_id' => $targetSetting->id,
                'business_name' => $targetSetting->company_name,
            ],
        ]);

        // The stored unit price is ex-tax, so the document is tax-exclusive.
        $purchase->update(['is_tax_included' => false]);

        $component = Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id])
            ->set('selectedSettingId', $targetSetting->id)
            ->dispatch('business-selector-changed', settingId: $targetSetting->id)
            ->set('is_tax_included', false)
            ->set('supplier_id', $supplier->id);

        // A row interaction under the new business: this is the eligible pricing
        // event, and it must use the TARGET business increment of 50.
        $cartItem = Cart::instance('purchase')->content()->first();
        Livewire::test(\App\Livewire\Purchase\ProductCart::class, [
            'cartInstance' => 'purchase',
            'selectedSettingId' => $targetSetting->id,
        ])
            ->set('is_tax_included', false)
            ->call('updateQuantity', $cartItem->rowId, $this->product->id);

        $component->call('submit');

        $updatedPurchase = $purchase->fresh();
        $this->assertEquals($targetSetting->id, $updatedPurchase->setting_id);

        $detail = $updatedPurchase->purchaseDetails->first();
        // Target business (increment 50) rounds 78949.9943 to 78950.00 (whereas source increment 100 would round it to 78900.00)
        $this->assertEquals(78950.00, $detail->sub_total);
    }

    public function test_moving_business_without_a_row_interaction_preserves_the_stored_total(): void
    {
        // Loading and saving is not a pricing event. A draft row committed under
        // one increment must survive a business move untouched; only an actual
        // row interaction may reprice it.
        $tax = Tax::first();
        $targetSetting = Setting::factory()->create([
            'company_name' => 'PT Target Stable',
            'company_email' => 'stable@purchase.com',
            'company_phone' => '081234567892',
            'notification_email' => 'stable@purchase.com',
            'is_pkp' => true,
            'row_total_rounding_increment' => 50.00,
        ]);
        $this->user->settings()->attach($targetSetting->id, ['role_id' => 1]);

        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier D',
            'supplier_email' => 'sup@d.com',
            'supplier_phone' => '0812348',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-0004',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 78900,
            'paid_amount' => 0,
            'due_amount' => 78900,
            'created_by' => $this->user->id,
        ]);

        \Modules\Product\Entities\ProductPrice::where('product_id', $this->product->id)->update([
            'last_purchase_price' => 71126.13,
        ]);

        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 71126.13,
            'unit_price' => 71126.13,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 78900,
            'product_tax_amount' => 7823.87,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'automatic',
        ]);

        Category::create([
            'setting_id' => $targetSetting->id,
            'category_code' => 'CAT-3',
            'category_name' => 'Category 3',
            'created_by' => $this->user->id,
        ]);
        session([
            'setting_id' => $targetSetting->id,
            'user_settings' => [
                'setting_id' => $targetSetting->id,
                'business_name' => $targetSetting->company_name,
            ],
        ]);

        Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id])
            ->set('selectedSettingId', $targetSetting->id)
            ->dispatch('business-selector-changed', settingId: $targetSetting->id)
            ->set('is_tax_included', true)
            ->set('supplier_id', $supplier->id)
            ->call('submit');

        $updatedPurchase = $purchase->fresh();
        $this->assertEquals($targetSetting->id, $updatedPurchase->setting_id);
        // Stored under increment 100; must NOT be re-rounded to the target's 50.
        $this->assertEquals(78900.00, $updatedPurchase->purchaseDetails->first()->sub_total);
    }

    public function test_saving_an_untouched_draft_after_the_increment_setting_changes_is_stable(): void
    {
        $tax = Tax::first();
        $this->setting->update(['row_total_rounding_increment' => 50]);

        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier E',
            'supplier_email' => 'sup@e.com',
            'supplier_phone' => '0812349',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-0005',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 78950,
            'paid_amount' => 0,
            'due_amount' => 78950,
            'created_by' => $this->user->id,
        ]);

        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 78950,
            'unit_price' => 78950,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 78950,
            'product_tax_amount' => 7823.87,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'automatic',
        ]);

        // The business later switches to a 100 increment.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        Cart::instance('purchase')->destroy();

        // Editing only the note must not reprice the row to 79000.
        Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id])
            ->set('supplier_id', $supplier->id)
            ->set('note', 'catatan baru')
            ->call('submit');

        $this->assertEquals(78950.00, $purchase->fresh()->purchaseDetails->first()->sub_total);
    }

    public function test_hydration_sets_the_recalculation_flag_false(): void
    {
        $tax = Tax::first();
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier F',
            'supplier_email' => 'sup@f.com',
            'supplier_phone' => '0812350',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-FLAG-1',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 78950,
            'paid_amount' => 0,
            'due_amount' => 78950,
            'created_by' => $this->user->id,
        ]);

        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 78950,
            'unit_price' => 78950,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 78950,
            'product_tax_amount' => 7823.87,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'automatic',
        ]);

        Cart::instance('purchase')->destroy();
        Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id]);

        $options = Cart::instance('purchase')->content()->first()->options;

        $this->assertFalse((bool) $options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_adding_a_new_automatic_row_sets_the_recalculation_flag_true(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);

        $this->assertTrue((bool) $cartItem->options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_a_quantity_interaction_sets_the_recalculation_flag_true(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);

        // Force the flag down as a hydrated row would carry it, then interact.
        Cart::instance('purchase')->update($cartItem->rowId, [
            'options' => array_merge($cartItem->options->toArray(), [
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => false,
            ]),
        ]);

        Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->call('updateQuantity', $cartItem->rowId, $this->product->id);

        $updated = Cart::instance('purchase')->content()->first();

        $this->assertTrue((bool) $updated->options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_manual_line_total_clears_the_recalculation_flag(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);

        Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', false)
            ->set('line_total.' . $this->product->id, 78949.00)
            ->call('updateLineTotal', $cartItem->rowId, $this->product->id);

        $updated = Cart::instance('purchase')->content()->first();

        $this->assertSame('manual_line_total', $updated->options->pricing_source);
        $this->assertFalse((bool) $updated->options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_normalizer_preserves_stored_total_when_flag_is_false(): void
    {
        // Flag false + supplied total: the backend must NOT reconstruct, even
        // though the row is automatic and the increment would round elsewhere.
        $normalized = $this->normalizeSingleRow(78950.00, false);

        $this->assertEquals(78950.00, $normalized['sub_total']);
    }

    public function test_normalizer_reconstructs_exactly_when_flag_is_true(): void
    {
        // Same supplied total, flag true: the backend reprices from the unit
        // price and keeps the exact result rather than lifting it to 79,000.
        $normalized = $this->normalizeSingleRow(78950.00, true);

        $this->assertEquals(78999.96, round((float) $normalized['sub_total'], 2));
    }

    /**
     * Normalize one tax-inclusive automatic row carrying the given stored total
     * and recalculation flag, with a unit price of 78999.96.
     */
    private function normalizeSingleRow(float $storedTotal, bool $recalcRequired): array
    {
        $tax = Tax::first();

        Cart::instance('purchase')->destroy();
        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 78999.96,
            'weight' => 1,
            'options' => [
                'unit_price' => 78999.96,
                'sub_total' => $storedTotal,
                'sub_total_before_tax' => 71171.17,
                'product_tax_amount' => 7828.83,
                'product_tax' => $tax->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => $recalcRequired,
            ],
        ]);

        $normalized = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize(
            ['is_tax_included' => true],
            Cart::instance('purchase')->content(),
            true,
            $this->setting->id
        );

        return $normalized['details'][0];
    }

    /**
     * Submit the request-sourced (client-built) cart to purchases.store with a
     * forged row total, and return the persisted detail sub_total.
     */
    private function storeWithForgedCart(mixed $forgedFlag, string $reference): float
    {
        $tax = Tax::first();
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier ' . $reference,
            'supplier_email' => strtolower($reference) . '@f.com',
            'supplier_phone' => '0812' . random_int(100000, 999999),
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $term = \Modules\Purchase\Entities\PaymentTerm::create([
            'name' => 'COD ' . $reference,
            'days' => 0,
            'is_active' => true,
        ]);

        Cart::instance('purchase')->destroy();

        $row = [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 71171.135,
            'tax_id' => $tax->id,
            'discount' => 0,
            'discount_type' => 'fixed',
            // Forged derived money and pricing authority.
            'sub_total' => 1.00,
            'sub_total_before_tax' => 1.00,
            'product_tax_amount' => 0.00,
            'pricing_source' => 'manual_line_total',
        ];

        if ($forgedFlag !== null) {
            $row[\App\Support\RowTotalRoundingCalculator::RECALC_FLAG] = $forgedFlag;
        }

        $this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'reference' => $reference,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'payment_term' => $term->id,
            'tax_id' => null,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1.00,
            'is_tax_included' => false,
            'cart' => [$row],
        ]);

        // The reference is server-allocated, so locate the document by supplier.
        $purchase = \Modules\Purchase\Entities\Purchase::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();

        return (float) $purchase->purchaseDetails->first()->sub_total;
    }

    public function test_request_cart_ignores_a_forged_total_when_the_flag_is_absent(): void
    {
        // 71171.135 x 1.11 = 78999.96, persisted exactly; the forged total is discarded.
        $this->assertEquals(78999.96, round($this->storeWithForgedCart(null, 'PR-FORGE-A'), 2));
    }

    public function test_request_cart_ignores_a_forged_total_when_the_flag_is_false(): void
    {
        $this->assertEquals(78999.96, round($this->storeWithForgedCart(false, 'PR-FORGE-B'), 2));
    }

    public function test_request_cart_ignores_a_forged_total_when_the_flag_is_true(): void
    {
        $this->assertEquals(78999.96, round($this->storeWithForgedCart(true, 'PR-FORGE-C'), 2));
    }

    public function test_tax_included_automatic_row_is_not_taxed_twice_on_persistence(): void
    {
        // A tax-inclusive price already contains the tax. Persistence must not
        // gross it up again (78999.96 x 1.11 -> 87700).
        $tax = Tax::first();

        Cart::instance('purchase')->destroy();
        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 78999.96,
            'weight' => 1,
            'options' => [
                'unit_price' => 78999.96,
                'sub_total_before_tax' => 71171.17,
                'product_tax_amount' => 7828.83,
                'product_tax' => $tax->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'pricing_source' => 'automatic',
            ],
        ]);

        $normalized = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize(
            ['is_tax_included' => true],
            Cart::instance('purchase')->content(),
            true,
            $this->setting->id
        );

        $detail = $normalized['details'][0];

        $this->assertEquals(78999.96, round((float) $detail['sub_total'], 2));
        $this->assertEqualsWithDelta($detail['sub_total'], $detail['sub_total_before_tax'] + $detail['product_tax_amount'], 0.01);
    }

    /**
     * Adds the product to the purchase cart at the given ex-tax unit price.
     */
    private function addAutomaticRow(float $unitPrice, bool $taxIncluded = false): \Gloudemans\Shoppingcart\CartItem
    {
        \Modules\Product\Entities\ProductPrice::where('product_id', $this->product->id)
            ->update(['last_purchase_price' => $unitPrice]);

        Cart::instance('purchase')->destroy();

        Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('is_tax_included', $taxIncluded)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 50,
                'product_unit' => 'Pcs',
                'price' => $unitPrice,
                'unit_price' => $unitPrice,
            ]);

        return Cart::instance('purchase')->content()->first();
    }

    /**
     * These two amounts previously straddled the increment midpoint and were
     * pulled to 79,000.00 and 78,900.00. Purchase now keeps them exactly, and the
     * configured increment is irrelevant regardless of its value.
     *
     * @dataProvider formerMidpointAmountProvider
     */
    public function test_purchase_row_keeps_exact_total_across_former_midpoints(float $amount): void
    {
        // Tax-included input: raw row total is the unit price itself (qty 1).
        $this->assertEquals($amount, round((float) $this->addAutomaticRow($amount, true)->options->sub_total, 2));
    }

    public static function formerMidpointAmountProvider(): array
    {
        return [
            'at midpoint' => [78950.00],
            'just below midpoint' => [78949.99],
        ];
    }

    /**
     * The increment setting still exists for Sales and POS. Purchase must ignore
     * it whatever it is set to, so the total is identical across values.
     */
    public function test_purchase_row_total_is_independent_of_the_configured_increment(): void
    {
        foreach ([0, 50, 100, 1000] as $increment) {
            $this->setting->update(['row_total_rounding_increment' => $increment]);
            Cart::instance('purchase')->destroy();

            $cartItem = $this->addAutomaticRow(78950.00, true);

            $this->assertEquals(
                78950.00,
                round((float) $cartItem->options->sub_total, 2),
                "Purchase total must ignore increment {$increment}."
            );
        }
    }

    public function test_exact_purchase_row_reconciles_dpp_plus_tax_tax_excluded(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135, false);

        $total = (float) $cartItem->options->sub_total;
        $dpp = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;

        // Kept exactly as computed rather than lifted to 79,000 by the increment.
        $this->assertEquals(78999.97, round($total, 2));
        $this->assertEqualsWithDelta($total, $dpp + $tax, 0.01);
    }

    public function test_exact_purchase_row_reconciles_dpp_plus_tax_tax_included(): void
    {
        $cartItem = $this->addAutomaticRow(78999.96, true);

        $total = (float) $cartItem->options->sub_total;
        $dpp = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;

        $this->assertEquals(78999.96, round($total, 2));
        $this->assertEqualsWithDelta($total, $dpp + $tax, 0.01);
    }

    public function test_pricing_source_persists_lowercase_and_survives_reload(): void
    {
        // BaseModel uppercases string attributes on write; pricing_source must
        // survive as the lowercase sentinel the rounding code compares against.
        $tax = Tax::first();
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier B',
            'supplier_email' => 'sup@b.com',
            'supplier_phone' => '0812346',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-0002',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 79000,
            'paid_amount' => 0,
            'due_amount' => 79000,
            'created_by' => $this->user->id,
        ]);

        $detail = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 71171.14,
            'unit_price' => 71171.14,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 79000,
            'product_tax_amount' => 7828.83,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'automatic',
        ]);

        $this->assertSame('automatic', $detail->fresh()->pricing_source);

        // A manual source round-trips as its lowercase sentinel too.
        $detail->update(['pricing_source' => 'manual_unit_price']);
        $this->assertSame('manual_unit_price', $detail->fresh()->pricing_source);
    }

    public function test_edit_reload_does_not_mutate_stored_row_totals(): void
    {
        $tax = Tax::first();
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier C',
            'supplier_email' => 'sup@c.com',
            'supplier_phone' => '0812347',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl Test',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'PR-0003',
            'supplier_id' => $supplier->id,
            'setting_id' => $this->setting->id,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_DRAFTED,
            'is_tax_included' => true,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 78949,
            'paid_amount' => 0,
            'due_amount' => 78949,
            'created_by' => $this->user->id,
        ]);

        // A historical row whose total is NOT a multiple of the increment.
        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 78949.00,
            'unit_price' => 78949.00,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 78949.00,
            'product_tax_amount' => 7823.06,
            'tax_id' => $tax->id,
            'product_tax' => $tax->id,
            'pricing_source' => 'manual_unit_price',
        ]);

        Cart::instance('purchase')->destroy();

        // Merely loading the edit form must not reprice or round the stored row.
        Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(78949.00, round((float) $cartItem->options->sub_total, 2));

        $this->assertEquals(78949.00, round((float) $purchase->fresh()->purchaseDetails->first()->sub_total, 2));
    }
}
