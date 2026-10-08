<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPurchasePaymentCreateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting1;
    protected $setting2;
    protected $supplier;
    protected $purchase1;
    protected $purchase2;
    protected $purchaseUnreceived;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Currency ID 1 first
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ','
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting1@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1'
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting2@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2'
        ]);

        Permission::firstOrCreate(['name' => 'purchasePayments.global.access']);
        Permission::firstOrCreate(['name' => 'purchasePayments.create']);
        
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo(['purchasePayments.global.access', 'purchasePayments.create']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Global ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address',
            'setting_id' => $this->setting1->id, // Supplier belongs to setting1
        ]);

        $this->purchase1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'tax_rate' => 0,
            'setting_id' => $this->setting1->id,
        ]);
        
        $this->purchase2 = $this->purchase1->replicate();
        $this->purchase2->reference = 'PR-2';
        $this->purchase2->setting_id = $this->setting2->id;
        $this->purchase2->status = \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED;
        $this->purchase2->save();

        $this->purchaseUnreceived = $this->purchase1->replicate();
        $this->purchaseUnreceived->reference = 'PR-3';
        $this->purchaseUnreceived->status = 'Pending';
        $this->purchaseUnreceived->save();
    }

    public function test_candidate_query_across_settings()
    {
        // Actually STATUS_RECEIVED is "Completed".
        // Ensure that purchases with Completed status are picked up, across settings.
        
        session(['setting_id' => $this->setting1->id]);
        
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $this->purchase1->id
        ]));
        
        $response->assertStatus(200);
        
        // Assert it sees both PR-1 and PR-2 because they are Completed and cross-setting
        $response->assertSee(route('purchases.global-payments.show', $this->purchase1->id));
        $response->assertSee(route('purchases.global-payments.show', $this->purchase2->id));
        
        // Assert it doesn't see PR-3 because it is Pending
        $response->assertDontSee(route('purchases.global-payments.show', $this->purchaseUnreceived->id));
    }
    
    public function test_rejects_ineligible_starting_purchase()
    {
        session(['setting_id' => $this->setting1->id]);
        
        // Try starting with an unreceived purchase
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $this->purchaseUnreceived->id
        ]));
        // Should redirect back to index
        $response->assertRedirect(route('purchases.global-payments.index'));
    }

    public function test_purchase_create_form_pins_starting_purchase_and_orders_candidates_by_due_date_and_id()
    {
        session(['setting_id' => $this->setting1->id]);

        $entryPurchase = Purchase::create(array_merge($this->purchase1->toArray(), [
            'reference' => 'ENTRY-PURCHASE',
            'due_date' => '2026-10-01',
            'note' => 'Entry purchase note',
            'setting_id' => $this->setting1->id,
        ]));

        $earlierPurchase = Purchase::create(array_merge($this->purchase1->toArray(), [
            'reference' => 'EARLIER-PURCHASE',
            'due_date' => '2026-09-01',
            'note' => 'Earlier purchase note',
            'setting_id' => $this->setting1->id,
        ]));

        $latePurchase = Purchase::create(array_merge($this->purchase1->toArray(), [
            'reference' => 'LATE-PURCHASE',
            'due_date' => '2026-12-31',
            'note' => 'Late purchase note',
            'setting_id' => $this->setting1->id,
        ]));

        $tie1 = Purchase::create(array_merge($this->purchase1->toArray(), [
            'reference' => 'TIE-1',
            'due_date' => '2026-09-15',
            'setting_id' => $this->setting1->id,
        ]));

        $tie2 = Purchase::create(array_merge($this->purchase1->toArray(), [
            'reference' => 'TIE-2',
            'due_date' => '2026-09-15',
            'setting_id' => $this->setting1->id,
        ]));

        // Request with starting purchase_id
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $entryPurchase->id,
        ]));

        $response->assertOk();

        /** @var \Illuminate\Database\Eloquent\Collection $candidates */
        $candidates = $response->viewData('candidates');
        $candidateIds = $candidates->pluck('id')->filter(fn ($id) => in_array($id, [
            $entryPurchase->id, $earlierPurchase->id, $latePurchase->id, $tie1->id, $tie2->id,
        ]))->values()->all();

        $this->assertEquals([
            $entryPurchase->id,
            $earlierPurchase->id,
            min($tie1->id, $tie2->id),
            max($tie1->id, $tie2->id),
            $latePurchase->id,
        ], $candidateIds);

        // Verify escaped note rendering
        $response->assertSee('Entry purchase note');
        $response->assertSee('Earlier purchase note');
        $response->assertSee('Late purchase note');

        // Request without starting purchase_id (supplier context entry)
        $supplierResponse = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
        ]));

        $supplierResponse->assertOk();
        /** @var \Illuminate\Database\Eloquent\Collection $supplierCandidates */
        $supplierCandidates = $supplierResponse->viewData('candidates');
        $supplierCandidateIds = $supplierCandidates->pluck('id')->filter(fn ($id) => in_array($id, [
            $entryPurchase->id, $earlierPurchase->id, $latePurchase->id, $tie1->id, $tie2->id,
        ]))->values()->all();

        $this->assertEquals([
            $earlierPurchase->id,
            min($tie1->id, $tie2->id),
            max($tie1->id, $tie2->id),
            $entryPurchase->id,
            $latePurchase->id,
        ], $supplierCandidateIds);
    }

    public function test_renders_multiple_file_attachment_control_per_row_with_stable_purchase_id()
    {
        session(['setting_id' => $this->setting1->id]);

        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $this->purchase1->id,
        ]));

        $response->assertOk();

        // Check context columns are preserved
        $response->assertSee('Nomor Transaksi');
        $response->assertSee('No. Pembelian Supplier');
        $response->assertSee('Deskripsi');
        $response->assertSee('Jatuh Tempo');
        $response->assertSee('Total');
        $response->assertSee('Sisa Tagihan');
        $response->assertSee('Jumlah Dibayar');
        $response->assertSee('Lampiran');

        // Check dropzone container is rendered for each purchase row with stable purchase id
        $response->assertSee('id="dropzone-purchase-' . $this->purchase1->id . '"', false);
        $response->assertSee('data-purchase-id="' . $this->purchase1->id . '"', false);
        $response->assertSee('id="dropzone-purchase-' . $this->purchase2->id . '"', false);
        $response->assertSee('data-purchase-id="' . $this->purchase2->id . '"', false);

        // Ensure legacy top-level dropzone is not rendered
        $response->assertDontSee('id="file-dropzone"', false);
    }

    public function test_form_preserves_row_specific_hidden_inputs_and_uses_individual_staging_endpoints()
    {
        session(['setting_id' => $this->setting1->id]);

        // Create > 10 candidate purchases so they span across multiple DataTables pages
        for ($i = 3; $i <= 12; $i++) {
            $p = $this->purchase1->replicate();
            $p->reference = "PR-PAGINATED-{$i}";
            $p->save();
        }

        // Simulate session old input with row-specific allocations and attachments
        $oldSession = [
            'allocations' => [
                $this->purchase1->id => 500,
                $this->purchase2->id => 750,
            ],
            'attachments' => [
                $this->purchase1->id => ['receipt1.pdf', 'invoice1.png'],
                $this->purchase2->id => ['contract2.docx'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->withSession(['_old_input' => $oldSession])
            ->get(route('purchases.global-payments.create', [
                'supplier' => $this->supplier->id,
            ]));

        $response->assertOk();

        // 1. Verify individual staging and delete route endpoints are present in page scripts
        $response->assertSee(route('purchase-payments.attachments.upload'));
        $response->assertSee(route('purchase-payments.attachments.delete'));

        // 2. Verify all 12 candidate rows are rendered in HTML markup (DataTables manages them client-side)
        $response->assertSee('PR-PAGINATED-12');

        // 3. Verify row-specific hidden inputs for attachments were initialized from old input
        $response->assertSee('name="attachments[' . $this->purchase1->id . '][]"', false);
        $response->assertSee('value="receipt1.pdf"', false);
        $response->assertSee('value="invoice1.png"', false);
        $response->assertSee('name="attachments[' . $this->purchase2->id . '][]"', false);
        $response->assertSee('value="contract2.docx"', false);

        // 4. Verify client script embeds rowAttachmentsMap tracking for off-page rows
        $response->assertSee('rowAttachmentsMap[' . $this->purchase1->id . '].push({', false);
        $response->assertSee('serverName: "receipt1.pdf"', false);

        // 5. Verify submit handler blocks failed/error uploads
        $response->assertSee('getFilesWithStatus(Dropzone.ERROR)');
    }
}
