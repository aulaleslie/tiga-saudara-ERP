<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Jobs\StagePurchaseImportRows;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportProductCodeMappingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => '',
        ]);

        Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);
    }

    /** @test */
    public function test_kode_produk_header_is_recognized_in_csv_mapping()
    {
        // Test that "Kode Produk" header is normalized correctly
        $controller = new \Modules\Purchase\Http\Controllers\PurchaseUploadController(
            app(PurchaseImportService::class)
        );

        $rawHeaders = [
            'Tanggal',
            'Supplier',
            'No Faktur',
            'Produk',
            'Kuantitas',
            'Satuan',
            'Harga Satuan',
            'Kode Produk',  // The field we're testing
        ];

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('normalizeHeaders');
        $method->setAccessible(true);

        $normalized = $method->invoke($controller, $rawHeaders);

        $this->assertArrayHasKey('kode_produk', $normalized);
        $this->assertEquals('Kode Produk', $normalized['kode_produk']);
    }

    /** @test */
    public function test_product_code_english_alias_is_recognized()
    {
        // Test that "Product Code" is also recognized
        $controller = new \Modules\Purchase\Http\Controllers\PurchaseUploadController(
            app(PurchaseImportService::class)
        );

        $rawHeaders = ['Tanggal', 'Product Code'];

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('normalizeHeaders');
        $method->setAccessible(true);

        $normalized = $method->invoke($controller, $rawHeaders);

        $this->assertArrayHasKey('kode_produk', $normalized);
    }

    /**
     * Drive the real upload header normalization plus the queued staging job over CSV content,
     * so the assertions cover the actual mapping path rather than a hand-built row payload.
     *
     * @return \Illuminate\Support\Collection<int, PurchaseImportRow>
     */
    protected function stageCsv(array $headers, array $dataRows)
    {
        Storage::fake('local');
        Queue::fake();

        $lines = [implode(',', $headers)];
        foreach ($dataRows as $dataRow) {
            $lines[] = implode(',', $dataRow);
        }

        $path = 'imports/purchases/product-code-test.csv';
        Storage::put($path, implode("\n", $lines));

        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => $path,
            'file_sha256' => 'dummy',
            'status' => 'queued',
        ]);

        // Normalize headers through the controller so the job receives the real header map.
        $controller = new \Modules\Purchase\Http\Controllers\PurchaseUploadController(
            app(PurchaseImportService::class)
        );
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('normalizeHeaders');
        $method->setAccessible(true);
        $normalizedHeaders = $method->invoke($controller, $headers);

        (new StagePurchaseImportRows($batch->id, $normalizedHeaders, $headers, ','))->handle();

        return PurchaseImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
    }

    /** @test */
    public function test_staging_job_carries_kode_produk_into_raw_json()
    {
        // Exercise the real StagePurchaseImportRows path from CSV headers through to raw_json.
        $rows = $this->stageCsv(
            ['Tanggal', 'Nama Panggilan', 'Nomor Transaksi', 'Nama Produk', 'Kode Produk', 'Kuantitas', 'Satuan', 'Harga per Unit'],
            [['01/07/2026', 'CV TEST', 'INV001', 'LAPTOP ABC', 'LAP-ABC-001', '10', 'UNIT', '1000000']]
        );

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('kode_produk', $rows[0]->raw_json);
        $this->assertEquals('LAP-ABC-001', $rows[0]->raw_json['kode_produk']);
        $this->assertEquals('LAPTOP ABC', $rows[0]->raw_json['produk']);
    }

    /** @test */
    public function test_staging_job_handles_absent_kode_produk_column()
    {
        // The product-code column is optional: staging must succeed and leave the value null.
        $rows = $this->stageCsv(
            ['Tanggal', 'Nama Panggilan', 'Nomor Transaksi', 'Nama Produk', 'Kuantitas', 'Satuan', 'Harga per Unit'],
            [['01/07/2026', 'CV TEST', 'INV001', 'LAPTOP XYZ', '5', 'UNIT', '2000000']]
        );

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('kode_produk', $rows[0]->raw_json);
        $this->assertNull($rows[0]->raw_json['kode_produk']);
    }

    /** @test */
    public function test_staging_job_keeps_blank_kode_produk_as_empty()
    {
        // A present-but-blank cell stages as an empty string; the service treats it as absent.
        $rows = $this->stageCsv(
            ['Tanggal', 'Nama Panggilan', 'Nomor Transaksi', 'Nama Produk', 'Kode Produk', 'Kuantitas', 'Satuan', 'Harga per Unit'],
            [['01/07/2026', 'CV TEST', 'INV001', 'MOUSE USB', '', '20', 'PCS', '50000']]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]->raw_json['kode_produk']);
    }
}
