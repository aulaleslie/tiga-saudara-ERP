<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ImportStockLocationTest extends TestCase
{
    use RefreshDatabase;

    protected $tigaNusa;
    protected $topIt;
    protected $otherTenant;
    protected $duniaComputer;
    
    protected $tigaNusaLoc;
    protected $topItLoc;
    protected $otherTenantLoc;
    protected $duniaComputerLoc;

    protected function setUp(): void
    {
        parent::setUp();

        // Create User
        \App\Models\User::factory()->create(['id' => 1, 'is_active' => 1]);

        // Create Unit
        Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        // Create Tenants
        $this->tigaNusa = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'tiganusa@example.com',
            'company_phone' => '123',
            'company_address' => 'Addr',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'tiganusa@example.com',
            'footer_text' => 'Footer',
        ]);
        $this->tigaNusaLoc = Location::create([
            'setting_id' => $this->tigaNusa->id,
            'name' => 'Gudang Tiga Nusa',
        ]);

        $this->topIt = Setting::create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'company_email' => 'topit@example.com',
            'company_phone' => '123',
            'company_address' => 'Addr',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'topit@example.com',
            'footer_text' => 'Footer',
        ]);
        $this->topItLoc = Location::create([
            'setting_id' => $this->topIt->id,
            'name' => 'Gudang Top IT',
        ]);

        $this->otherTenant = Setting::create([
            'company_name' => 'TIGA COMPUTER', // 'aries' maps to this
            'company_email' => 'tigacom@example.com',
            'company_phone' => '123',
            'company_address' => 'Addr',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'tigacom@example.com',
            'footer_text' => 'Footer',
        ]);
        $this->otherTenantLoc = Location::create([
            'setting_id' => $this->otherTenant->id,
            'name' => 'Gudang Tiga Com',
        ]);

        $this->duniaComputer = Setting::create([
            'company_name' => 'DUNIA COMPUTER', // 'agus' maps to this
            'company_email' => 'dunia@example.com',
            'company_phone' => '123',
            'company_address' => 'Addr',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'dunia@example.com',
            'footer_text' => 'Footer',
        ]);
        $this->duniaComputerLoc = Location::create([
            'setting_id' => $this->duniaComputer->id,
            'name' => 'Gudang Dunia',
        ]);
    }

    /** @test */
    public function it_resolves_stock_setting_correctly_based_on_markers()
    {
        $service = new PurchaseImportService();

        // Product with * marker (Should -> Tiga Nusa)
        $productName = '*Mouse Gaming';
        $tag = 'unmapped_tag'; // Unmapped tag to test marker fallback
        $sourceSetting = $this->otherTenant;

        $resolved = $service->resolveStockSetting($tag, $productName, $sourceSetting);
        $this->assertEquals($this->tigaNusa->id, $resolved->id, 'Asterisk marker should resolve to Tiga Nusa');

        // Product with TP marker (Should -> Top IT)
        $productName = 'Keyboard TP';
        $tag = 'unmapped_tag'; // Unmapped tag to test marker fallback
        $sourceSetting = $this->tigaNusa;

        $resolved = $service->resolveStockSetting($tag, $productName, $sourceSetting);
        $this->assertEquals($this->topIt->id, $resolved->id, 'TP marker should resolve to Top IT');
    }

    /** @test */
    public function it_resolves_stock_setting_based_on_history_when_no_marker()
    {
        $service = new PurchaseImportService();

        // Create a product
        $product = Product::create([
            'product_name' => 'Normal Item',
            'product_code' => 'ITM001',
            'unit_id' => 1,
            'setting_id' => $this->tigaNusa->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        // Scenario 1: No history, defaults to Source
        $tag = 'cv tiga nusa';
        $sourceSetting = $this->tigaNusa;
        $resolved = $service->resolveStockSetting($tag, $product->product_name, $sourceSetting, $product);
        $this->assertEquals($this->tigaNusa->id, $resolved->id, 'No history should default to source');

        // Scenario 2: History exists for OTHER tenant (Dunia Computer)
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->duniaComputer->id, // Dunia Computer bought it
            'quantity' => 10,
            'current_quantity' => 10,
            'location_id' => $this->duniaComputerLoc->id,
            'user_id' => 1,
            'type' => 'BUY',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'broken_quantity' => 0,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // New import from Tiga Nusa (Source)
        // History lookup is removed; it should default to Perdana
        $resolved = $service->resolveStockSetting('unmapped_tag', $product->product_name, $this->tigaNusa, $product);
        $this->assertNotEquals($this->duniaComputer->id, $resolved->id);

        // New import from Tiga Computer (Source)
        $resolved = $service->resolveStockSetting('unmapped_tag', $product->product_name, $this->otherTenant, $product);
        $this->assertNotEquals($this->duniaComputer->id, $resolved->id);
    }

    /** @test */
    public function it_ignores_tiga_nusa_in_history_lookup()
    {
        $service = new PurchaseImportService();

        // Create a product
        $product = Product::create([
            'product_name' => 'Normal Item 2',
            'product_code' => 'ITM002',
            'unit_id' => 1,
            'setting_id' => $this->tigaNusa->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        // History: Tiga Nusa bought it recently
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id, 
            'quantity' => 10,
            'current_quantity' => 10,
            'location_id' => $this->tigaNusaLoc->id,
            'user_id' => 1,
            'type' => 'BUY',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'broken_quantity' => 0,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // History: Dunia Computer bought it earlier (Older)
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->duniaComputer->id, 
            'quantity' => 10,
            'current_quantity' => 10,
            'location_id' => $this->duniaComputerLoc->id,
            'user_id' => 1,
            'type' => 'BUY',
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'broken_quantity' => 0,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Import
        // History lookup is removed; it should default to Perdana or Source
        $resolved = $service->resolveStockSetting('unmapped_tag', $product->product_name, $this->tigaNusa, $product);
        $this->assertNotEquals($this->duniaComputer->id, $resolved->id);
    }

    /** @test */
    public function sales_service_uses_same_logic()
    {
        // Just verify method exists and basic case, assuming shared logic is copy-pasted (or ideally trait, but for now duplicate is fine)
        $service = new SalesImportService();

        $productName = '*Mouse Gaming';
        $tag = 'unmapped_tag';
        
        // Mock finding setting through reflection or just reliance on DB
        // The service methods rely on Setting::model
        
        $resolved = $service->resolveStockSetting($tag, $productName, $this->otherTenant);
        $this->assertEquals($this->tigaNusa->id, $resolved->id);
    }
}
