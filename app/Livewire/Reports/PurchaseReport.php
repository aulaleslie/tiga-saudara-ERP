<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Spatie\Tags\Tag;

use App\Services\Reports\PurchaseReportFilterData;
use App\Services\Reports\PurchaseReportQueryService;
use App\Services\Reports\PurchaseReportSnapshotService;
use App\Services\Reports\PurchaseReportValidator;
use Illuminate\Validation\ValidationException;

class PurchaseReport extends Component
{
    use WithPagination;

    private const REPORT_MODE_SESSION_KEY = 'purchase_report.report_mode';
    private const REPORT_MODES = ['detail', 'header'];

    public $startDate, $endDate;
    public $sortField = 'date';
    public $sortDirection = 'desc';
    public $supplierIds = [];
    public $tagIds = [];
    public $documentStatuses = [];
    public $paymentStatuses = [];
    public $periodPreset = '';
    public $dateBasis = 'transaction_date';
    public $reportMode = 'detail';
    public $filterTriggered = false;
    public $isGlobal = false;
    public $settingId;

    public $appliedFilters = [];

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills (id => name)
    public $supplierLabels = [];
    public $tagLabels = [];

    protected $paginationTheme = 'bootstrap';
    protected $queryString = ['reportMode'];

    public function sortBy($field): void
    {
        $allowedFields = $this->supportedSortFields($this->activeSortMode());

        if (!in_array($field, $allowedFields)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function sortIcon($field): string
    {
        if ($field !== $this->sortField) return '';
        return $this->sortDirection === 'asc'
            ? '<i class="bi bi-caret-up-fill text-primary ms-1"></i>'
            : '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
    }

    public function hydrate(): void
    {
        $this->reportMode = $this->normalizeReportMode($this->reportMode);
    }

    public function selectSupplier(int $id, string $name): void
    {
        if (!in_array($id, $this->supplierIds)) {
            $this->supplierIds[] = $id;
            $this->supplierLabels[$id] = $name;
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
    }

    public function removeSupplier(int $id): void
    {
        $this->supplierIds = array_values(array_diff($this->supplierIds, [$id]));
        unset($this->supplierLabels[$id]);
    }

    public function selectTag(int $id, string $name): void
    {
        if (!in_array($id, $this->tagIds)) {
            $this->tagIds[] = $id;
            $this->tagLabels[$id] = $name;
        }
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function removeTag(int $id): void
    {
        $this->tagIds = array_values(array_diff($this->tagIds, [$id]));
        unset($this->tagLabels[$id]);
    }

    public function toggleDocumentStatus(string $status): void
    {
        if (in_array($status, $this->documentStatuses)) {
            $this->documentStatuses = array_values(array_diff($this->documentStatuses, [$status]));
        } else {
            $this->documentStatuses[] = $status;
        }
    }

    public function togglePaymentStatus(string $status): void
    {
        if (in_array($status, $this->paymentStatuses)) {
            $this->paymentStatuses = array_values(array_diff($this->paymentStatuses, [$status]));
        } else {
            $this->paymentStatuses[] = $status;
        }
    }

    public function mount($isGlobal = false): void
    {
        $this->isGlobal = $isGlobal;
        $this->settingId = session('setting_id');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->reportMode = $this->normalizeReportMode(
            request()->query('reportMode', session(self::REPORT_MODE_SESSION_KEY, $this->reportMode))
        );
        $this->persistReportMode();
        $this->normalizeSortForMode($this->reportMode);
        $this->appliedFilters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
    }

    public function updatedReportMode($value): void
    {
        $this->reportMode = $this->normalizeReportMode($value);
        $this->persistReportMode();
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => [$this->startDate, $this->endDate] = [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'this_week'  => [$this->startDate, $this->endDate] = [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
            'this_month' => [$this->startDate, $this->endDate] = [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
            'this_year'  => [$this->startDate, $this->endDate] = [now()->startOfYear()->format('Y-m-d'), now()->endOfYear()->format('Y-m-d')],
            default      => null,
        };
    }

    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }

        $this->supplierOptions = Supplier::query()
            ->whereRaw('LOWER(supplier_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'supplier_name'])
            ->toArray();
    }

    public function updatedTagSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }

        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->where(function ($query) use ($value, $locale) {
                $query->containing($value, $locale)
                      ->orWhere(fn($q) => $q->containing($value, 'en'));
            })
            ->limit(10)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->documentStatuses = $this->appliedFilters['documentStatuses'] ?? [];
            $this->paymentStatuses = $this->appliedFilters['paymentStatuses'] ?? [];
            $this->dateBasis = $this->appliedFilters['dateBasis'] ?? 'transaction_date';
            $this->reportMode = $this->normalizeReportMode($this->appliedFilters['reportMode'] ?? 'detail');
            $this->persistReportMode();
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->documentStatuses = [];
        $this->paymentStatuses = [];
        $this->dateBasis = 'transaction_date';
        $this->reportMode = 'detail';
        $this->persistReportMode();
        $this->normalizeSortForMode($this->reportMode);
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function applyFilters(
        PurchaseReportValidator $validator,
        PurchaseReportQueryService $queryService,
        PurchaseReportSnapshotService $snapshotService
    ): void {
        $filterArray = $this->exportFilters();
        $filterArray['scopeSettingId'] = $this->settingId;

        try {
            $validated = $validator->validate($filterArray);
            $filterData = PurchaseReportFilterData::fromArray($validated);
            $this->normalizeSortForMode($filterData->reportMode);

            $query = $queryService->build($filterData);
            $count = $query->count();

            $snapshotService->createSnapshot($filterData, $count);

            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            $this->filterTriggered = true;
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(PurchaseReportSnapshotService $snapshotService, PurchaseReportQueryService $queryService)
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = PurchaseReportFilterData::fromArray($this->appliedFilters);
        if (!$snapshotService->isValidForExport($filterData)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return null;
        }

        $query = $queryService->build($filterData);
        $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

        $fileName = sprintf(
            'purchases_list_%s_%s.xlsx',
            date('d-m-Y', strtotime($filterData->startDate)),
            date('d-m-Y', strtotime($filterData->endDate))
        );

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PurchaseReportExport($query, $filterData, false),
            $fileName
        );
    }

    public function exportCsv(PurchaseReportSnapshotService $snapshotService, PurchaseReportQueryService $queryService)
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = PurchaseReportFilterData::fromArray($this->appliedFilters);
        if (!$snapshotService->isValidForExport($filterData)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return null;
        }

        $query = $queryService->build($filterData);
        $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

        $fileName = sprintf(
            'purchases_list_%s_%s.csv',
            date('d-m-Y', strtotime($filterData->startDate)),
            date('d-m-Y', strtotime($filterData->endDate))
        );

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PurchaseReportExport($query, $filterData, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportPdf(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'documentStatuses' => $this->documentStatuses,
            'paymentStatuses' => $this->paymentStatuses,
            'isGlobal' => $this->isGlobal,
            'dateBasis' => $this->dateBasis,
            'reportMode' => $this->normalizeReportMode($this->reportMode),
        ];
    }

    public function render(PurchaseReportQueryService $queryService)
    {
        $purchaseDetails = collect();
        $tableReportMode = $this->filterTriggered
            ? $this->normalizeReportMode($this->appliedFilters['reportMode'] ?? 'detail')
            : $this->reportMode;

        if ($this->filterTriggered) {
            $filterData = PurchaseReportFilterData::fromArray($this->appliedFilters);
            $query = $queryService->build($filterData);

            $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

            $purchaseDetails = $query->paginate(15);
        }

        $documentStatusLabels = [
            Purchase::STATUS_DRAFTED            => 'Draf',
            Purchase::STATUS_WAITING_APPROVAL   => 'Menunggu Persetujuan',
            Purchase::STATUS_APPROVED           => 'Disetujui',
            Purchase::STATUS_REJECTED           => 'Ditolak',
            Purchase::STATUS_RECEIVED_PARTIALLY => 'Diterima Sebagian',
            Purchase::STATUS_RECEIVED           => 'Diterima',
            Purchase::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            Purchase::STATUS_RETURNED           => 'Diretur',
        ];

        $paymentStatusLabels = [
            'UNPAID'  => 'Belum Dibayar',
            'PARTIAL' => 'Terbayar Sebagian',
            'PAID'    => 'Lunas',
        ];

        return view('livewire.reports.purchase-report', [
            'purchases'            => $purchaseDetails,
            'documentStatusLabels' => $documentStatusLabels,
            'paymentStatusLabels'  => $paymentStatusLabels,
            'isGlobal'             => $this->isGlobal,
            'tableReportMode'      => $tableReportMode,
        ]);
    }

    private function activeSortMode(): string
    {
        if (!$this->filterTriggered) {
            return $this->reportMode;
        }

        return $this->normalizeReportMode($this->appliedFilters['reportMode'] ?? $this->reportMode);
    }

    private function normalizeReportMode(mixed $reportMode): string
    {
        return in_array($reportMode, self::REPORT_MODES, true) ? $reportMode : 'detail';
    }

    private function normalizeSortForMode(string $reportMode): void
    {
        if (in_array($this->sortField, $this->supportedSortFields($reportMode), true)) {
            return;
        }

        $this->sortField = 'date';
        $this->sortDirection = 'desc';
    }

    private function persistReportMode(): void
    {
        session([self::REPORT_MODE_SESSION_KEY => $this->reportMode]);
    }

    private function supportedSortFields(string $reportMode): array
    {
        $baseFields = [
            'date',
            'reference',
            'supplier_purchase_number',
            'supplier_name',
            'status',
            'payment_status',
            'total_amount',
            'due_date',
        ];

        if ($reportMode === 'header') {
            return $baseFields;
        }

        return array_merge($baseFields, [
            'product_name',
            'product_code',
        ]);
    }
}
