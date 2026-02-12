<?php

namespace Modules\People\Tests\Feature;

use Tests\TestCase;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SupplierUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_update_supplier_with_empty_identity_number_without_unique_constraint_violation()
    {
        // Setup
        $user = User::factory()->create();
        $this->actingAs($user);

        // Mock permissions
        Gate::define('suppliers.edit', fn() => true);
        Gate::define('suppliers.access', fn() => true);

        // Setup setting_id session as expected by controller
        session(['setting_id' => 1]);
        
        // Ensure setting exists for foreign key constraints if any (schema shows setting_id is required)
        // We might need to create a setting if the factory doesn't do it or if we hardcode ID 1.
        // Let's create a setting with ID 1 to be safe, or just rely on factory if it handles it.
        // The controller uses session('setting_id'), so we must ensure the data we create belongs to this setting_id.
        if (DB::table('settings')->where('id', 1)->doesntExist()) {
             DB::table('settings')->insert([
                 'id' => 1,
                 'company_name' => 'Test Company',
                 'company_email' => 'test@example.com',
                 'company_phone' => '123456',
                 'company_address' => 'Address',
                 'default_currency_id' => 1,
                 'default_currency_position' => 'prefix',
                 'notification_email' => 'notify@example.com',
                 'footer_text' => 'Footer Text',
                 'created_at' => now(),
                 'updated_at' => now(),
             ]);
        }


        // Create two suppliers linked to setting_id 1
        $supplier1 = Supplier::factory()->create([
            'supplier_name' => 'Supplier 1',
            'identity_number' => '12345',
            'setting_id' => 1,
        ]);

        $supplier2 = Supplier::factory()->create([
            'supplier_name' => 'Supplier 2',
            'identity_number' => '67890',
            'setting_id' => 1,
        ]);

        // Update supplier 1 with empty identity_number
        $response = $this->put(route('suppliers.update', $supplier1->id), [
            'supplier_name' => 'Supplier 1 Updated',
            'contact_name' => 'Contact 1',
            'supplier_phone' => '08123456789',
            'supplier_email' => 'supplier1@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'identity' => '', // Empty string
            'identity_number' => '', // Empty string
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('suppliers.index'));
        
        // Update supplier 2 with empty identity_number
        // This should fail if "" is saved because distinct "" values collide on unique constraint
        $response2 = $this->put(route('suppliers.update', $supplier2->id), [
            'supplier_name' => 'Supplier 2 Updated',
            'contact_name' => 'Contact 2',
            'supplier_phone' => '08987654321',
            'supplier_email' => 'supplier2@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'identity' => '', // Empty string
            'identity_number' => '', // Empty string
        ]);
        
        // If the bug exists, this might fail with 500 or session errors
        $response2->assertSessionHasNoErrors();
        $response2->assertRedirect(route('suppliers.index'));

        // Final check: both should have NULL Identity Number
        $this->assertNull(Supplier::find($supplier1->id)->identity_number);
        $this->assertNull(Supplier::find($supplier2->id)->identity_number);
    }
}
