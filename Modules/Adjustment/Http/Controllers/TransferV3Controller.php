<?php

namespace Modules\Adjustment\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3Access;
use Modules\Adjustment\Services\TransferV3AllocationService;
use Modules\Adjustment\Services\TransferV3CancellationExecutor;
use Modules\Adjustment\Services\TransferV3DispatchExecutor;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Services\TransferV3ReceiptExecutor;
use RuntimeException;
use Throwable;

/**
 * HTTP boundary for Stock Transfer workflow version 3. Every action checks
 * its own permission in the active business and the document version
 * before touching a service; services recheck status and revisions under
 * lock.
 */
class TransferV3Controller extends Controller
{
    public const RECEIPT_CONFIRMATION = 'Pastikan seluruh barang telah dihitung dan jumlahnya sesuai dengan daftar pada dokumen ini. Dengan mengonfirmasi, Anda menyatakan seluruh barang telah diterima lengkap.';

    public const CANCELLATION_CONFIRMATION = 'Pastikan seluruh barang belum diserahkan atau telah dikembalikan ke lokasi asal sebelum membatalkan pengiriman. Stok akan dikembalikan ke lokasi asal sesuai pengiriman.';

    public function approvalWorkspace(Request $request, Transfer $transfer, TransferV3AllocationService $allocations)
    {
        TransferV3Access::authorize(TransferV3Access::APPROVAL);
        TransferV3Access::assertV3($transfer);

        if ($transfer->status !== Transfer::STATUS_PENDING) {
            toast('Alokasi hanya tersedia untuk transfer yang menunggu persetujuan.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $workspace = $allocations->workspace($transfer, TransferV3Access::canViewBucketDiagnostics());
        $summary = $request->boolean('review') ? $allocations->summary($transfer) : null;

        return view('adjustment::transfers.v3.approval', [
            'transfer'     => $transfer,
            'workspace'    => $workspace,
            'summary'      => $summary,
            'operationKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * Simpan Progres (redirects to detail) or Tinjau & Setujui (saves, then
     * reopens the workspace with the server-derived summary modal).
     */
    public function saveProgress(Request $request, Transfer $transfer, TransferV3AllocationService $allocations): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::APPROVAL);
        TransferV3Access::assertV3($transfer);

        $validated = $request->validate([
            'request_revision_id'    => ['required', 'integer'],
            'configuration_revision' => ['required', 'integer', 'min:0'],
            'serial_destinations'    => ['nullable', 'array'],
            'rows'                   => ['nullable', 'array'],
            'rows.*.product_id'      => ['required', 'integer'],
            'rows.*.source_location_id' => ['nullable', 'integer'],
            'rows.*.destination_location_id' => ['nullable', 'integer'],
            'rows.*.quantity'        => ['nullable'],
            'intent'                 => ['nullable', 'in:save,review'],
        ]);

        try {
            $allocations->saveProgress(
                $transfer,
                auth()->user(),
                $this->activeSettingId(),
                (int) $validated['request_revision_id'],
                (int) $validated['configuration_revision'],
                $validated['serial_destinations'] ?? [],
                array_values($validated['rows'] ?? [])
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            toast($e->getMessage(), 'error');

            return redirect()->route('transfers.v3.approval', $transfer->id)->withInput();
        }

        if (($validated['intent'] ?? 'save') === 'review') {
            return redirect()->route('transfers.v3.approval', ['transfer' => $transfer->id, 'review' => 1]);
        }

        toast('Progres alokasi disimpan. Stok belum dicadangkan.', 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Konfirmasi on the summary modal: approves and dispatches only the
     * exact reviewed request/configuration revisions.
     */
    public function approve(Request $request, Transfer $transfer, TransferV3DispatchExecutor $executor): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::APPROVAL);
        TransferV3Access::assertV3($transfer);

        $validated = $request->validate([
            'request_revision_id'    => ['required', 'integer'],
            'configuration_revision' => ['required', 'integer'],
            'operation_key'          => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $executor->approveAndDispatch(
                $transfer,
                auth()->user(),
                $this->activeSettingId(),
                (int) $validated['request_revision_id'],
                (int) $validated['configuration_revision'],
                $validated['operation_key'] ?? null
            );
        } catch (Throwable $e) {
            $this->logFailure('approve', $transfer, $e);
            toast($this->message($e), 'error');

            return redirect()->route('transfers.v3.approval', $transfer->id);
        }

        toast('Transfer disetujui dan dikirim. No. Dokumen: ' . $transfer->document_number, 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    public function reject(Request $request, Transfer $transfer, TransferV3GoodsService $goods): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::APPROVAL);
        TransferV3Access::assertV3($transfer);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']], [
            'reason.required' => 'Alasan penolakan harus diisi.',
        ]);

        return $this->run('reject', $transfer, fn () => $goods->reject($transfer, $validated['reason'], auth()->user(), $this->activeSettingId()), 'Transfer ditolak.');
    }

    public function acknowledgeRejection(Transfer $transfer, TransferV3GoodsService $goods): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::EDIT);
        TransferV3Access::assertV3($transfer);

        return $this->run('acknowledge', $transfer, fn () => $goods->acknowledgeRejection($transfer, auth()->user(), $this->activeSettingId()), 'Transfer dikembalikan ke Draf untuk direvisi.');
    }

    /**
     * Ajukan Persetujuan from the list or detail for a persisted draft.
     */
    public function submit(Transfer $transfer, TransferV3GoodsService $goods): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::EDIT);
        TransferV3Access::assertV3($transfer);

        return $this->run('submit', $transfer, fn () => $goods->submitPersisted($transfer, auth()->user(), $this->activeSettingId()), 'Transfer diajukan untuk persetujuan.');
    }

    /**
     * Terima Barang confirmation. Accepts no quantities, locations, serials
     * or tax values: any such override is rejected outright.
     */
    public function receive(Request $request, Transfer $transfer, TransferV3ReceiptExecutor $executor): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::RECEIVE);
        TransferV3Access::assertV3($transfer);

        $this->rejectOverrides($request, ['confirm', 'operation_key']);
        $request->validate([
            'confirm'       => ['accepted'],
            'operation_key' => ['nullable', 'string', 'max:64'],
        ], ['confirm.accepted' => 'Konfirmasi penerimaan diperlukan.']);

        return $this->run('receive', $transfer, fn () => $executor->receive($transfer, auth()->user(), $this->activeSettingId(), $request->input('operation_key')), 'Seluruh barang telah diterima. Transfer selesai.');
    }

    public function cancelDispatch(Request $request, Transfer $transfer, TransferV3CancellationExecutor $executor): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::CANCEL_DISPATCH);
        TransferV3Access::assertV3($transfer);

        $this->rejectOverrides($request, ['confirm', 'reason', 'operation_key']);
        $validated = $request->validate([
            'confirm'       => ['accepted'],
            'reason'        => ['required', 'string', 'max:255'],
            'operation_key' => ['nullable', 'string', 'max:64'],
        ], [
            'confirm.accepted' => 'Konfirmasi pembatalan pengiriman diperlukan.',
            'reason.required'  => 'Alasan pembatalan pengiriman harus diisi.',
        ]);

        return $this->run('cancel-dispatch', $transfer, fn () => $executor->cancel($transfer, auth()->user(), $this->activeSettingId(), $validated['reason'], $validated['operation_key'] ?? null), 'Pengiriman dibatalkan dan stok dikembalikan ke lokasi asal.');
    }

    private function rejectOverrides(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->except(['_token', '_method'])), $allowed);

        if ($unexpected !== []) {
            abort(422, 'Permintaan berisi data yang tidak diperbolehkan.');
        }
    }

    private function run(string $action, Transfer $transfer, callable $callback, string $success): RedirectResponse
    {
        try {
            $callback();
            toast($success, 'success');
        } catch (Throwable $e) {
            $this->logFailure($action, $transfer, $e);
            toast($this->message($e), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    private function message(Throwable $e): string
    {
        return $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Terjadi kesalahan saat memproses transfer stok.';
    }

    private function logFailure(string $action, Transfer $transfer, Throwable $e): void
    {
        Log::warning('Stock transfer v3 action failed', [
            'action'      => $action,
            'transfer_id' => $transfer->id,
            'user_id'     => auth()->id(),
            'error'       => $e->getMessage(),
        ]);
    }

    private function activeSettingId(): int
    {
        return (int) session('setting_id');
    }
}
