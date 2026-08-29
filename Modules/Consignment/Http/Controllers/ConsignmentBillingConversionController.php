<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Services\ConsignmentBillingConversionService;
use Modules\Consignment\Services\ConsignmentBillingPreviewService;
use Modules\Purchase\Entities\PaymentTerm;

class ConsignmentBillingConversionController extends Controller
{
    protected ConsignmentBillingPreviewService $previewService;
    protected ConsignmentBillingConversionService $conversionService;

    public function __construct(
        ConsignmentBillingPreviewService $previewService,
        ConsignmentBillingConversionService $conversionService
    ) {
        $this->previewService = $previewService;
        $this->conversionService = $conversionService;
    }

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.billing.access'), 403);
        $settingId = (int) session('setting_id');

        $readyConfirmations = ConsignmentBillingConfirmation::forSetting($settingId)
            ->readyForBilling()
            // Eager loads keep the row rendering free of N+1 queries.
            ->with(['supplier', 'approver'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('confirmation_number'), fn ($q) => $q->where('confirmation_number', 'like', '%' . trim($request->input('confirmation_number')) . '%'))
            ->when($request->filled('supplier_invoice_number'), fn ($q) => $q->where('supplier_invoice_number', 'like', '%' . trim($request->input('supplier_invoice_number')) . '%'))
            ->when($request->filled('approved_from'), fn ($q) => $q->whereDate('approved_at', '>=', $request->input('approved_from')))
            ->when($request->filled('approved_to'), fn ($q) => $q->whereDate('approved_at', '<=', $request->input('approved_to')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // Supplier filter is an AJAX Select2: resolve only the selected label.
        $selectedSupplierText = \Modules\People\Entities\Supplier::whereKey($request->integer('supplier_id'))->value('supplier_name');

        return view('consignment::billing.index', compact('readyConfirmations', 'selectedSupplierText'));
    }

    public function create(int $id)
    {
        abort_if(Gate::denies('consignments.billing.convert'), 403);
        $settingId = (int) session('setting_id');

        /** @var ConsignmentBillingConfirmation $confirmation */
        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)
            ->with(['supplier', 'lines.receiptAllocations.receivingDetail.product', 'paymentTerm'])
            ->findOrFail($id);

        if (!$confirmation->isApproved() || !$confirmation->is_ready_for_billing || $confirmation->isBilled()) {
            toast('Konfirmasi tagihan tidak dapat diproses (belum disetujui atau sudah ditagih).', 'warning');
            return redirect()->route('consignments.confirmations.show', $confirmation->id);
        }

        $paymentTerms = PaymentTerm::where('is_active', true)->get();

        return view('consignment::billing.create', compact('confirmation', 'paymentTerms'));
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        abort_if(Gate::denies('consignments.billing.convert'), 403);
        $settingId = (int) session('setting_id');

        $validated = $request->validate([
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'reporting_date' => 'nullable|date',
            'due_date' => 'required_without:payment_term_id|nullable|date',
            'payment_term_id' => [
                'required_without:due_date',
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'tax_ref_no' => 'nullable|string|max:100',
            'billing_notes' => 'nullable|string',
        ]);

        $result = $this->previewService->generatePreview($id, $settingId, $validated);

        return response()->json($result);
    }

    public function convert(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.billing.convert'), 403);
        $settingId = (int) session('setting_id');
        $userId = (int) auth()->id();

        $validated = $request->validate([
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'reporting_date' => 'nullable|date',
            'due_date' => 'required_without:payment_term_id|nullable|date',
            'payment_term_id' => [
                'required_without:due_date',
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'tax_ref_no' => 'nullable|string|max:100',
            'billing_notes' => 'nullable|string',
            'attachments.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        try {
            $attachments = $request->file('attachments') ?? [];
            $purchase = $this->conversionService->convert($id, $settingId, $userId, $validated, $attachments);

            toast("Konversi tagihan konsinyasi berhasil! Purchase #{$purchase->reference} telah dibuat.", 'success');
            return redirect()->route('purchases.show', $purchase->id);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('Conversion failed: ' . $e->getMessage(), ['exception' => $e]);

            // Domain rejections carry operator-actionable reasons and are safe to surface.
            // Anything else (database/infrastructure faults) is logged above but reported
            // generically so internal details are not disclosed. Failure auditing is handled
            // automatically at the service boundary.
            $isDomainRejection = $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
            $userMessage = $isDomainRejection
                ? $e->getMessage()
                : 'Terjadi kesalahan sistem saat konversi tagihan. Silakan hubungi administrator.';

            toast("Gagal melakukan konversi tagihan: " . $userMessage, 'error');
            return redirect()->back()->withInput();
        }
    }
}
