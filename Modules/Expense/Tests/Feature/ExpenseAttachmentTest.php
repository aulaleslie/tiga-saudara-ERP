<?php

namespace Modules\Expense\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Sale\Entities\Sale;
use Modules\People\Entities\Customer;

class ExpenseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Setting $otherSetting;
    private User $user;
    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('local');
        Storage::fake('public');

        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        $this->otherSetting = Setting::factory()->create(['is_pkp' => false]);

        foreach (['expenses.edit', 'expenses.create', 'expenses.show', 'expenses.access'] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['expenses.edit', 'expenses.create', 'expenses.show', 'expenses.access']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->category = ExpenseCategory::create([
            'category_name' => 'Test',
            'category_description' => 'Test',
            'setting_id' => $this->setting->id
        ]);
    }

    private function createExpense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'date' => now()->toDateString(),
            'reference' => 'EXP-TEST',
            'category_id' => $this->category->id,
            'status' => Expense::STATUS_DRAFT,
            'amount' => 1000,
            'setting_id' => $this->setting->id,
        ], $attributes));
    }

    /** @test */
    public function attachment_upload_works_for_valid_file()
    {
        $expense = $this->createExpense();
        
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => $file,
        ]);

        $response->assertRedirect();

        $this->assertCount(1, $expense->getMedia('attachments'));
        $this->assertEquals('document.pdf', $expense->getFirstMedia('attachments')->file_name);
    }

    /** @test */
    public function attachment_upload_rejected_if_oversized()
    {
        $expense = $this->createExpense();
        
        // Size is in kilobytes, 11000 KB = 11 MB (over 10 MB limit)
        $file = UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf');

        $response = $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertCount(0, $expense->getMedia('attachments'));
    }

    /** @test */
    public function attachment_upload_requires_a_file()
    {
        $expense = $this->createExpense();

        $this->post(route('expenses.attachments.store', $expense->id), [])
            ->assertSessionHasErrors('file');

        $this->assertCount(0, $expense->getMedia('attachments'));
    }

    /** @test */
    public function attachment_upload_rejects_multiple_files()
    {
        $expense = $this->createExpense();

        $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => [
                UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
            ],
        ])->assertSessionHasErrors('file');

        $this->assertCount(0, $expense->getMedia('attachments'));
    }
    
    /** @test */
    public function attachment_deletion_works()
    {
        $expense = $this->createExpense();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $expense->addMedia($file)->toMediaCollection('attachments');
        $media = $expense->getFirstMedia('attachments');
        
        $this->assertCount(1, $expense->getMedia('attachments'));

        $response = $this->delete(route('expenses.attachments.destroy', [$expense->id, $media->id]));
        
        $response->assertRedirect();
        $this->assertCount(0, $expense->fresh()->getMedia('attachments'));
    }

    /** @test */
    public function requires_edit_permission_to_upload_or_delete()
    {
        $expense = $this->createExpense();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        $expense->addMedia($file)->toMediaCollection('attachments');
        $media = $expense->getFirstMedia('attachments');

        // Revoke edit permission
        $this->user->revokePermissionTo('expenses.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $newFile = UploadedFile::fake()->create('new.pdf', 1024, 'application/pdf');
        
        $uploadResponse = $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => $newFile,
        ]);
        $uploadResponse->assertForbidden();

        $deleteResponse = $this->delete(route('expenses.attachments.destroy', [$expense->id, $media->id]));
        $deleteResponse->assertForbidden();
    }

    /** @test */
    public function blocks_access_to_expenses_from_different_setting()
    {
        $expense = $this->createExpense(['setting_id' => $this->otherSetting->id]);
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $response = $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => $file,
        ]);
        $response->assertForbidden();
    }

    /** @test */
    public function blocks_mutation_if_expense_is_archived()
    {
        $expense = $this->createExpense([
            'archived_at' => now(), 
            'archived_by' => $this->user->id
        ]);
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        
        $response = $this->post(route('expenses.attachments.store', $expense->id), [
            'file' => $file,
        ]);
        $response->assertForbidden();

        $mediaFile = UploadedFile::fake()->create('existing.pdf', 10, 'application/pdf');
        $expense->addMedia($mediaFile)->toMediaCollection('attachments');
        $media = $expense->getFirstMedia('attachments');

        $this->delete(route('expenses.attachments.destroy', [$expense->id, $media->id]))
            ->assertForbidden();
        $this->assertNotNull($media->fresh());
    }

    /** @test */
    public function foreign_media_rejection()
    {
        $expense = $this->createExpense();
        
        // Create a sale and its media
        Permission::findOrCreate('sales.create', 'web');
        $this->user->givePermissionTo('sales.create');
        
        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'setting_id' => $this->setting->id,
        ]);
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);
        
        $file = UploadedFile::fake()->create('sale.pdf', 1024, 'application/pdf');
        $sale->addMedia($file)->toMediaCollection('attachments');
        $saleMedia = $sale->getFirstMedia('attachments');

        // Try to delete sale media via expense route
        $response = $this->delete(route('expenses.attachments.destroy', [$expense->id, $saleMedia->id]));
        $response->assertNotFound();
        $this->assertNotNull($saleMedia->fresh());
    }

    /** @test */
    public function detail_page_shows_attachment_and_form()
    {
        $expense = $this->createExpense();
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
        $expense->addMedia($file)->toMediaCollection('attachments');
        
        $response = $this->get(route('expenses.show', $expense->id));
        $response->assertOk();
        $response->assertSee('document.pdf');
        $response->assertSee('Tambah Lampiran'); // Checks if the form is visible
    }

    /** @test */
    public function detail_page_shows_empty_state_without_attachment()
    {
        $expense = $this->createExpense();

        $this->get(route('expenses.show', $expense->id))
            ->assertOk()
            ->assertSee('Tidak ada lampiran.');
    }

    /** @test */
    public function detail_page_is_read_only_without_edit_permission()
    {
        $expense = $this->createExpense();
        $expense->addMedia(UploadedFile::fake()->create('read-only.pdf', 10, 'application/pdf'))
            ->toMediaCollection('attachments');
        $media = $expense->getFirstMedia('attachments');
        $this->user->revokePermissionTo('expenses.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get(route('expenses.show', $expense->id))
            ->assertOk()
            ->assertSee('read-only.pdf')
            ->assertDontSee('Tambah Lampiran')
            ->assertDontSee(route('expenses.attachments.destroy', [$expense->id, $media->id]), false);
    }
}
