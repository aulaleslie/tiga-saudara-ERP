## 1. Livewire Component Update

- [x] 1.1 Add `updatedPerPage` lifecycle hook to `App\Livewire\Purchase\PurchaseTable` that calls `$this->resetPage()` to prevent out-of-bounds errors on resize.

## 2. Blade View Update

- [x] 2.1 Add a `<select wire:model.live="perPage">` element to `resources/views/livewire/purchase/purchase-table.blade.php`.
- [x] 2.2 Place the select element in the pagination footer next to the "Menampilkan … data" count and style it with Bootstrap forms (e.g., `form-select`, `form-select-sm`).
- [x] 2.3 Populate the select element with standard data table page size options: 10, 25, 50, and 100.
