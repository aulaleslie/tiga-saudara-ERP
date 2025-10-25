<?php

namespace Tests\Feature\SalesReturn;

use App\Livewire\AutoComplete\SaleReferenceLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Fluent;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReferenceLoaderTest extends TestCase
{
    use RefreshDatabase;

    private function createSettingWithCurrency(): array
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
            'company_name' => 'CV Tiga Computer',
            'company_email' => 'info@example.com',
            'company_phone' => '080000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'before',
            'notification_email' => 'notif@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Jl. Test 123',
            'document_prefix' => 'TS',
            'sale_prefix_document' => 'SL',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Gudang Pusat',
            'is_pos' => false,
        ]);

        return compact('currency', 'setting', 'location');
    }

    private function createProduct(Setting $setting, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'product_name' => 'Produk Bundle',
            'product_code' => 'PRD-001',
            'product_quantity' => 50,
            'serial_number_required' => 1,
            'broken_quantity' => 0,
            'product_cost' => 50000,
            'product_price' => 125000,
            'product_stock_alert' => 5,
            'is_purchased' => 1,
            'is_sold' => 1,
            'sale_price' => 125000,
            'tier_1_price' => 125000,
            'tier_2_price' => 125000,
        ], $overrides));
    }

    private function createDispatchedSale(int $dispatchedQuantity = 5, array $saleOverrides = []): array
    {
        ['setting' => $setting, 'location' => $location] = $this->createSettingWithCurrency();

        session()->put('setting_id', $setting->id);

        $product = $this->createProduct($setting);

        $sale = Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'customer_name' => 'Acme Corp',
            'setting_id' => $setting->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $dispatchedQuantity * 125000,
            'paid_amount' => $dispatchedQuantity * 125000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => null,
        ], $saleOverrides));

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $dispatchedQuantity,
            'price' => 125000,
            'unit_price' => 125000,
            'sub_total' => $dispatchedQuantity * 125000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => $dispatchedQuantity,
            'tax_id' => null,
        ]);

        $serialIds = [];

        for ($i = 1; $i <= $dispatchedQuantity; $i++) {
            $serialIds[] = ProductSerialNumber::create([
                'product_id' => $product->id,
                'dispatch_detail_id' => $dispatchDetail->id,
                'location_id' => $location->id,
                'serial_number' => 'SN-' . $sale->id . '-' . $i,
                'tax_id' => null,
            ])->id;
        }

        return compact('sale', 'product', 'dispatchDetail', 'saleDetail', 'serialIds');
    }

    private function createSaleReturnForDispatch(Sale $sale, DispatchDetail $dispatchDetail, array $serialIds, int $quantity): void
    {
        $saleReturn = SaleReturn::create([
            'date' => now()->format('Y-m-d'),
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'customer_id' => null,
            'setting_id' => $sale->setting_id,
            'location_id' => $dispatchDetail->location_id,
            'customer_name' => $sale->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $quantity * 125000,
            'paid_amount' => 0,
            'due_amount' => $quantity * 125000,
            'approval_status' => 'pending',
            'return_type' => null,
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Pending',
            'note' => null,
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'sale_detail_id' => SaleDetails::where('sale_id', $sale->id)->value('id'),
            'dispatch_detail_id' => $dispatchDetail->id,
            'location_id' => $dispatchDetail->location_id,
            'tax_id' => null,
            'product_id' => $dispatchDetail->product_id,
            'product_name' => 'Produk Bundle',
            'product_code' => 'PRD-001',
            'quantity' => $quantity,
            'unit_price' => 125000,
            'price' => 125000,
            'sub_total' => $quantity * 125000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => array_slice($serialIds, 0, $quantity),
        ]);
    }

    private function normaliseResults($results): Collection
    {
        if ($results instanceof Collection) {
            return $results->map(function ($item) {
                return $item instanceof Fluent ? $item->toArray() : (array) $item;
            });
        }

        if (is_array($results)) {
            return collect($results)->map(function ($item) {
                return $item instanceof Fluent ? $item->toArray() : (array) $item;
            });
        }

        return collect();
    }

    public function test_it_returns_matching_sale_references_with_summary(): void
    {
        $data = $this->createDispatchedSale();
        $sale = $data['sale'];

        $queryFragment = Str::substr($sale->reference, 0, 6);

        Livewire::test(SaleReferenceLoader::class)
            ->set('query', $queryFragment)
            ->call('updatedQuery')
            ->assertSet('search_results', function ($results) use ($sale) {
                $collection = $this->normaliseResults($results);
                $match = $collection->firstWhere('id', $sale->id);

                return $match
                    && $match['reference'] === $sale->reference
                    && $match['returnable_lines'] === 1
                    && $match['total_available_quantity'] === 5
                    && $match['requires_serials'] === true;
            });
    }

    public function test_it_filters_out_ineligible_sales(): void
    {
        $eligible = $this->createDispatchedSale();
        $ineligible = $this->createDispatchedSale(3, [
            'status' => Sale::STATUS_APPROVED,
            'customer_name' => 'PT Tidak Boleh',
        ]);

        $queryFragment = Str::substr($eligible['sale']->reference, 0, 4);

        Livewire::test(SaleReferenceLoader::class)
            ->set('query', $queryFragment)
            ->call('updatedQuery')
            ->assertSet('search_results', function ($results) use ($eligible, $ineligible) {
                $collection = $this->normaliseResults($results);

                return $collection->contains('id', $eligible['sale']->id)
                    && ! $collection->contains('id', $ineligible['sale']->id);
            });
    }

    public function test_it_respects_existing_returned_quantities(): void
    {
        $data = $this->createDispatchedSale();
        $this->createSaleReturnForDispatch($data['sale'], $data['dispatchDetail'], $data['serialIds'], 2);

        Livewire::test(SaleReferenceLoader::class)
            ->set('query', $data['sale']->reference)
            ->call('updatedQuery')
            ->assertSet('search_results', function ($results) use ($data) {
                $collection = $this->normaliseResults($results);
                $match = $collection->firstWhere('id', $data['sale']->id);

                return $match && $match['total_available_quantity'] === 3;
            });
    }

    public function test_selecting_a_sale_notifies_parent_with_rows_and_resets_state(): void
    {
        $data = $this->createDispatchedSale();
        $sale = $data['sale'];
        $dispatchDetail = $data['dispatchDetail'];

        Livewire::test(SaleReferenceLoader::class)
            ->set('query', $sale->reference)
            ->call('updatedQuery')
            ->call('selectSale', $sale->id)
            ->assertDispatched('saleReferenceSelected', function ($payload) use ($sale, $dispatchDetail): bool {
                return is_array($payload)
                    && $payload['id'] === $sale->id
                    && $payload['reference'] === $sale->reference
                    && $payload['customer_name'] === $sale->customer_name
                    && isset($payload['rows'])
                    && count($payload['rows']) === 1
                    && $payload['rows'][0]['dispatch_detail_id'] === $dispatchDetail->id;
            })
            ->assertSet('query', $sale->reference)
            ->assertSet('how_many', 5)
            ->assertSet('search_results', function ($results) {
                return $this->normaliseResults($results)->isEmpty();
            });
    }

    public function test_enter_key_selects_the_current_match(): void
    {
        $data = $this->createDispatchedSale();
        $sale = $data['sale'];

        Livewire::test(SaleReferenceLoader::class)
            ->set('query', $sale->reference)
            ->call('updatedQuery')
            ->set('highlightedIndex', -1)
            ->call('selectExactMatch')
            ->assertDispatched('saleReferenceSelected', function ($payload) use ($sale): bool {
                return is_array($payload)
                    && $payload['id'] === $sale->id
                    && $payload['reference'] === $sale->reference;
            });
    }
}
