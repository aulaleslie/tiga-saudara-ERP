<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportShowRawPriceRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $this->actingAs($user);
    }

    public function test_sales_import_show_renders_invalid_row_with_blank_unit_price(): void
    {
        $batch = SalesImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => 'imports/sales/test.csv',
            'file_sha256' => hash('sha256', 'sales'),
            'status' => SalesImportBatch::STATUS_COMPLETED,
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_count' => 0,
            'error_count' => 1,
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => SalesImportRow::STATUS_INVALID,
            'error_message' => 'Tenant not found',
            'raw_json' => [
                'tanggal' => '01/01/2026',
                'customer' => 'Customer A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'harga_satuan' => '',
            ],
        ]);

        $response = $this->get(route('sales.imports.show', [
            'batch' => $batch,
            'status' => 'invalid',
            'search' => '',
        ]));

        $response->assertOk();
        $response->assertSee('Tenant not found');
    }

    public function test_purchase_import_show_renders_invalid_row_with_blank_unit_price(): void
    {
        $batch = PurchaseImportBatch::create([
            'user_id' => auth()->id(),
            'source_csv_path' => 'imports/purchases/test.csv',
            'file_sha256' => hash('sha256', 'purchases'),
            'status' => PurchaseImportBatch::STATUS_COMPLETED,
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_count' => 0,
            'error_count' => 1,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => PurchaseImportRow::STATUS_INVALID,
            'error_message' => 'Supplier not found',
            'raw_json' => [
                'tanggal' => '01/01/2026',
                'supplier' => 'Supplier A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'harga_satuan' => '',
            ],
        ]);

        $response = $this->get(route('purchases.imports.show', [
            'batch' => $batch,
            'status' => 'invalid',
            'search' => '',
        ]));

        $response->assertOk();
        $response->assertSee('Supplier not found');
    }
}
