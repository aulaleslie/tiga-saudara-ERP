## 1. Modal Rendering Stabilization

- [x] 1.1 Refactor the shared product quick-add sale-pricing section so purchase-context sale fields stay mounted and switch between active/inactive state based on `is_sold`.
- [x] 1.2 Preserve current sales-context behavior so the sale-pricing section remains visible and required when the modal is opened from sales pages.
- [x] 1.3 Ensure disabling `is_sold` still clears sale-pricing values and leaves the modal in a clean inactive state for continued use.

## 2. Regression Coverage

- [x] 2.1 Add Livewire coverage for purchase-context product quick-add rendering when `Saya Jual Barang Ini` is toggled on and off.
- [x] 2.2 Extend quick-add modal reset/save coverage to confirm sale-pricing fields do not retain stale visible values after disable or reset.
- [x] 2.3 Run the targeted product quick-add test suite and confirm both purchase and sales quick-add flows remain green.
