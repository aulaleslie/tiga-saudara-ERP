<?php

namespace Modules\Consignment\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3Access;
use Modules\Consignment\Services\ConsignmentTransferProvenanceService;
use RuntimeException;

/**
 * Consignment receipt-lot confirmation inside the existing V3 transfer approval workspace.
 * Same authority as allocation (transfer approval); never touches stock.
 */
class ConsignmentTransferLotController extends Controller
{
    public function confirm(Request $request, Transfer $transfer, ConsignmentTransferProvenanceService $provenance): RedirectResponse
    {
        TransferV3Access::authorize(TransferV3Access::APPROVAL);
        TransferV3Access::assertV3($transfer);

        $validated = $request->validate([
            'lots' => ['required', 'array'],
            'lots.*' => ['array'],
            'lots.*.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rows = [];
        foreach ($validated['lots'] as $rowId => $lots) {
            $rows[(int) $rowId] = array_filter(array_map(fn ($q) => (string) ($q ?? '0'), $lots), fn ($q) => (float) $q > 0);
        }

        try {
            $provenance->confirmSelection($transfer, $rows, auth()->user());
        } catch (RuntimeException $e) {
            toast($e->getMessage(), 'error');

            return redirect()->route('transfers.v3.approval', $transfer->id)->withInput();
        }

        toast('Lot penerimaan konsinyasi dikonfirmasi. Stok belum dikirim.', 'success');

        return redirect()->route('transfers.v3.approval', $transfer->id);
    }
}
