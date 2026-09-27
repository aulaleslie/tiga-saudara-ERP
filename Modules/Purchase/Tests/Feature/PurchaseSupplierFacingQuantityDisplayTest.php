<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Show/print supplier-facing row rendering: entered snapshot (quantity, unit
 * price, and discount) preferred over the persisted canonical per-base-unit
 * values, with the canonical equivalent shown alongside where the row is
 * converted, the authoritative stored subtotal always displayed unchanged,
 * and legacy (pre-snapshot) rows falling back to canonical quantity/price
 * and the product's current base unit.
 */
class PurchaseSupplierFacingQuantityDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->user = User::factory()->create();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $perms = [
            'purchases.show', 'purchases.archive', 'purchases.reporting-date.override',
            'purchases.due-date.override', 'purchases.approval', 'purchases.create',
            'purchases.receive', 'purchases.receive.approval', 'purchases.update',
        ];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->user->givePermissionTo($perms);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Category::create([
            'id' => 1,
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        $this->pcsUnit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $this->boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'box', 'operator' => '*', 'operation_value' => 1]);

        Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create(['id' => 1, 'name' => 'Loc A1', 'setting_id' => $this->setting->id]);
    }

    private function makeProduct(): Product
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP-' . uniqid(),
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'stock_managed' => 1,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        return $product;
    }

    private function makePurchase(): Purchase
    {
        return Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-DISP-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => 'Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);
    }

    /**
     * 2 BOX @ Rp12,000/BOX = Rp24,000, factor 12 against a canonical price
     * of Rp1,000/PCS. Chosen so the canonical per-base-unit price (1,000)
     * and the entered per-unit price (12,000) can never be confused for
     * one another in a substring assertion.
     */
    private function makeConvertedDetail(Purchase $purchase, Product $product, ProductUnitConversion $conversion): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 120,
            'product_tax_amount' => 0,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 1440,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);
    }

    public function test_show_page_renders_entered_unit_row_not_canonical_per_base_unit_values(): void
    {
        $product = $this->makeProduct();
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12,
        ]);
        $purchase = $this->makePurchase();
        $this->makeConvertedDetail($purchase, $product, $conversion);

        $response = $this->get(route('purchases.show', $purchase->id));
        $response->assertStatus(200);

        $cells = $this->firstDetailRowCells($response->getContent());
        // Columns: Produk, Harga Satuan, Kuantitas, Diskon, Jumlah Total.
        [$productCell, $priceCell, $qtyCell, $discountCell, $subtotalCell] = $cells;

        // Quantity cell: entered snapshot (2 BOX), canonical equivalent (24 PCS) shown alongside.
        $this->assertStringContainsString('2 BOX', $qtyCell);
        $this->assertStringContainsString('24', $qtyCell);
        $this->assertStringContainsString('PCS', $qtyCell);

        // Price cell: entered unit price (12,000) leads the cell as the row's primary price. The
        // canonical per-PCS price may appear only as a parenthetical annotation, never as the price itself.
        $primaryPrice = trim(strtok($priceCell, "\n"));
        $this->assertStringContainsString('12,000.00', $primaryPrice);
        $this->assertStringNotContainsString('1,000.00', $primaryPrice);
        $this->assertStringContainsString('1,000.00', $priceCell, 'Canonical per-PCS price should still be shown as a conversion annotation.');

        // Discount cell: entered-unit discount (1,440) leads the cell; the canonical per-base-unit
        // discount (120) may appear only as a parenthetical annotation, never as the discount itself.
        $primaryDiscount = trim(strtok($discountCell, "\n"));
        $this->assertStringContainsString('1,440.00', $primaryDiscount);
        $this->assertStringNotContainsString('120.00', $primaryDiscount);

        // The authoritative stored subtotal remains untouched: 2 BOX x 12,000 = 24,000.
        $this->assertStringContainsString('24,000.00', $subtotalCell);
    }

    public function test_show_page_falls_back_to_canonical_price_and_quantity_for_legacy_rows(): void
    {
        $product = $this->makeProduct();
        $purchase = $this->makePurchase();

        // Legacy row: no entered/unit snapshot columns populated at all.
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 50,
            'product_tax_amount' => 0,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));
        $response->assertStatus(200);

        $cells = $this->firstDetailRowCells($response->getContent());
        [$productCell, $priceCell, $qtyCell, $discountCell, $subtotalCell] = $cells;

        $this->assertStringContainsString('1,000.00', $priceCell);
        $this->assertStringContainsString('10 PCS', $qtyCell);
        $this->assertStringContainsString('50.00', $discountCell);
    }

    public function test_print_view_renders_entered_unit_row_not_canonical_per_base_unit_values(): void
    {
        $product = $this->makeProduct();
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12,
        ]);
        $purchase = $this->makePurchase();
        $this->makeConvertedDetail($purchase, $product, $conversion);

        $html = $this->renderPrintView($purchase);
        $cells = $this->firstPrintRowCells($html);
        // Columns: Nama Barang, Qty, Satuan, Harga Satuan, Diskon%, Nilai Diskon, Jumlah.
        [$productCell, $qtyCell, $unitCell, $priceCell, $discountPctCell, $discountAmtCell, $subtotalCell] = $cells;

        $this->assertSame('2', $qtyCell);
        $this->assertSame('BOX', $unitCell);
        $this->assertSame('12.000', $priceCell);
        $this->assertSame('12.0%', $discountPctCell);
        $this->assertSame('1.440', $discountAmtCell);
        $this->assertSame('24.000', $subtotalCell);

        // The canonical per-PCS price/discount must never surface as the row's price/discount.
        $this->assertNotSame('1.000', $priceCell);
        $this->assertNotSame('120', $discountAmtCell);
    }

    public function test_print_view_falls_back_to_canonical_price_and_quantity_for_legacy_rows(): void
    {
        $product = $this->makeProduct();
        $purchase = $this->makePurchase();

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 50,
            'product_tax_amount' => 0,
        ]);

        $html = $this->renderPrintView($purchase);
        $cells = $this->firstPrintRowCells($html);
        [$productCell, $qtyCell, $unitCell, $priceCell, $discountPctCell, $discountAmtCell, $subtotalCell] = $cells;

        $this->assertSame('10', $qtyCell);
        $this->assertSame('PCS', $unitCell);
        $this->assertSame('1.000', $priceCell);
        $this->assertSame('50', $discountAmtCell);
    }

    public function test_pdf_route_renders_converted_row_without_error(): void
    {
        $product = $this->makeProduct();
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12,
        ]);
        $purchase = $this->makePurchase();
        $this->makeConvertedDetail($purchase, $product, $conversion);

        $response = $this->get(route('purchases.pdf', $purchase->id));

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    private function renderPrintView(Purchase $purchase): string
    {
        $purchase->load(['purchaseDetails.product.baseUnit']);

        return view('purchase::print', [
            'purchase' => $purchase,
            'supplier' => $purchase->supplier,
            'details' => $purchase->purchaseDetails,
        ])->render();
    }

    /**
     * Extracts the cell text of the first <tbody> row inside the first
     * <table> whose class list contains $tableClass. Avoids adding a DOM
     * parsing dependency: each <td>...</td> is captured and its inner tags
     * stripped, giving one plain-text string per column in row order.
     *
     * @return list<string>
     */
    private function firstRowCells(string $html, string $tableClass): array
    {
        if (!preg_match('/<table[^>]*class="[^"]*' . preg_quote($tableClass, '/') . '[^"]*"[^>]*>(.*?)<\/table>/s', $html, $tableMatch)) {
            $this->fail("Table with class {$tableClass} not found.");
        }

        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/s', $tableMatch[1], $bodyMatch);
        $tbody = $bodyMatch[1] ?? $tableMatch[1];

        preg_match('/<tr[^>]*>(.*?)<\/tr>/s', $tbody, $rowMatch);
        $this->assertNotEmpty($rowMatch, "No detail row found in table {$tableClass}.");

        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rowMatch[1], $cellMatches);

        return array_map(
            function (string $cell) {
                $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $cell);
                $withBreaks = preg_replace('/<div[^>]*>/i', "\n", $withBreaks);

                return trim(html_entity_decode(strip_tags($withBreaks)));
            },
            $cellMatches[1]
        );
    }

    private function firstDetailRowCells(string $html): array
    {
        return $this->firstRowCells($html, 'table-striped');
    }

    private function firstPrintRowCells(string $html): array
    {
        return $this->firstRowCells($html, 'main-table');
    }
}
