<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Spatie\Tags\Tag;

use App\Services\Reports\SaleReportFilterData;
use App\Services\Reports\SaleReportQueryService;
use App\Services\Reports\SaleReportSnapshotService;
use App\Services\Reports\SaleReportValidator;
use Illuminate\Validation\ValidationException;

class SaleReport extends Component
{
    use WithPagination;

    private const REPORT_MODE_SESSION_KEY = 'sale_report.report_mode';
    private const REPORT_MODES = ['detail', 'header'];

    public $startDate, $endDate;
    public $sortField = 'date';
    public $sortDirection = 'desc';
    public $customerIds = [];
    public $tagIds = [];
    public $documentStatuses = [];
    public $paymentStatuses = [];
    public $periodPreset = '';
    public $dateBasis = 'date';
    public $reportMode = 'detail';
    public $filterTriggered = false;
    public $isGlobal = false;
    public $settingId;

    public $appliedFilters = [];

    // Searchable filter states
    public $customerSearch = '';
    public $customerOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills (id => name)
    public $customerLabels = [];
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

    public function selectCustomer(int $id, string $name): void
    {
        if (!in_array($id, $this->customerIds)) {
            $this->customerIds[] = $id;
            $this->customerLabels[$id] = $name;
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
    }

    public function removeCustomer(int $id): void
    {
        $this->customerIds = array_values(array_diff($this->customerIds, [$id]));
        unset($this->customerLabels[$id]);
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

    public function updatedCustomerSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->customerOptions = [];
            return;
        }

        $this->customerOptions = Customer::query()
            ->whereRaw('LOWER(customer_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)
            ->get(['id', 'customer_name'])
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
            $this->customerIds = $this->appliedFilters['customerIds'] ?? [];
            $this->customerLabels = $this->appliedFilters['customerLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->documentStatuses = $this->appliedFilters['documentStatuses'] ?? [];
            $this->paymentStatuses = $this->appliedFilters['paymentStatuses'] ?? [];
            $this->dateBasis = $this->appliedFilters['dateBasis'] ?? 'date';
            $this->reportMode = $this->normalizeReportMode($this->appliedFilters['reportMode'] ?? 'detail');
            $this->persistReportMode();
        }
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->customerIds = [];
        $this->customerLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->documentStatuses = [];
        $this->paymentStatuses = [];
        $this->dateBasis = 'date';
        $this->reportMode = 'detail';
        $this->persistReportMode();
        $this->normalizeSortForMode($this->reportMode);
        $this->customerSearch = '';
        $this->customerOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function applyFilters(
        SaleReportValidator $validator,
        SaleReportQueryService $queryService,
        SaleReportSnapshotService $snapshotService
    ): void {
        $filterArray = $this->exportFilters();
        $filterArray['scopeSettingId'] = $this->settingId;

        try {
            $validated = $validator->validate($filterArray);
            $filterData = SaleReportFilterData::fromArray($validated);
            $this->normalizeSortForMode($filterData->reportMode);

            $query = $queryService->build($filterData);
            $count = $query->count();

            $snapshotService->createSnapshot($filterData, $count);

            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'customerLabels' => $this->customerLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            $this->filterTriggered = true;
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(SaleReportSnapshotService $snapshotService, SaleReportQueryService $queryService)
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = SaleReportFilterData::fromArray($this->appliedFilters);
        if (!$snapshotService->isValidForExport($filterData)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return null;
        }

        $query = $queryService->build($filterData);
        $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

        $fileName = sprintf(
            'sales_list_%s_%s.xlsx',
            date('d-m-Y', strtotime($filterData->startDate)),
            date('d-m-Y', strtotime($filterData->endDate))
        );

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SaleReportExport($query, $filterData, false),
            $fileName
        );
    }

    public function exportCsv(SaleReportSnapshotService $snapshotService, SaleReportQueryService $queryService)
    {
        if (!$this->filterTriggered || empty($this->appliedFilters)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan filter data terlebih dahulu sebelum melakukan ekspor.']);
            return null;
        }

        $filterData = SaleReportFilterData::fromArray($this->appliedFilters);
        if (!$snapshotService->isValidForExport($filterData)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Filter telah diubah. Silakan klik tombol Filter kembali sebelum mengekspor.']);
            return null;
        }

        $query = $queryService->build($filterData);
        $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

        $fileName = sprintf(
            'sales_list_%s_%s.csv',
            date('d-m-Y', strtotime($filterData->startDate)),
            date('d-m-Y', strtotime($filterData->endDate))
        );

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SaleReportExport($query, $filterData, true),
            $fileName,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportPdf(SaleReportSnapshotService $snapshotService)
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
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'documentStatuses' => $this->documentStatuses,
            'paymentStatuses' => $this->paymentStatuses,
            'isGlobal' => $this->isGlobal,
            'dateBasis' => $this->dateBasis,
            'reportMode' => $this->normalizeReportMode($this->reportMode),
        ];
    }

    public function render(SaleReportQueryService $queryService)
    {
        $saleDetails = collect();
        $tableReportMode = $this->filterTriggered
            ? $this->normalizeReportMode($this->appliedFilters['reportMode'] ?? 'detail')
            : $this->reportMode;

        if ($this->filterTriggered) {
            $filterData = SaleReportFilterData::fromArray($this->appliedFilters);
            $query = $queryService->build($filterData);

            $queryService->applySort($query, $this->sortField, $this->sortDirection, $filterData->reportMode);

            $saleDetails = $query->paginate(15);
        }

        $documentStatusLabels = [
            Sale::STATUS_DRAFTED            => 'Draf',
            Sale::STATUS_WAITING_APPROVAL   => 'Menunggu Persetujuan',
            Sale::STATUS_APPROVED           => 'Disetujui',
            Sale::STATUS_REJECTED           => 'Ditolak',
            Sale::STATUS_DISPATCHED_PARTIALLY => 'Dikirim Sebagian',
            Sale::STATUS_DISPATCHED         => 'Terkirim',
            Sale::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            Sale::STATUS_RETURNED           => 'Diretur',
        ];

        $paymentStatusLabels = [
            'UNPAID'  => 'Belum Dibayar',
            'PARTIAL' => 'Terbayar Sebagian',
            'PAID'    => 'Lunas',
        ];

        return view('livewire.reports.sale-report', [
            'sales'                => $saleDetails,
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
            'customer_name',
            'status',
            'payment_status',
            'total_amount',
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
