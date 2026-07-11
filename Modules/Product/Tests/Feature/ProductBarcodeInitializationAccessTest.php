<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductBarcodeInitializationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'products.barcodes.manage']);
        Permission::firstOrCreate(['name' => 'products.edit']);
        Permission::firstOrCreate(['name' => 'barcodes.print']);
    }

    public function test_authorized_user_can_access_barcode_initialization()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.barcodes.manage');

        $response = $this->actingAs($user)->get(route('products.barcodes.index'));

        $response->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_access_barcode_initialization()
    {
        $user = User::factory()->create();
        // Give only other permissions
        $user->givePermissionTo('products.edit');
        $user->givePermissionTo('barcodes.print');

        $response = $this->actingAs($user)->get(route('products.barcodes.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_barcode_initialization()
    {
        $response = $this->get(route('products.barcodes.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_menu_visibility()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.barcodes.manage');

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertStatus(200);
        $response->assertSee('Inisialisasi Barcode'); // Assuming 'Inisialisasi Barcode' is the menu text
    }

    public function test_unauthorized_save_attempts()
    {
        $user = User::factory()->create();
        // User without permission

        $response = $this->actingAs($user)->get(route('products.barcodes.index'));
        $response->assertStatus(403);

        // Let's test the livewire component
        $component = \Livewire\Livewire::actingAs($user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->assertForbidden();
    }
}
