<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Purchase\Services\PurchaseImportService;
use Tests\TestCase;

class PurchaseImportProductUnitAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_import_creates_product_with_canonical_unit_fields()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);
        $service = app(PurchaseImportService::class);
        $settingId = 1; // dummy setting id

        $product = $service->findOrCreateProduct('Imported Product', 'PCS', $settingId);

        $this->assertEquals($unit->id, $product->base_unit_id);
        $this->assertEquals($unit->id, $product->unit_id);
        $this->assertEquals('PCS', $product->product_unit);
    }

    public function test_imported_purchase_detail_has_null_conversion_snapshot_and_behaves_as_factor_one(): void
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
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@example.com',
            'company_phone' => '000',
            'notification_email' => 'perdana@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => '',
            'company_address' => '',
        ]);

        Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $this->actingAs(User::factory()->create(['is_active' => 1]));

        $batch = PurchaseImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => 'legacy-import.csv',
            'file_sha256' => md5(uniqid()),
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        // A plain legacy-shaped import row: no conversion/unit context beyond
        // the product's own base unit -- imports never populate conversion
        // snapshot fields (see PurchaseImportService::processInvoiceGroup),
        // so the resulting line must resolve as a factor-one base-unit row.
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => PurchaseImportRow::STATUS_PENDING,
            'raw_json' => [
                'tanggal' => '01/01/2024',
                'no_faktur' => 'PO-LEGACY-IMPORT-001',
                'supplier' => 'Legacy Supplier',
                'produk' => 'Legacy Imported Product',
                'satuan' => 'PCS',
                'kuantitas' => '10',
                'harga_satuan' => '5000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'pajak' => '0',
                'sisa_tagihan' => '0',
                'biaya_pengiriman' => '0',
                'tag' => '',
            ],
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-LEGACY-IMPORT-001')->first();
        $this->assertNotNull($purchase);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['legacy imported product'])->first();
        $this->assertNotNull($product);

        $detail = PurchaseDetail::where('purchase_id', $purchase->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($detail);

        // No conversion identity was ever set by the importer.
        $this->assertNull($detail->purchase_unit_id);
        $this->assertNull($detail->product_unit_conversion_id);
        $this->assertNull($detail->entered_quantity);
        $this->assertNull($detail->entered_unit_price);
        $this->assertNull($detail->conversion_factor);
        $this->assertNull($detail->unit_name);
        $this->assertNull($detail->base_unit_name);

        // The effective_* accessors fall back to canonical base-unit values,
        // i.e. behave as an implicit factor-one row.
        $this->assertEquals(10, (float) $detail->quantity);
        $this->assertEquals((float) $detail->quantity, (float) $detail->effective_entered_quantity);
        $this->assertEquals((float) $detail->unit_price, (float) $detail->effective_entered_unit_price);
        $this->assertEquals((float) $detail->product_discount_amount, (float) $detail->effective_entered_product_discount_amount);
    }
}
