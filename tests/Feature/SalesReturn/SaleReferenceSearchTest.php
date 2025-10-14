<?php

namespace Tests\Feature\SalesReturn;

use App\Livewire\SalesReturn\SaleReferenceSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Sale\Entities\Sale;
use Tests\TestCase;

class SaleReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    private function createSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'customer_name' => 'Acme Corp',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 125000,
            'paid_amount' => 125000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => null,
        ], $overrides));
    }

    public function test_it_returns_matching_sale_references(): void
    {
        $sale = $this->createSale([
            'customer_name' => 'PT. Pelanggan',
        ]);

        $queryFragment = Str::substr($sale->reference, 0, 6);

        Livewire::test(SaleReferenceSearch::class)
            ->set('query', $queryFragment)
            ->call('updatedQuery')
            ->assertSet('searchResults', function ($results) use ($sale) {
                if (is_array($results)) {
                    $collection = collect($results);

                    return $collection->contains('id', $sale->id)
                        && $collection->contains('reference', $sale->reference);
                }

                return method_exists($results, 'contains')
                    && $results->contains('id', $sale->id)
                    && $results->contains('reference', $sale->reference);
            });
    }

    public function test_selecting_a_sale_notifies_parent_and_resets_state(): void
    {
        $sale = $this->createSale([
            'customer_name' => 'CV. Test Customer',
        ]);

        Livewire::test(SaleReferenceSearch::class)
            ->set('query', $sale->reference)
            ->call('updatedQuery')
            ->call('selectSale', $sale->id)
            ->assertDispatched('saleReferenceSelected', function ($payload) use ($sale): bool {
                return is_array($payload)
                    && $payload['id'] === $sale->id
                    && $payload['reference'] === $sale->reference
                    && $payload['customer_name'] === $sale->customer_name;
            })
            ->assertSet('query', '')
            ->assertSet('howMany', 5)
            ->assertSet('searchResults', function ($results) {
                if (is_array($results)) {
                    return empty($results);
                }

                return method_exists($results, 'isEmpty') && $results->isEmpty();
            });
    }
}
