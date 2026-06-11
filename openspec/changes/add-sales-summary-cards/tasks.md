## 1. Component

- [x] 1.1 Create `Modules/Sale/Livewire/SaleSummaryCards.php` mirroring `PurchaseSummaryCards`, with `$settingId` set from `session('setting_id')` in `mount()`
- [x] 1.2 Implement `getPiutangBelumTertagihProperty()` — count + `SUM(due_amount)` for `due_amount > 0`, `payment_status` in UNPAID/PARTIAL, `status` in APPROVED/DISPATCHED PARTIALLY/DISPATCHED, scoped to `setting_id`
- [x] 1.3 Implement `getPiutangTelatProperty()` — same as above plus `due_date < today`
- [x] 1.4 Implement `getPenerimaanProperty()` — distinct-invoice count + `SUM(amount)` from `SalePayment::active()` joined to in-setting PAID dispatched sales in the last 30 days; **do NOT divide by 100**
- [x] 1.5 Add fallback in `getPenerimaanProperty()` to PAID sales' `paid_amount` dated in the last 30 days when no active payment rows exist

## 2. View & Integration

- [x] 2.1 Create `Modules/Sale/Resources/views/livewire/sale-summary-cards.blade.php` mirroring the purchase card layout (three cards, rupiah formatting, AR labels)
- [x] 2.2 Insert `<livewire:sale.sale-summary-cards />` above `<livewire:sale.sale-table />` in `Modules/Sale/Resources/views/index.blade.php`

## 3. Tests

- [x] 3.1 Create `Modules/Sale/Tests/Feature/SaleSummaryCardsTest.php` mirroring `PurchaseSummaryCardsTest`
- [x] 3.2 Assert open-receivables includes dispatched outstanding sales and excludes PAID/draft/rejected sales
- [x] 3.3 Assert overdue card includes only `due_date < today` receivables
- [x] 3.4 Assert collections card counts distinct invoices and that a known `SalePayment.amount` surfaces unscaled (NOT divided by 100)
- [x] 3.5 Assert the fallback path uses `paid_amount` when no active payments exist
- [x] 3.6 Assert all metrics are scoped to the current `setting_id`

## 4. Verification

- [x] 4.1 Run the new test file via `php artisan test --filter SaleSummaryCardsTest`
- [x] 4.2 Manually load `sales.index` and confirm the three cards render with sensible values
