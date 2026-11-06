<?php

namespace Tests\Feature\SalesReturn;

use App\Livewire\SalesReturn\SaleReferenceSearchDropdown;
use App\Livewire\SalesReturn\SaleReturnCreateForm;
use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Tests\TestCase;

class StandardCreateEditOwnershipTest extends TestCase
{
    use RefreshDatabase, BuildsWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableForeignKeys();
        $this->actingAs(User::factory()->create());
    }

    /** @test */
    public function lookup_excludes_pos_origin_sales_and_shows_pos_guidance(): void
    {
        $pos = $this->makeSale('POSONLY');
        $this->linkSplitCheckoutSale($pos);

        Livewire::test(SaleReferenceSearchDropdown::class)
            ->set('search', 'POSONLY')
            ->assertSet('options', [])
            ->assertSet('posNotice', 'Penjualan POS tidak dapat diretur di sini. Gunakan menu Retur POS (/pos/returns).');
    }

    /** @test */
    public function direct_livewire_payload_cannot_select_a_pos_sale_even_with_prefetched_rows(): void
    {
        $pos = $this->makeSale();
        $this->linkLegacyCheckout($pos);

        Livewire::test(SaleReturnCreateForm::class)
            ->call('handleSaleSelected', ['id' => $pos->id, 'rows' => [['sale_detail_id' => 1, 'quantity' => 1]], 'reference' => $pos->reference])
            ->assertSet('sale_id', null)
            ->assertSet('rows', []);

    }

    /** @test */
    public function create_submit_rejects_a_pos_sale_and_writes_nothing(): void
    {
        $pos = $this->makeSale();
        $this->linkSplitCheckoutSale($pos);
        $before = SaleReturn::withArchived()->count();

        Livewire::test(SaleReturnCreateForm::class)
            ->set('sale_id', $pos->id)
            ->set('rows', [['sale_detail_id' => 1, 'dispatch_detail_id' => 1, 'quantity' => 1, 'product_id' => 1]])
            ->call('submit');

        $this->assertSame($before, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function edit_page_of_a_pos_linked_return_is_rejected_but_the_record_is_untouched(): void
    {
        $sale = $this->makeSale();
        $return = $this->makeReturn($sale, ['pos_return_id' => 12]);
        $snapshot = $return->fresh()->toArray();

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])->assertStatus(409);

        $this->assertSame($snapshot, $return->fresh()->toArray());
    }
}
