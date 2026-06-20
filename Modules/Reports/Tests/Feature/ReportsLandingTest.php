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
            'Pajak',
            'Lainnya'
        ]);

        $response->assertDontSeeText('Aset');
        $response->assertDontSeeText('Bank');
        $response->assertDontSeeText('Produksi');

        $response->assertSeeText('Laporan Laba Rugi');
        $response->assertSeeText('Menampilkan ringkasan pendapatan, biaya, dan laba/rugi dalam periode tertentu.');
        $response->assertSeeText('Lihat laporan');

        $response->assertSeeText('Neraca');
        $response->assertSeeText('Buku besar');
        $response->assertSeeText('Arus kas');
        $response->assertSeeText('Neraca saldo');
        
        $response->assertDontSeeText('Jurnal');
        $response->assertDontSeeText('Perubahan Modal');
        $response->assertDontSeeText('Ringkasan Bisnis');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'penjualan']));
        $response->assertSeeText('Daftar Penjualan');
        $response->assertSeeText('Penjualan Per Customer');
        $response->assertSeeText('Penjualan Global');
        $response->assertSeeText('Piutang pelanggan');
        $response->assertSeeText('Usia piutang');
        $response->assertSeeText('Pengiriman penjualan');
        $response->assertSeeText('Penjualan per produk');
        $response->assertSeeText('Penyelesaian pesanan penjualan');
        $response->assertSeeText('Daftar faktur proforma');
        $response->assertSeeText('Daftar tukar faktur');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'pembelian']));
        $response->assertSeeText('Daftar Pembelian');
        $response->assertSeeText('Pembelian Per Supplier');
        $response->assertSeeText('Pembelian Global');
        $response->assertSeeText('Utang supplier');
        $response->assertSeeText('Daftar pengeluaran');
        $response->assertSeeText('Detail pengeluaran');
        $response->assertSeeText('Usia utang');
        $response->assertSeeText('Pengiriman pembelian');
        $response->assertSeeText('Pembelian per produk');
        $response->assertSeeText('Penyelesaian pesanan pembelian');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'produk']));
        $response->assertSeeText('Mutasi Stok');
        $response->assertSeeText('Mutasi Stok Global');
        $response->assertSeeText('Valuasi Stok');
        $response->assertSeeText('Ringkasan persediaan barang');
        $response->assertSeeText('Kuantitas stok gudang');
        $response->assertSeeText('Nilai persediaan barang');
        $response->assertSeeText('Nilai stok gudang');
        $response->assertSeeText('Detail persediaan barang');
        $response->assertSeeText('Pergerakan barang gudang');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'pajak']));
        $response->assertSeeText('Pajak pemotongan');
        $response->assertSeeText('Pajak penjualan');

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
        $response->assertSeeText('Piutang pelanggan');
        
        $response->assertDontSeeText('Penjualan Global');
        $response->assertDontSee(route('reports.sale-report.global'));
        
        $response->assertDontSeeText('Sekilas bisnis');
        $response->assertDontSeeText('Pembelian');
        $response->assertDontSeeText('Produk');
        $response->assertDontSeeText('Pajak');
        $response->assertDontSeeText('Lainnya');
        $response->assertDontSeeText('Neraca');
        $response->assertDontSeeText('Utang supplier');
        $response->assertDontSeeText('Pajak pemotongan');

        $response->assertDontSee(route('profit-loss-report.index'));

        $response->assertDontSee(route('reports.purchase-report.index'));

        $response->assertDontSee(route('reports.stock-mutation-report.index'));

        $response->assertDontSee(route('reports.mekari-converter.index'));
    }

    /** @test */
    public function placeholder_cards_show_belum_tersedia_and_do_not_render_links()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('reports.access');

        $response = $this->actingAs($user)->get(route('reports.index', ['tab' => 'pajak']));

        $response->assertStatus(200);
        $response->assertSeeText('Pajak pemotongan');
        $response->assertSeeText('Belum tersedia');
        // Assert we don't see an anchor tag for any report route, or 'Lihat laporan'
        $response->assertDontSeeText('Lihat laporan');
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
