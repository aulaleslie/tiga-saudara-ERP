<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\DTOs\SerialClassification;
use Modules\Adjustment\Services\StockOpnameSerialClassifier;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class MultiLocationSerialClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $pkpSetting;
    protected Setting $nonPkpSetting;
    protected Location $pkpLocation1;
    protected Location $pkpLocation2;
    protected Location $nonPkpLocation1;
    protected Location $nonPkpLocation2;
    protected Location $outsideLocation;
    protected Location $consignmentLocation;
    protected Tax $tax;
    protected Unit $baseUnit;
    protected StockOpnameSerialClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Co',
            'company_email' => 'pkp@co.com',
            'company_phone' => '111',
            'notification_email' => 'n1@co.com',
            'footer_text' => 'F',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->nonPkpSetting = Setting::create([
            'company_name' => 'Non-PKP Co',
            'company_email' => 'nonpkp@co.com',
            'company_phone' => '222',
            'notification_email' => 'n2@co.com',
            'footer_text' => 'F',
            'company_address' => 'Bandung',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->pkpLocation1 = Location::create([
            'name' => 'Gudang PKP 1',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->pkpLocation2 = Location::create([
            'name' => 'Gudang PKP 2',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->nonPkpLocation1 = Location::create([
            'name' => 'Gudang Non-PKP 1',
            'setting_id' => $this->nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->nonPkpLocation2 = Location::create([
            'name' => 'Gudang Non-PKP 2',
            'setting_id' => $this->nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->outsideLocation = Location::create([
            'name' => 'Gudang Luar',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->consignmentLocation = Location::create([
            'name' => 'Gudang Konsinyasi',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => true,
        ]);

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $this->baseUnit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        $this->classifier = app(StockOpnameSerialClassifier::class);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Laptop Gamer',
            'product_code' => 'LPT-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'setting_id' => $this->pkpSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => true,
        ], $overrides));
    }

    private function makeSerial(Product $product, Location $location, string $sn, bool $isBroken = false, ?int $taxId = null): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $sn,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => $isBroken,
            'tax_id' => $taxId,
        ]);
    }

    private function makeStock(Product $product, Location $location, int $goodTax = 0, int $goodNonTax = 0, int $badTax = 0, int $badNonTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $goodTax + $goodNonTax + $badTax + $badNonTax,
            'quantity_tax' => $goodTax,
            'quantity_non_tax' => $goodNonTax,
            'broken_quantity' => $badTax + $badNonTax,
            'broken_quantity_tax' => $badTax,
            'broken_quantity_non_tax' => $badNonTax,
        ]);
    }

    public function test_serials_in_selected_pool_are_retained_at_their_respective_locations()
    {
        $product = $this->makeProduct();

        // SN1 is at Non-PKP 1, SN2 is at PKP 1.
        $sn1 = $this->makeSerial($product, $this->nonPkpLocation1, 'SN-POOL-1', isBroken: false);
        $sn2 = $this->makeSerial($product, $this->pkpLocation1, 'SN-POOL-2', isBroken: false, taxId: $this->tax->id);

        $sn1->load(['location', 'tax', 'product']);
        $sn2->load(['location', 'tax', 'product']);

        $selectedLocations = collect([$this->nonPkpLocation1, $this->pkpLocation1]);
        $matchingSerialsByText = collect([$sn1, $sn2])->groupBy('serial_number');
        $destinationSerialsByProduct = collect([$product->id => collect([$sn1, $sn2])]);
        $movementEligibleLocationIds = Location::standard()->pluck('id');

        $row = [
            'product_id' => $product->id,
            'serials' => [
                ['serial_number' => 'SN-POOL-1', 'condition' => 'good'],
                ['serial_number' => 'SN-POOL-2', 'condition' => 'bad'], // condition changed to bad
            ],
        ];

        $result = $this->classifier->classify(
            product: $product,
            row: $row,
            destinationLocation: $selectedLocations,
            isPkp: true,
            movementEligibleLocationIds: $movementEligibleLocationIds,
            matchingSerialsByText: $matchingSerialsByText,
            destinationSerialsByProduct: $destinationSerialsByProduct,
            activeClaimSerialIds: collect(),
            allocatedSerialIds: collect(),
        );

        $this->assertCount(2, $result['entered']);
        $this->assertCount(0, $result['omitted']);

        $c1 = collect($result['entered'])->firstWhere('serialNumber', 'SN-POOL-1');
        $this->assertSame(SerialClassification::STATUS_RETAINED, $c1->status);
        $this->assertSame($this->nonPkpLocation1->id, $c1->sourceLocationId);
        $this->assertFalse($c1->crossSetting);

        $c2 = collect($result['entered'])->firstWhere('serialNumber', 'SN-POOL-2');
        $this->assertSame(SerialClassification::STATUS_CONDITION_CHANGED, $c2->status);
        $this->assertSame($this->pkpLocation1->id, $c2->sourceLocationId);
        $this->assertSame('bad', $c2->enteredCondition);
    }

    public function test_outside_and_new_serials_target_deterministic_non_pkp_lowest_stock_destination()
    {
        $product = $this->makeProduct();

        // Non-PKP 1 has 10 stock, Non-PKP 2 has 2 stock, PKP 1 has 1 stock.
        $stockMap = collect([
            "{$this->nonPkpLocation1->id}_{$product->id}" => (object) ['quantity_tax' => 0, 'quantity_non_tax' => 10, 'broken_quantity' => 0],
            "{$this->nonPkpLocation2->id}_{$product->id}" => (object) ['quantity_tax' => 0, 'quantity_non_tax' => 2, 'broken_quantity' => 0],
            "{$this->pkpLocation1->id}_{$product->id}" => (object) ['quantity_tax' => 1, 'quantity_non_tax' => 0, 'broken_quantity' => 0],
        ]);

        // SN-OUTSIDE is at outsideLocation (belongs to PKP setting, taxed).
        $snOutside = $this->makeSerial($product, $this->outsideLocation, 'SN-OUTSIDE', isBroken: false, taxId: $this->tax->id);
        $snOutside->load(['location', 'tax', 'product']);

        $selectedLocations = collect([$this->pkpLocation1, $this->nonPkpLocation1, $this->nonPkpLocation2]);
        $matchingSerialsByText = collect([$snOutside])->groupBy('serial_number');
        $destinationSerialsByProduct = collect([$product->id => collect()]);
        $movementEligibleLocationIds = Location::standard()->pluck('id');

        $row = [
            'product_id' => $product->id,
            'serials' => [
                ['serial_number' => 'SN-OUTSIDE', 'condition' => 'good'],
                ['serial_number' => 'SN-BRAND-NEW', 'condition' => 'good'],
            ],
        ];

        $result = $this->classifier->classify(
            product: $product,
            row: $row,
            destinationLocation: $selectedLocations,
            isPkp: true,
            movementEligibleLocationIds: $movementEligibleLocationIds,
            matchingSerialsByText: $matchingSerialsByText,
            destinationSerialsByProduct: $destinationSerialsByProduct,
            activeClaimSerialIds: collect(),
            allocatedSerialIds: collect(),
            locationStocks: $stockMap,
        );

        $this->assertCount(2, $result['entered']);

        // Surplus destination: Non-PKP with lowest stock -> Non-PKP Location 2
        $cOutside = collect($result['entered'])->firstWhere('serialNumber', 'SN-OUTSIDE');
        $this->assertSame(SerialClassification::STATUS_MOVED, $cOutside->status);
        $this->assertStringContainsStringIgnoringCase('Gudang Non-PKP 2', $cOutside->label);
        $this->assertTrue($cOutside->sourceIsTax);
        $this->assertFalse($cOutside->destinationIsTax); // Non-PKP 2 destination is non-tax
        $this->assertTrue($cOutside->crossSetting);

        $cNew = collect($result['entered'])->firstWhere('serialNumber', 'SN-BRAND-NEW');
        $this->assertSame(SerialClassification::STATUS_NEW, $cNew->status);
        $this->assertStringContainsStringIgnoringCase('Gudang Non-PKP 2', $cNew->label);
        $this->assertFalse($cNew->destinationIsTax);
    }

    public function test_omitted_serial_identifies_exact_source_location_within_pool()
    {
        $product = $this->makeProduct();

        $sn1 = $this->makeSerial($product, $this->nonPkpLocation1, 'SN-OMIT-1', isBroken: false);
        $sn2 = $this->makeSerial($product, $this->pkpLocation1, 'SN-OMIT-2', isBroken: true, taxId: $this->tax->id);

        $sn1->load(['location', 'tax', 'product']);
        $sn2->load(['location', 'tax', 'product']);

        $selectedLocations = collect([$this->nonPkpLocation1, $this->pkpLocation1]);
        $destinationSerialsByProduct = collect([$product->id => collect([$sn1, $sn2])]);
        $movementEligibleLocationIds = Location::standard()->pluck('id');

        // Empty serials entered -> both omitted
        $row = [
            'product_id' => $product->id,
            'serials' => [],
        ];

        $result = $this->classifier->classify(
            product: $product,
            row: $row,
            destinationLocation: $selectedLocations,
            isPkp: true,
            movementEligibleLocationIds: $movementEligibleLocationIds,
            matchingSerialsByText: collect(),
            destinationSerialsByProduct: $destinationSerialsByProduct,
            activeClaimSerialIds: collect(),
            allocatedSerialIds: collect(),
        );

        $this->assertCount(2, $result['omitted']);

        $o1 = collect($result['omitted'])->firstWhere('serialNumber', 'SN-OMIT-1');
        $this->assertSame($this->nonPkpLocation1->id, $o1->sourceLocationId);
        $this->assertStringContainsStringIgnoringCase('Gudang Non-PKP 1', $o1->label);

        $o2 = collect($result['omitted'])->firstWhere('serialNumber', 'SN-OMIT-2');
        $this->assertSame($this->pkpLocation1->id, $o2->sourceLocationId);
        $this->assertStringContainsStringIgnoringCase('Gudang PKP 1', $o2->label);
    }

    public function test_consignment_source_location_is_a_blocking_conflict()
    {
        $product = $this->makeProduct();

        // Serial is currently at consignment location
        $sn = $this->makeSerial($product, $this->consignmentLocation, 'SN-CONSIGN', isBroken: false);
        $sn->load(['location', 'tax', 'product']);

        $selectedLocations = collect([$this->pkpLocation1]);
        $matchingSerialsByText = collect([$sn])->groupBy('serial_number');
        $destinationSerialsByProduct = collect([$product->id => collect()]);
        $movementEligibleLocationIds = Location::standard()->pluck('id'); // does not include consignmentLocation

        $row = [
            'product_id' => $product->id,
            'serials' => [
                ['serial_number' => 'SN-CONSIGN', 'condition' => 'good'],
            ],
        ];

        $result = $this->classifier->classify(
            product: $product,
            row: $row,
            destinationLocation: $selectedLocations,
            isPkp: true,
            movementEligibleLocationIds: $movementEligibleLocationIds,
            matchingSerialsByText: $matchingSerialsByText,
            destinationSerialsByProduct: $destinationSerialsByProduct,
            activeClaimSerialIds: collect(),
            allocatedSerialIds: collect(),
        );

        $c = $result['entered'][0];
        $this->assertSame(SerialClassification::STATUS_CONFLICTING, $c->status);
        $this->assertStringContainsString('konsinyasi', $c->conflictReason);
        $this->assertNotEmpty($result['conflicts']);
    }
}
