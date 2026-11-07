<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** The "Metode" summary on the return page comes from per-item settlements, never from the header placeholder. */
class ReturnMethodSummaryRenderingTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    private array $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->flow = $this->makeDispatchedSale(3, false);
    }

    private function returnWith(string $headerMethod, ?string $returnType = null): SaleReturn
    {
        return $this->makeReturnFor($this->flow, 2, [
            'status' => 'Completed', 'approval_status' => 'approved', 'received_at' => now(), 'settled_at' => now(),
            'payment_method' => $headerMethod, 'payment_status' => 'Paid', 'return_type' => $returnType,
        ]);
    }

    private function page(SaleReturn $r)
    {
        return $this->withSession(['setting_id' => 1])->get(route('sale-returns.show', $r));
    }

    private function metode(string $html): string
    {
        preg_match('#Metode</dt>\s*<dd[^>]*>(.*?)</dd>#s', $html, $m);

        return trim(html_entity_decode(strip_tags($m[1] ?? '')));
    }

    /** @test */
    public function completed_repair_return_with_pending_header_shows_perbaikan(): void
    {
        $r = $this->returnWith('Pending');
        $this->settlementFor($r, Settlement::STATUS_DISPATCHED, 'REPAIR');
        $before = DB::table('sale_returns')->where('id', $r->id)->first();

        $html = $this->page($r)->assertOk()->getContent();

        $this->assertSame('Perbaikan/Pergantian Produk', $this->metode($html));
        $this->assertStringNotContainsString('Metode: Perbaikan/Pergantian Produk', $html);
        $this->assertStringNotContainsString('Metode: PENDING', $html);
        $this->assertStringNotContainsString('Metode: Pending', $html);
        $this->assertStringNotContainsStringIgnoringCase('Metode</dt>' . "\n" . '                                            <dd class="col-7">Pending', $html);
        $this->assertEquals($before, DB::table('sale_returns')->where('id', $r->id)->first(), 'rendering must not write');
    }

    /** @test */
    public function cash_only_settlement_shows_pengembalian_tunai(): void
    {
        $r = $this->returnWith('Pending');
        $this->settlementFor($r, Settlement::STATUS_APPROVED, 'CASH_REFUND');

        $this->assertSame('Pengembalian Tunai', $this->metode($this->page($r)->getContent()));
    }

    /** @test */
    public function mixed_methods_show_campuran_and_a_rejected_choice_is_ignored(): void
    {
        $r = $this->returnWith('Pending');
        $this->settlementFor($r, Settlement::STATUS_APPROVED, 'CASH_REFUND');
        $this->settlementFor($r, Settlement::STATUS_DISPATCHED, 'REPAIR');
        $this->assertSame('Campuran — lihat penyelesaian per item', $this->metode($this->page($r)->getContent()));

        $r2 = $this->returnWith('Pending');
        $this->settlementFor($r2, Settlement::STATUS_REJECTED, 'CASH_REFUND');
        $this->settlementFor($r2, Settlement::STATUS_DISPATCHED, 'REPAIR');
        $this->assertSame('Perbaikan/Pergantian Produk', $this->metode($this->page($r2)->getContent()));
    }

    /** @test */
    public function unselected_settlement_shows_belum_ditentukan(): void
    {
        $r = $this->returnWith('Pending');
        Settlement::create([
            'sale_return_id' => $r->id, 'sale_return_detail_id' => $r->saleReturnDetails()->value('id'),
            'method' => null, 'nominal' => 0, 'status' => Settlement::STATUS_DRAFT,
        ]);

        $this->assertSame('Belum ditentukan', $this->metode($this->page($r)->getContent()));
    }

    /** @test */
    public function legacy_header_only_return_keeps_its_recognized_label_and_never_shows_pending(): void
    {
        $this->assertSame('Pengembalian Tunai', $this->metode($this->page($this->returnWith('Cash'))->getContent()));
        $this->assertSame('Perbaikan/Pergantian Produk', $this->metode($this->page($this->returnWith('Pending', 'repair'))->getContent()));
        $this->assertSame('Belum ditentukan', $this->metode($this->page($this->returnWith('Pending'))->getContent()));
    }
}
