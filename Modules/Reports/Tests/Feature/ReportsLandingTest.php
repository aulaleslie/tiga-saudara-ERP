<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class ReportsLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $permissions = [
            'reports.access',
            'saleReports.access',
            'saleReports.global.access',
            'purchaseReports.access',
            'purchaseReports.global.access',
            'stockMutationReports.access',
            'stockMutationReports.global.access',
            'inventoryValuationReports.access'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    /** @test */
    public function all_permission_user_sees_tabs_in_mekari_order_and_cards()
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::all());

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertStatus(200);
        $response->assertSeeTextInOrder([
            'Sekilas bisnis',
            'Penjualan',
            'Pembelian',
            'Produk',
            'Lainnya'
        ]);

        $response->assertDontSeeText('Aset');
        $response->assertDontSeeText('Bank');
        $response->assertDontSeeText('Pajak');
        $response->assertDontSeeText('Produksi');

        $response->assertSeeText('Laporan Laba Rugi');
        $response->assertSeeText('Menampilkan ringkasan pendapatan, biaya, dan laba/rugi dalam periode tertentu.');
        $response->assertSeeText('Lihat laporan');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'penjualan']));
        $response->assertSeeText('Daftar Penjualan');
        $response->assertSeeText('Penjualan Per Customer');
        $response->assertSeeText('Penjualan Global');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'pembelian']));
        $response->assertSeeText('Daftar Pembelian');
        $response->assertSeeText('Pembelian Per Supplier');
        $response->assertSeeText('Pembelian Global');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'produk']));
        $response->assertSeeText('Mutasi Stok');
        $response->assertSeeText('Mutasi Stok Global');
        $response->assertSeeText('Valuasi Stok');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'lainnya']));
        $response->assertSeeText('Mekari Converter');
        $response->assertSeeText('Mekari Invoice Generator');
    }

    /** @test */
    public function sales_only_user_sees_only_the_penjualan_tab_with_daftar_penjualan_and_penjualan_per_customer()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('saleReports.access');

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Penjualan');
        $response->assertSeeText('Daftar Penjualan');
        $response->assertSeeText('Penjualan Per Customer');
        
        $response->assertDontSeeText('Penjualan Global');
        $response->assertDontSee(route('reports.sale-report.global'));
        
        $response->assertDontSee(route('profit-loss-report.index'));

        $response->assertDontSee(route('reports.purchase-report.index'));

        $response->assertDontSee(route('reports.stock-mutation-report.index'));

        $response->assertDontSee(route('reports.mekari-converter.index'));
    }

    /** @test */
    public function user_with_no_report_permission_is_denied_the_reports_index_route()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertStatus(403);
    }

    /** @test */
    public function tab_resolution_falls_back_to_first_visible_tab_when_missing_invalid_or_unauthorized()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchaseReports.access');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'pembelian']));
        $response->assertStatus(200);
        $response->assertViewHas('activeSlug', 'pembelian');

        $response = $this->actingAs($user)->get(route('reports.index'));
        $response->assertStatus(200);
        $response->assertViewHas('activeSlug', 'pembelian');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'invalid-tab']));
        $response->assertStatus(200);
        $response->assertViewHas('activeSlug', 'pembelian');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'penjualan']));
        $response->assertStatus(200);
        $response->assertViewHas('activeSlug', 'pembelian');
    }
}
