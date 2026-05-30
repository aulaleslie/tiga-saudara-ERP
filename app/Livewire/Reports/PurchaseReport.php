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

    public $startDate, $endDate;
    public $sortField = 'date';
    public $sortDirection = 'desc';
    public $supplierIds = [];
    public $tagIds = [];
    public $documentStatuses = [];
    public $paymentStatuses = [];
    public $periodPreset = '';
    public $dateBasis = 'transaction_date';
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

    public function sortBy($field): void
    {
        $allowedFields = [
            'date',
            'reference',
            'supplier_purchase_number',
            'supplier_name',
            'status',
            'payment_status',
            'total_amount',
            'due_date',
            'product_name',
            'product_code'
        ];

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
        $this->appliedFilters = array_merge($this->exportFilters(), ['scopeSettingId' => $this->settingId]);
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
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }

        $this->supplierOptions = Supplier::query()
            ->when(!$this->isGlobal, fn($q) => $q->where('setting_id', $this->settingId))
            ->when(!empty($this->supplierIds), fn($q) => $q->whereNotIn('id', $this->supplierIds))
            ->where('supplier_name', 'like', '%' . $value . '%')
            ->limit(10)
            ->get(['id', 'supplier_name'])
            ->toArray();
    }

    public function updatedTagSearch($value): void
    {
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }

        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->when(!empty($this->tagIds), fn($q) => $q->whereNotIn('id', $this->tagIds))
            ->where("name->$locale", 'like', '%' . $value . '%')
            ->limit(10)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->documentStatuses = $this->appliedFilters['documentStatuses'] ?? [];
            $this->paymentStatuses = $this->appliedFilters['paymentStatuses'] ?? [];
            $this->dateBasis = $this->appliedFilters['dateBasis'] ?? 'transaction_date';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->documentStatuses = [];
        $this->paymentStatuses = [];
        $this->dateBasis = 'transaction_date';
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

            $query = $queryService->build($filterData);
            $count = $query->count();

            $snapshotService->createSnapshot($filterData, $count);

            $this->appliedFilters = array_merge($validated, [
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

    public function exportExcel(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
    }

    public function exportCsv(PurchaseReportSnapshotService $snapshotService)
    {
        $this->dispatch('alert', ['type' => 'error', 'message' => 'Fitur ekspor belum tersedia untuk versi ini.']);
        return null;
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
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'documentStatuses' => $this->documentStatuses,
            'paymentStatuses' => $this->paymentStatuses,
            'isGlobal' => $this->isGlobal,
            'dateBasis' => $this->dateBasis,
        ];
    }

    public function render(PurchaseReportQueryService $queryService)
    {
        $purchaseDetails = collect();
        if ($this->filterTriggered) {
            $filterData = PurchaseReportFilterData::fromArray($this->appliedFilters);
            $query = $queryService->build($filterData);

            $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';
            match ($this->sortField) {
                'date'                     => $query->orderBy('purchases.date', $direction),
                'reference'                => $query->orderBy('purchases.reference', $direction),
                'supplier_purchase_number' => $query->orderBy('purchases.supplier_purchase_number', $direction),
                'supplier_name'            => $query->orderBy('suppliers.supplier_name', $direction),
                'status'                   => $query->orderBy('purchases.status', $direction),
                'payment_status'           => $query->orderByRaw('
                    (CASE 
                        WHEN derived_active_paid <= 0 THEN 1 
                        WHEN purchases.total_amount > 0 AND derived_active_paid >= purchases.total_amount THEN 3 
                        ELSE 2 
                    END) ' . $direction
                ),
                'total_amount'             => $query->orderBy('purchases.total_amount', $direction),
                'due_date'                 => $query->orderBy('purchases.due_date', $direction),
                'product_name'             => $query->orderBy('purchase_details.product_name', $direction),
                'product_code'             => $query->orderBy('purchase_details.product_code', $direction),
                default                    => null,
            };

            $query->orderBy('purchases.id', 'desc')->orderBy('purchase_details.id', 'asc');

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
        ]);
    }
}
