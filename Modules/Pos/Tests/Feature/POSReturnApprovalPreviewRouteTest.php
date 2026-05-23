<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewRouteTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $approver;

    protected $viewer;

    protected $terminal;

    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Approval Preview Route Test');

        foreach ([
            'pos.returns.view',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.approve',
        ]);

        $this->viewer = $this->createUserForSetting($this->setting, 'POS Return Viewer', [
            'pos.access',
            'pos.returns.view',
        ]);

        [$this->terminal] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->approver);
    }

    /** @test */
    public function it_requires_approve_permission_to_open_approval_preview(): void
    {
        $posReturn = $this->createPosReturn();

        $this->actingAsInSetting($this->viewer, $this->setting);

        $this->get(route('pos.returns.approval-preview', $posReturn))->assertStatus(403);
    }

    /** @test */
    public function it_blocks_preview_for_non_pending_returns_without_mutating_anything(): void
    {
        $posReturn = $this->createPosReturn([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertRedirect(route('pos.returns.show', $posReturn));

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'approved_by' => $this->approver->id,
        ]);
    }

    /** @test */
    public function preview_route_opens_preview_without_approving_immediately(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'status' => 'ready',
            'is_blocked' => false,
            'blockers' => [],
            'warnings' => [],
            'info' => [],
            'groups' => [],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()
            ->assertSee('Preview Persetujuan Retur POS')
            ->assertSee('Preview siap dieksekusi')
            ->assertSee('Setujui Retur');

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /** @test */
    public function preview_route_renders_component_targets_and_mixed_resolution_summary(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'status' => 'ready',
            'is_blocked' => false,
            'blockers' => [],
            'warnings' => [],
            'info' => [],
            'groups' => [
                [
                    'source_sale' => [
                        'id' => 99,
                        'reference' => 'SO-COMP-001',
                        'status' => 'DISPATCHED',
                    ],
                    'source_owner' => [
                        'setting_id' => 10,
                        'name' => 'Owner Split',
                    ],
                    'source_location' => [
                        'location_id' => 20,
                        'name' => 'Gudang Split',
                    ],
                    'tax_context' => [
                        'tax_id' => null,
                        'tax_name' => null,
                    ],
                    'linked_sale_return_references' => [],
                    'planned_header' => [
                        'sale_id' => 99,
                        'sale_reference' => 'SO-COMP-001',
                        'setting_id' => 10,
                        'setting_name' => 'Owner Split',
                        'location_id' => 20,
                        'location_name' => 'Gudang Split',
                        'return_type' => 'cash_return',
                        'line_count' => 2,
                        'parent_line_count' => 1,
                        'component_line_count' => 1,
                        'cash_return_line_count' => 2,
                        'product_replacement_line_count' => 0,
                        'resolution_labels' => ['Cash Return'],
                        'total_amount' => 100,
                        'cash_return_total' => 100,
                    ],
                    'planned_details' => [
                        [
                            'row_type' => 'component',
                            'product_name' => 'Component Battery',
                            'product_code' => 'COMP-BATT',
                            'resolution' => 'cash_return',
                            'resolution_label' => 'Cash Return',
                            'quantity' => 1,
                            'amount' => 0,
                            'cash_return_amount' => 0,
                            'dispatch_detail_id' => null,
                            'dispatch_resolution' => 'sale_bundle_item',
                            'source_setting_name' => 'Owner Split',
                            'source_location_name' => 'Gudang Split',
                            'tax_name' => null,
                            'returned_serial' => 'SN-RET-001',
                            'replacement_serial' => null,
                            'stock_movement_intent' => 'stok_sumber_akan_bertambah_saat_receiving',
                            'serial_movement_intent' => 'tidak_ada_mutasi_serial',
                            'bundle_trace' => [['product_id' => 5]],
                            'source_pos_product_name' => 'Phone Bundle',
                            'source_pos_product_code' => 'PHONE-BUNDLE',
                            'component_line_group_key' => 'standalone-1-5',
                            'component_quantity_per_bundle' => 1,
                        ],
                        [
                            'row_type' => 'parent',
                            'product_name' => 'Phone Bundle',
                            'product_code' => 'PHONE-BUNDLE',
                            'resolution' => 'cash_return',
                            'resolution_label' => 'Cash Return',
                            'quantity' => 1,
                            'amount' => 100,
                            'cash_return_amount' => 100,
                            'dispatch_detail_id' => 44,
                            'dispatch_resolution' => 'returned_serial.dispatch_detail_id',
                            'source_setting_name' => 'Owner Split',
                            'source_location_name' => 'Gudang Split',
                            'tax_name' => null,
                            'returned_serial' => 'SN-RET-001',
                            'replacement_serial' => null,
                            'stock_movement_intent' => 'stok_sumber_akan_bertambah_saat_receiving',
                            'serial_movement_intent' => 'serial_retur_dilepas_dari_dispatch_saat_receiving',
                            'bundle_trace' => [['product_id' => 5]],
                            'source_pos_product_name' => 'Phone Bundle',
                            'source_pos_product_code' => 'PHONE-BUNDLE',
                            'component_line_group_key' => null,
                            'component_quantity_per_bundle' => null,
                        ],
                    ],
                ],
            ],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()
            ->assertSee('Komponen Bundle')
            ->assertSee('Dari item POS: Phone Bundle')
            ->assertSee('Source Sale / Dokumen')
            ->assertSee('SO-COMP-001')
            ->assertDontSee('Ringkasan Resolusi Baris')
            ->assertDontSee('Dispatch')
            ->assertSee('Setujui Retur');
    }

    /** @test */
    public function preview_route_renders_cross_owner_replacement_execution_details(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'status' => 'ready',
            'is_blocked' => false,
            'blockers' => [],
            'warnings' => [],
            'info' => [],
            'groups' => [[
                'source_sale' => [
                    'id' => 101,
                    'reference' => 'SO-CROSS-001',
                    'status' => 'DISPATCHED',
                ],
                'source_owner' => [
                    'setting_id' => 10,
                    'name' => 'Owner A',
                ],
                'source_location' => [
                    'location_id' => 20,
                    'name' => 'Gudang A',
                ],
                'tax_context' => [
                    'tax_id' => null,
                    'tax_name' => null,
                ],
                'linked_sale_return_references' => [],
                'planned_header' => [
                    'sale_id' => 101,
                    'sale_reference' => 'SO-CROSS-001',
                    'setting_id' => 10,
                    'setting_name' => 'Owner A',
                    'location_id' => 20,
                    'location_name' => 'Gudang A',
                    'return_type' => 'product_replacement',
                    'line_count' => 1,
                    'parent_line_count' => 1,
                    'component_line_count' => 0,
                    'cash_return_line_count' => 0,
                    'product_replacement_line_count' => 1,
                    'resolution_labels' => ['Product Replacement'],
                    'total_amount' => 600,
                    'cash_return_total' => 0,
                ],
                'planned_details' => [[
                    'row_type' => 'parent',
                    'product_name' => 'Cross Owner Product',
                    'product_code' => 'CROSS-001',
                    'resolution' => 'product_replacement',
                    'resolution_label' => 'Product Replacement',
                    'quantity' => 1,
                    'amount' => 600,
                    'cash_return_amount' => 0,
                    'dispatch_detail_id' => 55,
                    'dispatch_resolution' => 'returned_serial.dispatch_detail_id',
                    'source_setting_name' => 'Owner A',
                    'source_location_name' => 'Gudang A',
                    'tax_name' => null,
                    'returned_serial' => 'SN-RET-001',
                    'replacement_serial' => 'SN-REP-001',
                    'replacement_serial_owner_setting_name' => 'Owner B',
                    'replacement_serial_location_name' => 'Gudang B',
                    'execution_mode' => 'cross_owner_replacement',
                    'execution_mode_label' => 'Cross-owner replacement',
                    'original_sale_correction_quantity' => 1,
                    'original_sale_correction_amount' => 600,
                    'generated_replacement_sale_effects' => [
                        'setting_name' => 'Owner B',
                        'location_name' => 'Gudang B',
                        'customer_resolution_source' => 'selected',
                        'payment_amount' => 600,
                    ],
                    'stock_movement_intent' => 'stok_retur_kembali_ke_owner_asal_dan_serial_pengganti_keluar_dari_owner_pengganti',
                    'serial_movement_intent' => 'serial_retur_kembali_ke_sale_asal_dan_serial_pengganti_dikirim_dari_owner_pengganti',
                    'bundle_trace' => [],
                    'source_pos_product_name' => 'Cross Owner Product',
                    'source_pos_product_code' => 'CROSS-001',
                ]],
            ]],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()
            ->assertSee('Mode: Cross-owner replacement')
            ->assertSee('Pengganti:')
            ->assertSee('Owner B')
            ->assertSee('Gudang B')
            ->assertSee('Koreksi sale asal:')
            ->assertSee('Sale pengganti:')
            ->assertSee('Customer: selected');
    }

    /** @test */
    public function direct_approval_post_rebuilds_preview_and_redirects_to_show_after_success(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'blockers' => [],
            'warnings' => [],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $lifecycle = \Mockery::mock(PosReturnLifecycleService::class);
        $lifecycle->shouldReceive('executeApprovalFromPreview')->once()->with($posReturn->id, null, [
            'blockers' => [],
            'warnings' => [],
        ]);
        $this->app->instance(PosReturnLifecycleService::class, $lifecycle);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->post(route('pos.returns.approve', $posReturn));

        $response->assertRedirect(route('pos.returns.show', $posReturn));

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /** @test */
    public function preview_route_disables_final_approval_when_warnings_exist(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'status' => 'ready',
            'is_blocked' => false,
            'blockers' => [],
            'warnings' => [
                ['message' => 'Warning baru muncul.'],
            ],
            'info' => [],
            'groups' => [],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()
            ->assertSee('Persetujuan final dinonaktifkan')
            ->assertSee('Peringatan Preview')
            ->assertSee('Setujui Retur')
            ->assertSee('disabled', false);
    }

    /** @test */
    public function direct_approval_post_redirects_back_to_preview_when_warnings_exist(): void
    {
        $posReturn = $this->createPosReturn();

        $planner = \Mockery::mock(PosReturnApprovalPreviewPlannerService::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'blockers' => [],
            'warnings' => [
                ['message' => 'Warning baru muncul.'],
            ],
        ]);
        $this->app->instance(PosReturnApprovalPreviewPlannerService::class, $planner);

        $lifecycle = \Mockery::mock(PosReturnLifecycleService::class);
        $lifecycle->shouldNotReceive('executeApprovalFromPreview');
        $this->app->instance(PosReturnLifecycleService::class, $lifecycle);

        $this->actingAsInSetting($this->approver, $this->setting);

        $response = $this->post(route('pos.returns.approve', $posReturn));

        $response->assertRedirect(route('pos.returns.approval-preview', $posReturn));

        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    protected function createPosReturn(array $overrides = []): PosReturn
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->approver->id,
            'owner_user_id' => $this->approver->id,
            'last_saved_by' => $this->approver->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->approver->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 100000,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'source_snapshot' => [
                'header' => [
                    'transaction_code' => $transaction->code,
                    'receipt_number' => $checkout->receipt_number,
                    'customer_name' => 'Preview Customer',
                    'date' => now()->toIso8601String(),
                    'grand_total' => 100000,
                ],
                'payments' => [
                    ['method_name' => 'Tunai', 'amount' => 100000],
                ],
            ],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->approver->id,
            'updated_by' => $this->approver->id,
        ], $overrides));
    }
}
