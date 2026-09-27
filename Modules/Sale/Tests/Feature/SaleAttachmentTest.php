<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;

class SaleAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Setting $otherSetting;
    private User $user;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('local');
        Storage::fake('public');

        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        $this->otherSetting = Setting::factory()->create(['is_pkp' => false]);

        foreach (['sales.edit', 'sales.create', 'sales.show'] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.edit', 'sales.create', 'sales.show']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    private function createSale(array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'reference' => 'SALE-TEST',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ], $attributes));
    }

    /** @test */
    public function attachment_upload_works_for_valid_file()
    {
        $sale = $this->createSale();
        
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->post(route('sales.attachments.store', $sale->id), [
            'file' => $file,
        ]);

        $response->assertRedirect();

        $this->assertCount(1, $sale->getMedia('attachments'));
        $this->assertEquals('document.pdf', $sale->getFirstMedia('attachments')->file_name);
    }

    /** @test */
    public function attachment_upload_rejected_if_oversized()
    {
        $sale = $this->createSale();
        
        // Size is in kilobytes, 11000 KB = 11 MB (over 10 MB limit)
        $file = UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf');

        $response = $this->post(route('sales.attachments.store', $sale->id), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertCount(0, $sale->getMedia('attachments'));
    }

    /** @test */
    public function attachment_upload_requires_a_file()
    {
        $sale = $this->createSale();

        $this->post(route('sales.attachments.store', $sale->id), [])
            ->assertSessionHasErrors('file');

        $this->assertCount(0, $sale->getMedia('attachments'));
    }

    /** @test */
    public function attachment_upload_rejects_multiple_files()
    {
        $sale = $this->createSale();

        $this->post(route('sales.attachments.store', $sale->id), [
            'file' => [
                UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
            ],
        ])->assertSessionHasErrors('file');

        $this->assertCount(0, $sale->getMedia('attachments'));
    }
    
    /** @test */
    public function attachment_deletion_works()
    {
        $sale = $this->createSale();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $sale->addMedia($file)->toMediaCollection('attachments');
        $media = $sale->getFirstMedia('attachments');
        
        $this->assertCount(1, $sale->getMedia('attachments'));

        $response = $this->delete(route('sales.attachments.destroy', [$sale->id, $media->id]));
        
        $response->assertRedirect();
        $this->assertCount(0, $sale->fresh()->getMedia('attachments'));
    }

    /** @test */
    public function requires_edit_permission_to_upload_or_delete()
    {
        $sale = $this->createSale();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        $sale->addMedia($file)->toMediaCollection('attachments');
        $media = $sale->getFirstMedia('attachments');

        // Revoke edit permission
        $this->user->revokePermissionTo('sales.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $newFile = UploadedFile::fake()->create('new.pdf', 1024, 'application/pdf');
        
        $uploadResponse = $this->post(route('sales.attachments.store', $sale->id), [
            'file' => $newFile,
        ]);
        $uploadResponse->assertForbidden();

        $deleteResponse = $this->delete(route('sales.attachments.destroy', [$sale->id, $media->id]));
        $deleteResponse->assertForbidden();
    }

    /** @test */
    public function blocks_access_to_sales_from_different_setting()
    {
        $sale = $this->createSale(['setting_id' => $this->otherSetting->id]);
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $response = $this->post(route('sales.attachments.store', $sale->id), [
            'file' => $file,
        ]);
        $response->assertNotFound();
    }

    /** @test */
    public function blocks_mutation_if_sale_is_archived()
    {
        $sale = $this->createSale([
            'archived_at' => now(), 
            'archived_by' => $this->user->id
        ]);
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $response = $this->post(route('sales.attachments.store', $sale->id), [
            'file' => $file,
        ]);
        $response->assertForbidden();

        $mediaFile = UploadedFile::fake()->create('existing.pdf', 10, 'application/pdf');
        $sale->addMedia($mediaFile)->toMediaCollection('attachments');
        $media = $sale->getFirstMedia('attachments');

        $this->delete(route('sales.attachments.destroy', [$sale->id, $media->id]))
            ->assertForbidden();
        $this->assertNotNull($media->fresh());
    }

    /** @test */
    public function foreign_media_rejection()
    {
        $sale = $this->createSale();
        
        // Create an expense and its media
        $expenseCategory = ExpenseCategory::create([
            'category_name' => 'Test',
            'category_description' => 'Test',
            'setting_id' => $this->setting->id
        ]);
        $expense = Expense::create([
            'date' => now(), 
            'reference' => 'EXP', 
            'amount' => 100, 
            'category_id' => $expenseCategory->id, 
            'setting_id' => $this->setting->id, 
            'status' => Expense::STATUS_DRAFT
        ]);
        
        $file = UploadedFile::fake()->create('expense.pdf', 1024, 'application/pdf');
        $expense->addMedia($file)->toMediaCollection('attachments');
        $expenseMedia = $expense->getFirstMedia('attachments');

        // Try to delete expense media via sale route
        $response = $this->delete(route('sales.attachments.destroy', [$sale->id, $expenseMedia->id]));
        $response->assertNotFound();
        $this->assertNotNull($expenseMedia->fresh());
    }

    /** @test */
    public function detail_page_shows_attachment_and_form()
    {
        $sale = $this->createSale();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        $sale->addMedia($file)->toMediaCollection('attachments');
        
        $response = $this->get(route('sales.show', $sale->id));
        $response->assertOk();
        $response->assertSee('document.pdf');
        $response->assertSee('Tambah Lampiran'); // Checks if the form is visible
    }

    /** @test */
    public function detail_page_shows_empty_state_without_attachment()
    {
        $sale = $this->createSale();

        $this->get(route('sales.show', $sale->id))
            ->assertOk()
            ->assertSee('Tidak ada lampiran.');
    }

    /** @test */
    public function detail_page_is_read_only_without_edit_permission()
    {
        $sale = $this->createSale();
        $sale->addMedia(UploadedFile::fake()->create('read-only.pdf', 10, 'application/pdf'))
            ->toMediaCollection('attachments');
        $media = $sale->getFirstMedia('attachments');
        $this->user->revokePermissionTo('sales.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get(route('sales.show', $sale->id))
            ->assertOk()
            ->assertSee('read-only.pdf')
            ->assertDontSee('Tambah Lampiran')
            ->assertDontSee(route('sales.attachments.destroy', [$sale->id, $media->id]), false);
    }
}
