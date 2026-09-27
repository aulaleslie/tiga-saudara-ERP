<?php

namespace Tests\Feature;

use App\Livewire\Product\BundleTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductBundlePricingTest extends TestCase
{
    use RefreshDatabase;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'TestCo',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $this->setting->id);

        $this->parentProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PARENT_PRODUCT',
            'product_code' => 'P1',
            'product_unit' => 'pc',
            'product_cost' => 50000.00,
            'product_price' => 100000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $this->parentProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000.00,
        ]);

        $this->itemProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'ITEM_PRODUCT',
            'product_code' => 'I1',
            'product_unit' => 'pc',
            'product_cost' => 10000.00,
            'product_price' => 25000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 25000.00,
        ]);
    }

    public function test_bundle_create_persists_bundle_sale_price()
    {
        // Add feature test for bundle create defaulting and persisting Harga Jual Paket
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), [
            'name' => 'Test Bundle',
            'bundle_sale_price' => 95000.00,
            'price' => 5000.00, // Pass legacy explicitly to ensure it is NOT stored
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 1,
                    'informational_item_price' => 20000.00,
                    'price' => 1000.00, // Legacy
                ]
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.show', $this->parentProduct->id));

        $bundle = ProductBundle::withoutGlobalScopes()->where('name', 'TEST BUNDLE')->first();
        $this->assertNotNull($bundle);
        
        $this->assertEquals(95000.00, $bundle->bundle_sale_price);
        $this->assertNull($bundle->price); // Untouched legacy

        $item = $bundle->items()->first();
        $this->assertEquals(25000.00, $item->informational_item_price);
        $this->assertNull($item->price); // Untouched legacy
    }

    public function test_livewire_bundle_table_defaults_informational_price()
    {
        // Add feature or Livewire test for item selection defaulting and persisting Harga Informasi Item
        Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id])
            ->call('addItem')
            ->call('updateProductRow', [
                'index' => 'item_5',
                'product' => $this->itemProduct->toArray()
            ])
            // Just verifying it doesn't crash and we can test the state manually but rowKeys are dynamic.
            // A safer approach:
            ->set('rowKeys.0', 'item_5') // manually overwrite the first rowkey
            ->call('updateProductRow', [
                'index' => 'item_5',
                'product' => $this->itemProduct->toArray()
            ])
            ->assertSet('items.0.product_id', $this->itemProduct->id)
            ->assertSet('items.0.informational_item_price', 25000.00); // from setup sale_price
    }

    public function test_livewire_bundle_table_resolves_price_from_user_active_setting_when_session_missing(): void
    {
        $secondarySetting = Setting::create([
            'company_name' => 'Secondary Co',
            'company_email' => 'secondary@example.com',
            'company_phone' => '98765',
            'notification_email' => 'secondary-notify@example.com',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'left',
            'company_address' => 'Secondary Addr',
            'footer_text' => 'Secondary Footer',
            'is_pkp' => true,
        ]);

        $roleId = Role::query()->value('id') ?? Role::create(['name' => 'TEST ROLE'])->id;
        $this->user->settings()->attach($secondarySetting->id, ['role_id' => $roleId]);
        session()->forget('setting_id');

        ProductPrice::updateOrCreate(
            ['product_id' => $this->itemProduct->id, 'setting_id' => $this->setting->id],
            ['sale_price' => 0]
        );
        ProductPrice::updateOrCreate(
            ['product_id' => $this->itemProduct->id, 'setting_id' => $secondarySetting->id],
            ['sale_price' => 33333]
        );

        Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id])
            ->set('rowKeys.0', 'item_5')
            ->call('updateProductRow', [
                'index' => 'item_5',
                'product' => $this->itemProduct->toArray(),
            ])
            ->assertSet('items.0.informational_item_price', 33333.00);
    }

    public function test_surfaces_do_not_expose_legacy_harga_paket()
    {
        // create view
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.create', $this->parentProduct->id));
        $response->assertOk();
        $response->assertSee('bundle_sale_price');
        $response->assertDontSee('name="price"', false);

        // create a bundle for edit & product detail tests
        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Existing Bundle',
            'bundle_sale_price' => 95000.00,
            'price' => 3000.00 // Legacy value shouldn't be rendered
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->itemProduct->id,
            'quantity' => 2,
            'informational_item_price' => 25000.00,
            'price' => 1500.00 // Legacy shouldn't be rendered
        ]);

        // edit view
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.edit', [$this->parentProduct->id, $bundle->id]));
        $response->assertOk();
        $response->assertSee('bundle_sale_price');
        $response->assertDontSee('name="price"', false);

        // product detail surface
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.show', $this->parentProduct->id));
        $response->assertOk();
        // The display logic: text-muted for bracketed price
        // Legacy bundle price 3000.00 shouldn't exist, we expect 95000.00
        $response->assertSee('95.000,00');
        // Item informational price = 25000.00
        $response->assertSee('25.000,00');
        // It shouldn't display the legacy ones 3.000,00 and 1.500,00
        $response->assertDontSee('3.000,00');
        $response->assertDontSee('1.500,00');
    }
    public function test_livewire_bundle_table_removes_correct_row()
    {
        Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id])
            ->call('addItem') // index 1
            ->call('addItem') // index 2
            // Now we have 3 rows. Let's find the middle one's key.
            ->tap(function ($lw) {
                $rowKeys = $lw->get('rowKeys');
                $middleKey = $rowKeys[1];
                
                // Set some data for surviving rows
                $lw->set('items.0.product_id', 101);
                $lw->set('items.2.product_id', 103);
                
                $lw->call('removeItem', $middleKey);
                
                // surviving items should be 101 and 103 (at index 0 and 1 now due to array_values)
                $lw->assertCount('items', 2);
                $lw->assertSet('items.0.product_id', 101);
                $lw->assertSet('items.1.product_id', 103);
            });
    }

    public function test_livewire_bundle_table_edit_removes_persisted_integer_id_rows_via_string_key(): void
    {
        $initialItems = [
            [
                'id' => 27, // integer ID from database
                'product_id' => $this->itemProduct->id,
                'quantity' => 1,
                'informational_item_price' => 25000.00,
            ],
            [
                'id' => 28, // integer ID from database
                'product_id' => $this->parentProduct->id,
                'quantity' => 2,
                'informational_item_price' => 100000.00,
            ],
            [
                'id' => 29, // integer ID from database
                'product_id' => $this->itemProduct->id,
                'quantity' => 3,
                'informational_item_price' => 25000.00,
            ],
        ];

        // 1. Remove middle row ("28")
        Livewire::test(BundleTable::class, [
            'productId' => $this->parentProduct->id,
            'initialItems' => $initialItems,
            'bundleId' => 10,
        ])
            ->assertSet('rowKeys', ['27', '28', '29'])
            ->call('removeItem', '28') // String argument passed from Livewire / Blade
            ->assertCount('items', 2)
            ->assertSet('rowKeys', ['27', '29'])
            ->assertSet('items.0.product_id', $this->itemProduct->id)
            ->assertSet('items.0.quantity', 1)
            ->assertSet('items.1.product_id', $this->itemProduct->id)
            ->assertSet('items.1.quantity', 3);

        // 2. Remove first row ("27")
        Livewire::test(BundleTable::class, [
            'productId' => $this->parentProduct->id,
            'initialItems' => $initialItems,
            'bundleId' => 10,
        ])
            ->call('removeItem', '27')
            ->assertCount('items', 2)
            ->assertSet('rowKeys', ['28', '29'])
            ->assertSet('items.0.product_id', $this->parentProduct->id)
            ->assertSet('items.0.quantity', 2)
            ->assertSet('items.1.product_id', $this->itemProduct->id)
            ->assertSet('items.1.quantity', 3);

        // 3. Remove last row ("29")
        Livewire::test(BundleTable::class, [
            'productId' => $this->parentProduct->id,
            'initialItems' => $initialItems,
            'bundleId' => 10,
        ])
            ->call('removeItem', '29')
            ->assertCount('items', 2)
            ->assertSet('rowKeys', ['27', '28'])
            ->assertSet('items.0.product_id', $this->itemProduct->id)
            ->assertSet('items.1.product_id', $this->parentProduct->id);

        // 4. Add a new row to edit form then remove newly added row, or remove persisted row
        $component = Livewire::test(BundleTable::class, [
            'productId' => $this->parentProduct->id,
            'initialItems' => $initialItems,
            'bundleId' => 10,
        ]);
        $component->call('addItem');
        $component->assertCount('items', 4);
        $newKey = $component->get('rowKeys')[3];
        $this->assertStringStartsWith('item_', $newKey);

        // Remove the newly added row
        $component->call('removeItem', $newKey);
        $component->assertCount('items', 3);
        $component->assertSet('rowKeys', ['27', '28', '29']);

        // Now remove a persisted row after having added and removed
        $component->call('addItem');
        $component->call('removeItem', '27');
        $component->assertCount('items', 3);
        $component->assertSet('rowKeys.0', '28');
        $component->assertSet('rowKeys.1', '29');
    }

    public function test_livewire_bundle_table_clearing_product_selection(): void
    {
        $comp = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $comp->call('addItem'); // 2 rows now
        $rowKey0 = $comp->get('rowKeys')[0];
        $rowKey1 = $comp->get('rowKeys')[1];

        // Select product in row 0
        $comp->call('updateProductRow', [
            'index' => $rowKey0,
            'product' => $this->itemProduct->toArray(),
        ]);
        $comp->assertSet('items.0.product_id', $this->itemProduct->id);
        $comp->assertSet('items.0.informational_item_price', 25000.00);

        // Select product in row 1
        $comp->call('updateProductRow', [
            'index' => $rowKey1,
            'product' => $this->parentProduct->toArray(),
        ]);
        $comp->assertSet('items.1.product_id', $this->parentProduct->id);
        $comp->assertSet('items.1.informational_item_price', 100000.00);

        // Clear row 0 via null product (ProductSearchDropdown::clearSelection pattern)
        $comp->call('updateProductRow', $rowKey0, null);
        $comp->assertSet('items.0.product_id', null);
        $comp->assertSet('items.0.product_name', '');
        $comp->assertSet('items.0.informational_item_price', null);
        $comp->assertSet('items.0.quantity', 1);

        // Verify row 1 is unaffected
        $comp->assertSet('items.1.product_id', $this->parentProduct->id);
        $comp->assertSet('items.1.informational_item_price', 100000.00);

        // Unknown row key does not mutate state
        $comp->call('updateProductRow', 'non_existent_key', null);
        $comp->assertSet('items.1.product_id', $this->parentProduct->id);
    }

    public function test_livewire_bundle_table_last_row_cannot_be_removed(): void
    {
        $comp = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $comp->assertCount('items', 1);
        $key = $comp->get('rowKeys')[0];

        // Attempting to remove the last remaining row does nothing
        $comp->call('removeItem', $key);
        $comp->assertCount('items', 1);
        $comp->assertSet('rowKeys.0', $key);
    }

    public function test_livewire_bundle_table_hapus_button_transition_and_html_integrity(): void
    {
        // 1. Mount with 2 rows
        $comp = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $comp->call('addItem');
        $comp->assertCount('items', 2);
        $keys = $comp->get('rowKeys');

        // Render HTML with 2 rows: both Hapus buttons should be enabled and have title "Hapus item"
        $html2Rows = $comp->html();
        $this->assertStringContainsString('title="Hapus item"', $html2Rows);
        $this->assertStringNotContainsString('disabled', $html2Rows);

        // 2. Remove one row -> transitions to 1 row
        $comp->call('removeItem', $keys[1]);
        $comp->assertCount('items', 1);

        // 3. Render HTML after removal
        $html1Row = $comp->html();

        // 4. Verify no corrupted Livewire morph markers inside the button opening tag or HTML
        // Extract the <button ...> tag itself
        preg_match('/<button[^>]*class="btn btn-danger"[^>]*>.*?<\/button>/s', $html1Row, $matches);
        $this->assertNotEmpty($matches, 'Hapus button element must be found in rendered HTML');
        $buttonHtml = $matches[0];

        $this->assertStringNotContainsString('<!--', $buttonHtml);
        $this->assertStringNotContainsString('__BLOCK__', $buttonHtml);
        $this->assertStringNotContainsString('__ENDBLOCK__', $buttonHtml);
        $this->assertStringNotContainsString('<!--=""', $buttonHtml);
        $this->assertStringNotContainsString('&gt;', $buttonHtml);
        $this->assertStringContainsString('disabled', $buttonHtml);
        $this->assertStringContainsString('title="Paket harus memiliki minimal satu item"', $buttonHtml);

        // 5. Verify button element structure and attributes using DOMDocument
        $dom = new \DOMDocument();
        // Suppress HTML5 parsing warnings for Livewire custom attributes
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html1Row, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//button[contains(@class, "btn-danger")]');
        $this->assertEquals(1, $buttons->length, 'Exactly one Hapus button should exist');

        $btn = $buttons->item(0);
        $this->assertInstanceOf(\DOMElement::class, $btn);
        $this->assertEquals('button', $btn->getAttribute('type'));
        $this->assertTrue($btn->hasAttribute('disabled'), 'Hapus button should have disabled attribute');
        $this->assertEquals('Paket harus memiliki minimal satu item', $btn->getAttribute('title'));
        $this->assertEquals('Hapus', trim($btn->textContent));
    }

    public function test_livewire_bundle_table_preserves_values_across_row_operations(): void
    {
        $p3 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PRODUCT_3',
            'product_code' => 'P3',
            'product_unit' => 'pc',
            'product_cost' => 5000.00,
            'product_price' => 15000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $p3->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 15000.00,
        ]);

        $comp = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $comp->call('addItem');
        $comp->call('addItem');
        $keys = $comp->get('rowKeys');

        // Row A: itemProduct, qty 1, price 25000
        $comp->call('updateProductRow', $keys[0], $this->itemProduct->toArray());
        $comp->set('items.0.quantity', 1);

        // Row B: parentProduct, qty 2, price 100000
        $comp->call('updateProductRow', $keys[1], $this->parentProduct->toArray());
        $comp->set('items.1.quantity', 2);

        // Row C: p3, qty 3, price 15000
        $comp->call('updateProductRow', $keys[2], $p3->toArray());
        $comp->set('items.2.quantity', 3);

        // Delete Row B
        $comp->call('removeItem', $keys[1]);

        // Verify surviving Row 0 is Row A and Row 1 is Row C
        $comp->assertCount('items', 2);
        $comp->assertSet('items.0.product_id', $this->itemProduct->id);
        $comp->assertSet('items.0.quantity', 1);
        $comp->assertSet('items.0.informational_item_price', 25000.00);

        $comp->assertSet('items.1.product_id', $p3->id);
        $comp->assertSet('items.1.quantity', 3);
        $comp->assertSet('items.1.informational_item_price', 15000.00);

        // Changing quantity on row 0 does not change its informational price
        $comp->set('items.0.quantity', 10);
        $comp->assertSet('items.0.informational_item_price', 25000.00);

        // Changing product on row 1 refreshes only row 1's price and leaves row 0 intact
        $comp->call('updateProductRow', $keys[2], $this->itemProduct->toArray());
        $comp->assertSet('items.1.product_id', $this->itemProduct->id);
        $comp->assertSet('items.1.informational_item_price', 25000.00);
        $comp->assertSet('items.0.product_id', $this->itemProduct->id);
        $comp->assertSet('items.0.quantity', 10);
    }

    public function test_rendered_form_structure_and_persistence_without_submitted_informational_price(): void
    {
        // 1. Render create view and verify HTML structure
        $createView = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.create', $this->parentProduct->id));
        $createView->assertOk();
        // Visible quantity input exists with name="items[0][quantity]"
        $createView->assertSee('name="items[0][quantity]"', false);
        // Hidden product_id input exists
        $createView->assertSee('name="items[0][product_id]"', false);
        // Hidden quantity and hidden informational_item_price inputs are absent
        $createView->assertDontSee('type="hidden" name="items[0][informational_item_price]"', false);
        $createView->assertDontSee('type="hidden" name="items[0][quantity]"', false);

        // 2. Create bundle with only product_id and quantity submitted (no informational_item_price in payload)
        $storeResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), [
                'name' => 'Clean Payload Bundle',
                'bundle_sale_price' => 88000.00,
                'items' => [
                    [
                        'product_id' => $this->itemProduct->id,
                        'quantity' => 3,
                    ]
                ],
            ]);
        $storeResponse->assertSessionHasNoErrors();
        $bundle = ProductBundle::withoutGlobalScopes()->where('name', 'CLEAN PAYLOAD BUNDLE')->first();
        $this->assertNotNull($bundle);
        $this->assertCount(1, $bundle->items);
        $this->assertEquals(25000.00, (float) $bundle->items->first()->informational_item_price);
        $this->assertEquals(3, $bundle->items->first()->quantity);

        // 3. Edit bundle and submit changed quantity without informational_item_price
        $updateResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundle->id]), [
                'name' => 'Updated Clean Payload Bundle',
                'bundle_sale_price' => 88000.00,
                'items' => [
                    [
                        'product_id' => $this->itemProduct->id,
                        'quantity' => 5,
                    ]
                ],
            ]);
        $updateResponse->assertSessionHasNoErrors();
        $bundle->refresh();
        $this->assertEquals('UPDATED CLEAN PAYLOAD BUNDLE', $bundle->name);
        $this->assertEquals(5, $bundle->items->first()->quantity);
        $this->assertEquals(25000.00, (float) $bundle->items->first()->informational_item_price);
    }

    public function test_create_validation_redirect_restores_canonical_rows_and_ignores_forged_informational_price(): void
    {
        // 1. Submit invalid create request (active_to before active_from) with a forged informational price
        $invalidPayload = [
            'name' => 'Failing Create Bundle',
            'bundle_sale_price' => 80000.00,
            'active_from' => '2026-05-10',
            'active_to' => '2026-05-01', // Invalid: active_to before active_from
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 4,
                    'informational_item_price' => 999999.00, // Forged client price
                ]
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $invalidPayload);

        $response->assertSessionHasErrors('active_to');
        $response->assertRedirect();

        // 2. Follow redirect to create page with session old input
        $redirectResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.create', $this->parentProduct->id));

        $redirectResponse->assertOk();
        // Product name and quantity are restored
        $redirectResponse->assertSee('ITEM_PRODUCT');
        $redirectResponse->assertSee('value="4"', false);
        // Informational price displays the real active setting price (initialized via x-data as 25000), NOT the forged 999999
        $redirectResponse->assertSee('25000');
        $redirectResponse->assertDontSee('999999');

        // Verify Livewire component state directly with old input in session
        session()->put('_old_input', $invalidPayload);
        $component = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $component->assertCount('items', 1);
        $component->assertSet('items.0.product_id', $this->itemProduct->id);
        $component->assertSet('items.0.product_name', 'ITEM_PRODUCT');
        $component->assertSet('items.0.quantity', 4);
        $component->assertSet('items.0.informational_item_price', 25000.00);
        $component->assertSet('items.0.search', '');

        // 3. Correct the invalid field and resubmit
        $validPayload = [
            'name' => 'Corrected Create Bundle',
            'bundle_sale_price' => 80000.00,
            'active_from' => '2026-05-01',
            'active_to' => '2026-05-10',
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 4,
                ]
            ],
        ];

        $storeResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $validPayload);

        $storeResponse->assertSessionHasNoErrors();
        $storeResponse->assertRedirect(route('products.show', $this->parentProduct->id));

        $bundle = ProductBundle::withoutGlobalScopes()->where('name', 'CORRECTED CREATE BUNDLE')->first();
        $this->assertNotNull($bundle);
        $this->assertEquals(25000.00, (float) $bundle->items->first()->informational_item_price);
        $this->assertEquals(4, $bundle->items->first()->quantity);
    }

    public function test_edit_validation_redirect_restores_submitted_rows_and_re_resolves_prices(): void
    {
        $p3 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PRODUCT_3',
            'product_code' => 'P3',
            'product_unit' => 'pc',
            'product_cost' => 5000.00,
            'product_price' => 15000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $p3->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 15000.00,
        ]);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Original Edit Bundle',
            'bundle_sale_price' => 90000.00,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->itemProduct->id,
            'quantity' => 1,
            'informational_item_price' => 25000.00,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $p3->id,
            'quantity' => 2,
            'informational_item_price' => 15000.00,
        ]);

        // 1. Submit invalid update (e.g. duplicate component products) with changed quantities and forged price
        $invalidUpdatePayload = [
            'name' => 'Updated Bundle Name',
            'bundle_sale_price' => 95000.00,
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 6,
                    'informational_item_price' => 777777.00, // Forged client price
                ],
                [
                    'product_id' => $this->itemProduct->id, // Duplicate product triggers validation error
                    'quantity' => 7,
                ],
            ],
        ];

        $updateResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundle->id]), $invalidUpdatePayload);

        $updateResponse->assertSessionHasErrors('items.0.product_id');
        $updateResponse->assertRedirect();

        // 2. Original persisted bundle state is untouched
        $bundle->refresh();
        $this->assertEquals('ORIGINAL EDIT BUNDLE', $bundle->name);
        $this->assertCount(2, $bundle->items);

        // 3. Request redirected edit form with session old input
        $redirectResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.edit', [$this->parentProduct->id, $bundle->id]));

        $redirectResponse->assertOk();
        // Quantities 6 and 7 are rendered in visible inputs
        $redirectResponse->assertSee('value="6"', false);
        $redirectResponse->assertSee('value="7"', false);
        // Forged price is NOT displayed
        $redirectResponse->assertDontSee('777.777');

        // 4. Correct and resubmit valid update payload
        $validUpdatePayload = [
            'name' => 'Successfully Updated Bundle',
            'bundle_sale_price' => 95000.00,
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 6,
                ],
                [
                    'product_id' => $p3->id,
                    'quantity' => 8,
                ],
            ],
        ];

        $validResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundle->id]), $validUpdatePayload);

        $validResponse->assertSessionHasNoErrors();
        $validResponse->assertRedirect(route('products.show', $this->parentProduct->id));

        $bundle->refresh();
        $this->assertEquals('SUCCESSFULLY UPDATED BUNDLE', $bundle->name);
        $this->assertCount(2, $bundle->items);
        $this->assertEquals(6, $bundle->items->firstWhere('product_id', $this->itemProduct->id)->quantity);
        $this->assertEquals(25000.00, (float) $bundle->items->firstWhere('product_id', $this->itemProduct->id)->informational_item_price);
        $this->assertEquals(8, $bundle->items->firstWhere('product_id', $p3->id)->quantity);
        $this->assertEquals(15000.00, (float) $bundle->items->firstWhere('product_id', $p3->id)->informational_item_price);
    }

    public function test_old_input_with_malformed_and_non_existent_products_handled_defensively(): void
    {
        // Set malformed old input in session
        session()->put('_old_input', [
            'items' => [
                'not_an_array',
                [
                    'product_id' => 999999, // Non-existent product ID
                    'quantity' => -2, // Invalid quantity visible for validation message
                ],
                [
                    'product_id' => null,
                    'quantity' => 'abc',
                ],
            ],
        ]);

        $component = Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id]);
        $component->assertCount('items', 3);

        // Row 0: non-array fell back to blank row
        $component->assertSet('items.0.product_id', null);
        $component->assertSet('items.0.product_name', '');
        $component->assertSet('items.0.quantity', 1);

        // Row 1: non-existent product ID preserved without crashing, name/price empty, submitted qty preserved
        $component->assertSet('items.1.product_id', 999999);
        $component->assertSet('items.1.product_name', '');
        $component->assertSet('items.1.quantity', -2);
        $component->assertSet('items.1.informational_item_price', null);

        // Row 2: null product ID, string qty preserved
        $component->assertSet('items.2.product_id', null);
        $component->assertSet('items.2.quantity', 'abc');
        $component->assertSet('items.2.informational_item_price', null);
    }
}
