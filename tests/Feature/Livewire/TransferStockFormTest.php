<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Transfer\TransferStockForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Tests\TestCase;

class TransferStockFormTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $origin;
    protected $destination;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        
        $this->setting = Setting::create([
            'company_name'             => 'Test Company',
            'company_email'            => 'test@example.com',
            'company_phone'            => '1234567890',
            'default_currency_id'      => 1,
            'default_currency_position'=> 'prefix',
            'notification_email'       => 'test@example.com',
            'footer_text'              => 'Footer text',
            'company_address'          => '123 Street',
        ]);
        
        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Origin',
        ]);
        
        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Destination',
        ]);
        $category = \Modules\Product\Entities\Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);
        
        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_barcode_symbology' => 'CODE128',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'serial_number_required' => false
        ]);
        
        ProductStock::create([
            'location_id' => $this->origin->id,
            'product_id' => $this->product->id,
            'quantity' => 15,
            'broken_quantity' => 3,
            'quantity_tax' => 10,
            'quantity_non_tax' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 1,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    public function test_can_create_transfer_draft()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->set('rows', [
                [
                    'id' => $this->product->id,
                    'quantity_tax' => 2,
                    'quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'stock' => [
                        'quantity_tax' => 10,
                        'quantity_non_tax' => 5,
                        'broken_quantity_tax' => 2,
                        'broken_quantity_non_tax' => 1,
                    ]
                ]
            ])
            ->call('submit');
            
        $livewire->assertHasNoErrors();
        $livewire->assertRedirect(route('transfers.index'));
            
        $this->assertDatabaseHas('transfers', [
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status' => Transfer::STATUS_PENDING,
        ]);
    }

    public function test_can_load_existing_transfer_for_edit()
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->user->id,
        ]);
        
        \Modules\Adjustment\Entities\TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);
            
        $livewire->assertSet('originLocation', $this->origin->id)
            ->assertSet('destinationLocation', $this->destination->id);
            
        $rows = $livewire->get('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($this->product->id, $rows[0]['id']);
        $this->assertEquals(2, $rows[0]['quantity_tax']);
    }

    public function test_acknowledged_draft_saved_remains_draft()
    {
        // Create a transfer in DRAFT status (e.g., after rejection)
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'revision' => 1,
        ]);
        
        \Modules\Adjustment\Entities\TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        
        // User saves changes without explicit resubmit - should remain DRAFT
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->set('rows', [
                [
                    'id' => $this->product->id,
                    'quantity_tax' => 2,
                    'quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'stock' => [
                        'quantity_tax' => 10,
                        'quantity_non_tax' => 5,
                        'broken_quantity_tax' => 2,
                        'broken_quantity_non_tax' => 1,
                    ]
                ]
            ])
            ->call('submit');
            
        $livewire->assertHasNoErrors();
        
        // Verify transfer remains in DRAFT status after save
        $updatedTransfer = $transfer->fresh();
        $this->assertEquals(Transfer::STATUS_DRAFT, $updatedTransfer->status);
        $this->assertEquals(2, $updatedTransfer->revision);
    }

    public function test_http_create_with_insufficient_stock_rejected()
    {
        $this->user->givePermissionTo('stockTransfers.create');
        
        // Create a product with limited stock
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => \Modules\Product\Entities\Category::create([
                'setting_id' => $this->setting->id,
                'category_code' => 'CAT2',
                'category_name' => 'Category 2',
                'created_by' => $this->user->id,
            ])->id,
            'product_name' => 'Limited Stock Product',
            'product_code' => 'LIMITED-001',
            'product_barcode_symbology' => 'CODE128',
            'product_quantity' => 5,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);
        
        ProductStock::create([
            'location_id' => $this->origin->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'broken_quantity' => 0,
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        
        // Try to transfer more than available
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.store'), [
                'origin_location' => $this->origin->id,
                'destination_location' => $this->destination->id,
                'product_ids' => [$product->id],
                'quantities' => [10], // More than available stock
            ]);
        
        // Should fail with error message
        $response->assertRedirect();
        $this->assertCount(0, Transfer::where('status', Transfer::STATUS_PENDING)->where('origin_location_id', $this->origin->id)->get());
    }

    public function test_http_create_with_serialized_product_rejected()
    {
        $this->user->givePermissionTo('stockTransfers.create');
        
        // Create a serialized product
        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => \Modules\Product\Entities\Category::create([
                'setting_id' => $this->setting->id,
                'category_code' => 'CAT3',
                'category_name' => 'Category 3',
                'created_by' => $this->user->id,
            ])->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SERIAL-001',
            'product_barcode_symbology' => 'CODE128',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);
        
        // Try to create transfer with serialized product via HTTP (without serials)
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.store'), [
                'origin_location' => $this->origin->id,
                'destination_location' => $this->destination->id,
                'product_ids' => [$serialProduct->id],
                'quantities' => [2],
            ]);
        
        // Should fail with clear message about requiring web interface
        $response->assertRedirect();
        $this->assertCount(0, Transfer::where('status', Transfer::STATUS_PENDING)->where('origin_location_id', $this->origin->id)->get());
    }
}

