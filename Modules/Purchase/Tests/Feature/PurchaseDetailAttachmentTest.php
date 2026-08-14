<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseDetailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $setting = Setting::factory()->create(['is_pkp' => false]);
        $supplier = Supplier::create([
            'supplier_name' => 'Attachment Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'attachment-supplier@example.test',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $setting->id,
        ]);
        $location = Location::create([
            'name' => 'Attachment Location',
            'setting_id' => $setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PURCHASE-ATTACHMENT-TEST',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        Permission::findOrCreate('purchases.update', 'web');
        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchases.update');

        $this->actingAs($this->user);
        session(['setting_id' => $setting->id]);
    }

    public function test_purchase_detail_attachment_upload_remains_available(): void
    {
        $response = $this->post(route('purchases.attachments.store', $this->purchase), [
            'attachment' => UploadedFile::fake()->create('purchase-proof.pdf', 10, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertCount(1, $this->purchase->getMedia('attachments'));
        $this->assertSame(
            'purchase-proof.pdf',
            $this->purchase->getFirstMedia('attachments')->getCustomProperty('original_name')
        );
    }

    public function test_purchase_detail_attachment_deletion_remains_available(): void
    {
        $this->purchase
            ->addMedia(UploadedFile::fake()->create('purchase-proof.pdf', 10, 'application/pdf'))
            ->toMediaCollection('attachments');
        $media = $this->purchase->getFirstMedia('attachments');

        $this->delete(route('purchases.attachments.destroy', [$this->purchase, $media]))
            ->assertRedirect();

        $this->assertNull($media->fresh());
    }
}
