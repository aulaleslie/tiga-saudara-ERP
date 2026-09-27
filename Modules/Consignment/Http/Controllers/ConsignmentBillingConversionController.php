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
        $isPkp = (bool) ($confirmation->setting->is_pkp ?? false);
        $activeTaxes = $isPkp ? \Modules\Setting\Entities\Tax::active()->get() : collect();

        return view('consignment::billing.create', compact('confirmation', 'paymentTerms', 'isPkp', 'activeTaxes'));
    }

    private function pricingIntentRules(bool $isPkp): array
    {
        $rules = [
            'is_tax_included' => 'nullable|boolean',
            'global_discount_type' => 'nullable|string|in:fixed,percentage',
            'global_discount_value' => 'nullable|numeric|min:0',
            'rows' => 'nullable|array',
            // purchase_details.unit_price/price are DECIMAL(15,6): 9 integer digits
            // before the point, so the largest representable value is
            // 999999999.999999. Reject anything beyond that range up front rather
            // than letting it overflow or silently truncate at persistence.
            'rows.*.unit_price' => 'nullable|numeric|min:0|max:999999999.999999',
            'rows.*.discount_type' => 'nullable|string|in:fixed,percentage',
            'rows.*.discount_value' => 'nullable|numeric|min:0',
            // row_total_override lands in purchase_details.sub_total, which is
            // DECIMAL(15,2) -- 13 integer digits before the point -- a much wider
            // range than the unit-price column. Using the unit-price limit here
            // would reject valid large row totals well within sub_total's own range.
            'rows.*.row_total_override' => 'nullable|numeric|min:0|max:9999999999999.99',
        ];

        $rules['rows.*.tax_id'] = $isPkp
            ? [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('taxes', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ]
            : 'prohibited';

        return $rules;
    }

    private function extractPricingIntent(Request $request): array
    {
        return [
            'is_tax_included' => $request->boolean('is_tax_included', true),
            'global_discount_type' => $request->input('global_discount_type', 'fixed'),
            'global_discount_value' => (float) $request->input('global_discount_value', 0),
            'rows' => (array) $request->input('rows', []),
        ];
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        abort_if(Gate::denies('consignments.billing.convert'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::where('id', $id)
            ->where('setting_id', $settingId)
            ->with('setting')
            ->first();
        $isPkp = (bool) ($confirmation->setting->is_pkp ?? false);

        // The preview must render billing rows and recalculated totals immediately,
        // even before any invoice metadata is filled in, so nothing here is required.
        // Completeness (invoice number/date, due date or payment term) is enforced
        // only at final conversion; the preview service still reports missing/invalid
        // metadata as non-fatal blockers in the response.
        $validated = $request->validate([
            'supplier_invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'reporting_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'payment_term_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'tax_ref_no' => 'nullable|string|max:100',
            'billing_notes' => 'nullable|string',
        ] + $this->pricingIntentRules($isPkp));

        $pricingIntent = $this->extractPricingIntent($request);

        $result = $this->previewService->generatePreview($id, $settingId, $validated, $pricingIntent);

        return response()->json($result);
    }

    public function convert(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.billing.convert'), 403);
        $settingId = (int) session('setting_id');
        $userId = (int) auth()->id();

        $confirmation = ConsignmentBillingConfirmation::where('id', $id)
            ->where('setting_id', $settingId)
            ->with('setting')
            ->first();
        $isPkp = (bool) ($confirmation->setting->is_pkp ?? false);

        // At least one of due date or payment term is still required to finalize
        // conversion (unlike the preview, which tolerates both being blank). This is
        // checked explicitly, ahead of the framework validator, so a clear HTTP 400
        // is returned rather than a generic 422 validation-failure response.
        if (empty($request->input('due_date')) && empty($request->input('payment_term_id'))) {
            abort(400, 'At least one of due date or payment term is required to convert this confirmation.');
        }

        $validated = $request->validate([
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'reporting_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'payment_term_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'tax_ref_no' => 'nullable|string|max:100',
            'billing_notes' => 'nullable|string',
            'attachments.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ] + $this->pricingIntentRules($isPkp));

        $pricingIntent = $this->extractPricingIntent($request);

        try {
            $attachments = $request->file('attachments') ?? [];
            $purchase = $this->conversionService->convert($id, $settingId, $userId, $validated, $attachments, $pricingIntent);

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
