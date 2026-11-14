<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosReturnLineComponentIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_distinguishes_parent_sibling_carrier_and_parent_carrier_component_lines(): void
    {
        // Parent line: bundle_parent_sale_detail_id === sale_detail_id, same product as carrier, no component_quantity_per_bundle
        $carrierDetail = new SaleDetails([
            'product_id' => 4433,
        ]);
        $carrierDetail->id = 432029;

        $parentLine = new PosReturnLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            'product_id' => 4433,
            'component_quantity_per_bundle' => null,
        ]);
        $this->assertFalse(PosReturnLine::isComponentLine($parentLine, $carrierDetail));

        // Standalone non-bundle line
        $standaloneLine = new PosReturnLine([
            'sale_detail_id' => 500001,
            'bundle_parent_sale_detail_id' => null,
            'product_id' => 100,
            'component_quantity_per_bundle' => null,
        ]);
        $this->assertFalse(PosReturnLine::isComponentLine($standaloneLine));

        // Sibling-carrier / split-owner component line (different sale_detail_id even if component_quantity_per_bundle is omitted)
        $siblingComponentLine = new PosReturnLine([
            'sale_detail_id' => 432030,
            'bundle_parent_sale_detail_id' => 432029,
            'product_id' => 192,
            'component_quantity_per_bundle' => null,
        ]);
        $this->assertTrue(PosReturnLine::isComponentLine($siblingComponentLine));

        // Parent-carrier component line with component_quantity_per_bundle set
        $parentCarrierWithQty = new PosReturnLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            'product_id' => 192,
            'component_quantity_per_bundle' => 1,
        ]);
        $this->assertTrue(PosReturnLine::isComponentLine($parentCarrierWithQty, $carrierDetail));

        // Parent-carrier component line without component_quantity_per_bundle (detected via product_id !== carrier.product_id)
        $parentCarrierWithoutQty = new PosReturnLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            'product_id' => 192,
            'component_quantity_per_bundle' => null,
        ]);
        $this->assertTrue(PosReturnLine::isComponentLine($parentCarrierWithoutQty, $carrierDetail));

        // Draft array payloads
        $this->assertTrue(PosReturnLine::isComponentLine(['__synthesized_component' => true]));
        $this->assertTrue(PosReturnLine::isComponentLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            '__component_product_id' => 318,
            '__component_quantity_per_bundle' => 1,
        ]));
        $this->assertTrue(PosReturnLine::isComponentLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            '__component_product_id' => 318,
        ], $carrierDetail));
        $this->assertFalse(PosReturnLine::isComponentLine([
            'sale_detail_id' => 432029,
            'bundle_parent_sale_detail_id' => 432029,
            'product_id' => 4433,
        ], $carrierDetail));
    }

    /** @test */
    public function it_resolves_carrier_product_from_database_when_relation_not_loaded(): void
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Identity Test',
            'company_email' => 'id@example.com',
            'company_phone' => '0800',
            'company_address' => 'Addr',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'n@example.com',
            'footer_text' => 'F',
            'document_prefix' => 'D',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
        ]);

        $user = User::factory()->create(['is_active' => true]);

        $category = Category::create([
            'category_code' => 'ID-CAT',
            'category_name' => 'ID Cat',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);

        $unit = Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $parentProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Laptop Parent',
            'product_code' => 'LAP-4433',
            'barcode' => 'LAP-4433-BC',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 7600000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => true,
        ]);

        $compProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Mouse Comp',
            'product_code' => 'MOU-192',
            'barcode' => 'MOU-192-BC',
            'product_quantity' => 10,
            'product_cost' => 10000,
            'product_price' => 20000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Walk-in',
            'total_amount' => 7600000,
            'paid_amount' => 7600000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-ID-TEST',
        ]);

        $carrier = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => 'Laptop Parent',
            'product_code' => 'LAP-4433',
            'quantity' => 1,
            'price' => 7450000,
            'unit_price' => 7450000,
            'sub_total' => 7600000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $componentWithoutQtyPerBundle = new PosReturnLine([
            'sale_detail_id' => $carrier->id,
            'bundle_parent_sale_detail_id' => $carrier->id,
            'product_id' => $compProduct->id,
            'component_quantity_per_bundle' => null,
        ]);

        $parentWithSameProduct = new PosReturnLine([
            'sale_detail_id' => $carrier->id,
            'bundle_parent_sale_detail_id' => $carrier->id,
            'product_id' => $parentProduct->id,
            'component_quantity_per_bundle' => null,
        ]);

        $this->assertTrue(PosReturnLine::isComponentLine($componentWithoutQtyPerBundle));
        $this->assertFalse(PosReturnLine::isComponentLine($parentWithSameProduct));
    }
}
