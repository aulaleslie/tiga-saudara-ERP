<?php

namespace Modules\Product\Http\Controllers;

use App\Services\EffectiveDocumentBusinessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Http\Requests\BatchPrintBarcodeRequest;
use Modules\Product\Services\BarcodeBatchService;

class BarcodeController extends Controller
{

    public function printBarcode() {
        abort_if(Gate::denies('barcodes.print'), 403);

        return view('product::barcode.index');
    }

    /**
     * Render the standalone browser print document for a validated label batch.
     */
    public function batchPrint(
        BatchPrintBarcodeRequest $request,
        EffectiveDocumentBusinessResolver $resolver,
        BarcodeBatchService $service
    ) {
        $settingId = $request->input('setting_id');

        try {
            $resolved = $resolver->resolve($settingId !== null ? (int) $settingId : null);
        } catch (AuthorizationException $e) {
            abort(403, 'Perusahaan yang dipilih tidak dapat diakses.');
        }

        $result = $service->expand($request->input('items'), $resolved['setting_id']);

        if ($result['errors'] !== []) {
            return back()->withErrors($result['errors']);
        }

        return view('product::barcode.batch-print', [
            'labels' => $result['labels'],
        ]);
    }

    /**
     * Diagnostic label sheet for physical printer acceptance (tasks 5.1-5.3).
     *
     * Produces uniquely identifiable TEST 001..TEST NNN labels with a sample
     * Code 128 barcode, a border on the 2mm safe-area boundary, and top/bottom
     * alignment markers. Isolated from product barcode printing and disabled in
     * production.
     */
    public function diagnosticPrint(\Illuminate\Http\Request $request, BarcodeBatchService $service)
    {
        abort_if(Gate::denies('barcodes.print'), 403);
        abort_if(app()->environment('production'), 404);

        $count = (int) $request->query('count', 100);
        $count = max(1, min(BarcodeBatchService::MAX_TOTAL_LABELS, $count));

        $labels = [];

        for ($i = 1; $i <= $count; $i++) {
            $sequence = 'TEST ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $svg = $service->renderSvg($sequence, 'C128');

            if ($svg === null) {
                continue;
            }

            $labels[] = [
                'sequence' => $sequence,
                'barcode' => $sequence,
                'svg' => $svg,
            ];
        }

        return view('product::barcode.diagnostic-print', [
            'labels' => $labels,
        ]);
    }

}
