<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Purchase\Services\PurchasePrintDocumentService;

class PurchasePrintController extends Controller
{
    public function __construct(
        protected PurchasePrintDocumentService $documentService
    ) {}

    /**
     * Generate commercial PDF for a purchase (default variant: purchase).
     */
    public function pdf(Request $request, $id): Response
    {
        $rawVariant = $request->query('type') ?? $request->query('variant') ?? PurchasePrintDocumentService::VARIANT_PURCHASE;
        $variant = $this->documentService->resolveVariant($rawVariant);

        return $this->documentService->renderPdf($id, $variant);
    }

    /**
     * Generate commercial PDF for a purchase order (variant: order).
     */
    public function orderPdf(Request $request, $id): Response
    {
        $rawVariant = $request->query('type') ?? $request->query('variant') ?? PurchasePrintDocumentService::VARIANT_ORDER;
        $variant = $this->documentService->resolveVariant($rawVariant);

        return $this->documentService->renderPdf($id, $variant);
    }
}
