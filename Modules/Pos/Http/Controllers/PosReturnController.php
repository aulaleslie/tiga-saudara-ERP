<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;

class PosReturnController extends Controller
{
    protected $lookupService;
    protected $submissionService;
    protected $lifecycleService;
    protected $snapshotService;

    public function __construct(
        PosReturnLookupService $lookupService,
        PosReturnSubmissionService $submissionService,
        PosReturnLifecycleService $lifecycleService,
        \Modules\Pos\Services\PosReturnSnapshotService $snapshotService
    ) {
        $this->lookupService = $lookupService;
        $this->submissionService = $submissionService;
        $this->lifecycleService = $lifecycleService;
        $this->snapshotService = $snapshotService;
    }

    public function index()
    {
        return view('pos::returns.index');
    }

    public function create()
    {
        return view('pos::returns.create');
    }

    public function lookup(Request $request)
    {
        $identifier = $request->get('identifier');
        $result = $this->lookupService->lookup($identifier);
        
        if ($result) {
            $result['snapshot'] = $this->snapshotService->build($result['pos_transaction_id']);
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Transaksi tidak ditemukan atau belum diposting.'
        ], 404);
    }

    public function store(Request $request)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.create'), 403);
        
        $posReturn = $this->submissionService->store($request->all());
        
        toast('Retur POS berhasil disimpan.', 'success');
        
        return redirect()->route('pos.returns.show', $posReturn->id);
    }

    public function show(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.view'), 403);
        
        $return->load([
            'lines.product',
            'lines.returnedSerial',
            'lines.replacementSerial',
            'lines.saleReturn',
            'lines.saleReturnDetail.saleReturn',
            'posTransaction',
            'posCheckout',
            'approvedBy',
            'rejectedBy',
            'receivedBy',
            'settledBy',
            'saleReturns.location',
            'saleReturns.saleReturnDetails.product',
        ]);

        $detailView = $this->buildReadonlyDetailView($return);
        
        return view('pos::returns.show', compact('return', 'detailView'));
    }

    public function edit(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if (!$return->isDraftEditable()) {
            abort(403, 'Hanya retur draft yang dapat diubah.');
        }
        
        return view('pos::returns.edit', compact('return'));
    }

    public function update(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if (!$return->isDraftEditable()) {
            abort(403, 'Hanya retur draft yang dapat diubah.');
        }
        
        // Update logic will be implemented in submission service or here
        // For now, redirect to index or back
        return redirect()->route('pos.returns.index');
    }

    public function destroy(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);
        
        if ($return->isHardDeletable()) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($return) {
                $return->lines()->forceDelete();
                $return->forceDelete();
            });
            toast('Retur POS draft berhasil dihapus permanen.', 'success');
        } else {
            abort(403, 'Hanya retur draft yang dapat dihapus permanen.');
        }
        
        return redirect()->route('pos.returns.index');
    }

    public function submitDraft(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.approve'), 403);

        try {
            $this->submissionService->submitDraftForApproval($return);
            toast('Retur POS draft berhasil diajukan untuk persetujuan.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return redirect()->route('pos.returns.index');
    }

    public function approve(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.approve'), 403);

        $data = $request->validate([
            'return_option' => ['required', 'string', 'in:cash_return,product_replacement'],
        ]);

        try {
            $this->lifecycleService->approve($return->id, $data['return_option']);
            toast('Retur POS berhasil disetujui.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function reject(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.approve'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->reject($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil ditolak.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function receive(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.receive'), 403);

        try {
            $this->lifecycleService->receive($return->id);
            toast('Retur POS berhasil diterima.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function settle(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.settle'), 403);

        if ($return->return_option !== PosReturn::OPTION_CASH_RETURN) {
            toast('Penyelesaian tunai hanya tersedia untuk retur dengan opsi kembali uang.', 'error');
            return back();
        }

        try {
            $this->lifecycleService->settlePaymentReturn($return->id);
            toast('Retur POS berhasil diselesaikan dengan pengembalian tunai.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function dispatch(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.dispatch'), 403);

        if ($return->return_option !== PosReturn::OPTION_PRODUCT_REPLACEMENT) {
            toast('Pengiriman pengganti hanya tersedia untuk retur dengan opsi ganti produk.', 'error');
            return back();
        }

        try {
            $this->lifecycleService->dispatchReplacement($return->id);
            toast('Pengiriman pengganti berhasil diproses.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function archive(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->archive($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil diarsipkan.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function cancel(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->cancel($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil dibatalkan.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    protected function buildReadonlyDetailView(PosReturn $return): array
    {
        $snapshot = is_array($return->source_snapshot) ? $return->source_snapshot : [];
        $snapshotLines = collect($snapshot['lines'] ?? [])->filter(fn ($line) => is_array($line))->values();
        $snapshotLookup = $snapshotLines->mapWithKeys(fn (array $line) => [$this->buildSnapshotLineKey($line) => $line]);

        return [
            'transaction' => [
                'receipt_number' => $return->receipt_number ?: data_get($snapshot, 'header.receipt_number'),
                'transaction_code' => $return->transaction_code ?: data_get($snapshot, 'header.transaction_code'),
                'customer_name' => $return->customer_name ?: data_get($snapshot, 'header.customer_name') ?: 'Guest',
                'date' => data_get($snapshot, 'header.date'),
                'grand_total' => (float) (data_get($snapshot, 'header.grand_total') ?? 0),
            ],
            'payments' => collect($snapshot['payments'] ?? [])->filter(fn ($payment) => is_array($payment))->values()->all(),
            'linked_sale_returns' => $return->saleReturns
                ->map(function ($saleReturn) {
                    return [
                        'reference' => $saleReturn->reference,
                        'status' => $saleReturn->status,
                        'sale_reference' => $saleReturn->sale_reference,
                        'location_name' => optional($saleReturn->location)->name,
                    ];
                })
                ->values()
                ->all(),
            'groups' => $this->buildReadonlyGroups($return->lines, $snapshotLookup),
            'actionable_count' => $return->lines->count(),
            'total_cash_return' => (float) $return->lines->sum(fn (PosReturnLine $line) => (float) ($line->expected_cash_amount ?? 0)),
            'audit' => [
                'snapshot_hash' => $return->source_snapshot_hash,
                'receipt_number' => $return->receipt_number,
                'transaction_code' => $return->transaction_code,
                'pos_checkout_id' => $return->pos_checkout_id,
                'pos_transaction_id' => $return->pos_transaction_id,
                'source_setting_ids' => $return->lines->pluck('source_setting_id')->filter()->unique()->values()->all(),
                'source_location_ids' => $return->lines->pluck('source_location_id')->filter()->unique()->values()->all(),
                'sale_ids' => $return->lines->pluck('sale_id')->filter()->unique()->values()->all(),
                'sale_detail_ids' => $return->lines->pluck('sale_detail_id')->filter()->unique()->values()->all(),
            ],
            'snapshot_context' => [
                'available' => $snapshotLines->isNotEmpty(),
                'groups' => $this->buildSnapshotContextGroups($snapshotLines, $return->lines),
            ],
        ];
    }

    protected function buildReadonlyGroups(Collection $lines, Collection $snapshotLookup): array
    {
        $groups = [];

        foreach ($lines->sortBy([
            ['pos_transaction_line_id', 'asc'],
            ['sale_detail_id', 'asc'],
            ['id', 'asc'],
        ]) as $line) {
            $snapshotLine = $snapshotLookup->get($this->buildReturnLineSnapshotKey($line));
            $groupKey = $line->pos_transaction_line_id ?: ($line->bundle_group_key ?: $line->sale_detail_id ?: $line->id);
            $isTracked = $line->returned_serial_id !== null;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'product_name' => $line->product_name,
                    'product_code' => $line->product_code,
                    'unit_price' => (float) $line->unit_price,
                    'is_bundle' => ! empty($line->bundle_group_key)
                        || ! empty(data_get($snapshotLine, 'is_bundle'))
                        || ! empty(data_get($line->line_meta, 'bundle_trace')),
                    'is_tracked' => $isTracked,
                    'bundle_trace' => $this->buildBundleTrace($line, $snapshotLine),
                    'serial_rows' => [],
                    'non_serial_rows' => [],
                ];
            }

            $execution = $this->buildExecutionLinkage($line);
            $resolution = $this->resolutionMeta((string) $line->resolution);
            $row = [
                'resolution_label' => $resolution['label'],
                'resolution_badge' => $resolution['badge'],
                'quantity' => (float) $line->quantity,
                'cash_amount' => (float) ($line->expected_cash_amount ?? 0),
                'line_total' => (float) $line->line_total,
                'returned_serial' => optional($line->returnedSerial)->serial_number
                    ?: $this->findSnapshotSerialLabel($snapshotLine, $line->returned_serial_id),
                'replacement_serial' => optional($line->replacementSerial)->serial_number,
                'execution_label' => $execution['label'],
                'execution_meta' => $execution['meta'],
            ];

            if ($isTracked) {
                $groups[$groupKey]['serial_rows'][] = $row;
                continue;
            }

            $groups[$groupKey]['non_serial_rows'][] = $row;
        }

        return array_values($groups);
    }

    protected function buildSnapshotContextGroups(Collection $snapshotLines, Collection $returnLines): array
    {
        $returnedQuantities = $returnLines->groupBy(function (PosReturnLine $line) {
            return $this->buildReturnLineSnapshotKey($line);
        })->map(fn (Collection $group) => (float) $group->sum('quantity'));

        $groups = [];

        foreach ($snapshotLines as $snapshotLine) {
            $groupKey = $snapshotLine['pos_transaction_line_id'] ?? $snapshotLine['sale_detail_id'];

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'product_name' => $snapshotLine['product_name'] ?? '-',
                    'product_code' => $snapshotLine['product_code'] ?? '-',
                    'is_bundle' => ! empty($snapshotLine['is_bundle']),
                    'is_tracked' => ! empty($snapshotLine['is_tracked']),
                    'rows' => [],
                ];
            }

            $snapshotKey = $this->buildSnapshotLineKey($snapshotLine);
            $returnedQuantity = (float) ($returnedQuantities->get($snapshotKey) ?? 0);
            $originalQuantity = (float) ($snapshotLine['original_quantity'] ?? 0);
            $status = $this->snapshotStatusMeta($originalQuantity, $returnedQuantity, ! empty($snapshotLine['is_tracked']));

            $groups[$groupKey]['rows'][] = [
                'serial_label' => $this->findSnapshotSerialLabel($snapshotLine, data_get($snapshotLine, 'serial_number_ids.0')),
                'original_quantity' => $originalQuantity,
                'returned_quantity' => min($originalQuantity, $returnedQuantity),
                'status_label' => $status['label'],
                'status_badge' => $status['badge'],
            ];
        }

        return array_values($groups);
    }

    protected function buildBundleTrace(PosReturnLine $line, ?array $snapshotLine): array
    {
        $bundleTrace = collect(data_get($line->line_meta, 'bundle_trace', []));
        $snapshotBundleItems = collect(data_get($snapshotLine, 'bundle_items', []))->keyBy('product_id');

        if ($bundleTrace->isEmpty()) {
            return $snapshotBundleItems->map(function ($item) {
                return [
                    'product_name' => $item['product_name'] ?? $item['name'] ?? '-',
                    'product_code' => $item['product_code'] ?? null,
                    'quantity_per_bundle' => (float) ($item['quantity'] ?? $item['quantity_per_bundle'] ?? 0),
                    'total_component_quantity' => (float) ($item['quantity'] ?? $item['quantity_per_bundle'] ?? 0),
                ];
            })->values()->all();
        }

        return $bundleTrace->map(function ($trace) use ($snapshotBundleItems) {
            $bundleItem = $snapshotBundleItems->get($trace['product_id'] ?? null, []);

            return [
                'product_name' => $bundleItem['product_name'] ?? $bundleItem['name'] ?? ('Produk #' . ($trace['product_id'] ?? '-')),
                'product_code' => $bundleItem['product_code'] ?? null,
                'quantity_per_bundle' => (float) ($trace['quantity_per_bundle'] ?? 0),
                'total_component_quantity' => (float) ($trace['total_component_quantity'] ?? 0),
            ];
        })->values()->all();
    }

    protected function buildExecutionLinkage(PosReturnLine $line): array
    {
        $saleReturn = optional($line->saleReturnDetail)->saleReturn ?: $line->saleReturn;

        if (! $saleReturn) {
            return [
                'label' => 'Belum dieksekusi ke Sales Return',
                'meta' => null,
            ];
        }

        return [
            'label' => $saleReturn->reference,
            'meta' => trim(implode(' • ', array_filter([
                $saleReturn->status,
                $saleReturn->sale_reference,
                $line->sale_return_detail_id ? 'Detail #' . $line->sale_return_detail_id : null,
            ]))),
        ];
    }

    protected function buildSnapshotLineKey(array $line): string
    {
        if (! empty($line['is_tracked']) && ! empty($line['serial_number_ids'])) {
            $prefix = $line['pos_transaction_line_id'] ?? $line['sale_detail_id'];

            return $prefix . '-' . $line['serial_number_ids'][0];
        }

        return (string) ($line['sale_detail_id'] ?? '');
    }

    protected function buildReturnLineSnapshotKey(PosReturnLine $line): string
    {
        if ($line->returned_serial_id) {
            $prefix = $line->pos_transaction_line_id ?: $line->sale_detail_id;

            return $prefix . '-' . $line->returned_serial_id;
        }

        return (string) $line->sale_detail_id;
    }

    protected function findSnapshotSerialLabel(?array $snapshotLine, ?int $serialId): ?string
    {
        if (! $snapshotLine || ! $serialId) {
            return null;
        }

        foreach ($snapshotLine['serial_numbers'] ?? [] as $serialNumber) {
            if ((int) ($serialNumber['id'] ?? 0) === $serialId) {
                return $serialNumber['serial_number'] ?? null;
            }
        }

        return null;
    }

    protected function resolutionMeta(string $resolution): array
    {
        return match ($resolution) {
            PosReturnLine::RESOLUTION_CASH_RETURN => ['label' => 'Tunai', 'badge' => 'bg-success'],
            PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT => ['label' => 'Ganti Produk', 'badge' => 'bg-info text-dark'],
            default => ['label' => 'Tidak Ada Aksi', 'badge' => 'bg-secondary'],
        };
    }

    protected function snapshotStatusMeta(float $originalQuantity, float $returnedQuantity, bool $isTracked): array
    {
        if ($isTracked) {
            return $returnedQuantity > 0
                ? ['label' => 'Diretur', 'badge' => 'bg-success']
                : ['label' => 'Tidak Diretur', 'badge' => 'bg-secondary'];
        }

        if ($returnedQuantity <= 0) {
            return ['label' => 'Tidak Diretur', 'badge' => 'bg-secondary'];
        }

        if ($returnedQuantity < $originalQuantity) {
            return ['label' => 'Diretur Sebagian', 'badge' => 'bg-warning text-dark'];
        }

        return ['label' => 'Diretur', 'badge' => 'bg-success'];
    }
}
