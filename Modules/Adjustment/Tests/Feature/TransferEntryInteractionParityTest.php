<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferStockForm;
use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransferEntryInteractionParityTest extends TestCase
{
    use RefreshDatabase;

    private User $privilegedUser;
    private User $blindUser;
    private Setting $setting;
    private Location $originLocation;
    private Location $destinationLocation;
    private Location $otherLocation;
    private Product $nonSerialProduct;
    private Product $serialProduct;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.show',
            'stockTransfers.edit',
            'stockTransfers.delete',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
            \Modules\Adjustment\Services\TransferStockVisibility::PERMISSION,
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Tiga Saudara ERP',
            'company_email' => 'info@tigasaudara.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@tigasaudara.com',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $this->originLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse Origin',
        ]);

        $this->destinationLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse Destination',
        ]);

        $this->otherLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse Other',
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-TEST',
            'category_name' => 'General Category',
            'created_by' => 1,
        ]);

        $unit = Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $this->nonSerialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Non-Serial Widget',
            'product_code' => 'NSW-001',
            'barcode' => 'BAR-NSW-001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->nonSerialProduct->id,
            'location_id' => $this->originLocation->id,
            'quantity' => 100,
            'quantity_tax' => 40,
            'quantity_non_tax' => 60,
            'broken_quantity' => 20,
            'broken_quantity_tax' => 10,
            'broken_quantity_non_tax' => 10,
        ]);

        $this->serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Serialized Gadget',
            'product_code' => 'SRG-001',
            'barcode' => 'BAR-SRG-001',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->originLocation->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 3,
        ]);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            \Modules\Adjustment\Services\TransferStockVisibility::PERMISSION,
        ]);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function task_1_1_transfer_entry_mounting_row_hydration_and_parent_synchronization()
    {
        // Hydrate from existing draft
        $transfer = Transfer::create([
            'setting_id' => $this->setting->id,
            'origin_location_id' => $this->originLocation->id,
            'destination_location_id' => $this->destinationLocation->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->privilegedUser->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->nonSerialProduct->id,
            'quantity' => 15,
            'requested_quantity' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 15,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
        ]);

        $parent = Livewire::actingAs($this->privilegedUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        $parent->assertSet('originLocation', $this->originLocation->id)
            ->assertSet('destinationLocation', $this->destinationLocation->id)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD);

        $rows = $parent->get('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($this->nonSerialProduct->id, $rows[0]['id']);
        $this->assertEquals(15, $rows[0]['requested_quantity']);
    }

    /** @test */
    public function task_1_1_origin_change_clears_rows_while_destination_change_preserves_rows()
    {
        $parent = Livewire::actingAs($this->privilegedUser)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->originLocation->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destinationLocation->id])
            ->set('rows', [[
                'id' => $this->nonSerialProduct->id,
                'product_name' => $this->nonSerialProduct->product_name,
                'requested_quantity' => 10,
                'serial_numbers' => [],
            ]]);

        $this->assertCount(1, $parent->get('rows'));

        // Changing destination location only: rows MUST be preserved
        $parent->call('onDestinationLocationSelected', ['id' => $this->otherLocation->id]);
        $this->assertEquals($this->otherLocation->id, $parent->get('destinationLocation'));
        $this->assertCount(1, $parent->get('rows'), 'Destination change must preserve entered rows');

        // Clearing destination: rows MUST be preserved
        $parent->call('onDestinationLocationSelected', null);
        $this->assertNull($parent->get('destinationLocation'));
        $this->assertCount(1, $parent->get('rows'), 'Clearing destination must preserve entered rows');

        // Changing origin location: destination and rows MUST be cleared
        $parent->call('onOriginLocationSelected', ['id' => $this->otherLocation->id]);
        $this->assertEquals($this->otherLocation->id, $parent->get('originLocation'));
        $this->assertNull($parent->get('destinationLocation'));
        $this->assertCount(0, $parent->get('rows'), 'Origin change must clear entered rows');
    }

    /** @test */
    public function task_1_1_creation_condition_change_requires_confirmation_and_resets_rows()
    {
        $parent = Livewire::actingAs($this->privilegedUser)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->originLocation->id])
            ->set('rows', [[
                'id' => $this->nonSerialProduct->id,
                'product_name' => $this->nonSerialProduct->product_name,
                'requested_quantity' => 5,
                'serial_numbers' => [],
            ]]);

        $this->assertCount(1, $parent->get('rows'));
        $this->assertEquals(Transfer::CONDITION_GOOD, $parent->get('stockCondition'));

        // Request condition change to BREAKAGE
        $parent->call('selectStockCondition', Transfer::CONDITION_BREAKAGE);
        $parent->assertSet('showConditionConfirmModal', true)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD);
        $this->assertCount(1, $parent->get('rows'));

        // Cancel condition change: preserves rows
        $parent->call('cancelConditionChange')
            ->assertSet('showConditionConfirmModal', false)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD);
        $this->assertCount(1, $parent->get('rows'));

        // Confirm condition change: resets rows and updates condition
        $parent->call('selectStockCondition', Transfer::CONDITION_BREAKAGE)
            ->call('confirmConditionChange')
            ->assertSet('showConditionConfirmModal', false)
            ->assertSet('stockCondition', Transfer::CONDITION_BREAKAGE);
        $this->assertCount(0, $parent->get('rows'));
    }
}
