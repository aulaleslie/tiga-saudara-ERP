## 1. Livewire Component — PurchaseSummaryCards

- [x] 1.1 Create `Modules/Purchase/Livewire/PurchaseSummaryCards.php` with computed properties for `belumDibayar`, `telatBayar`, and `pelunasan` (each returning `count` and `total`)
- [x] 1.2 Implement `belumDibayar` query: `payment_status = UNPAID AND due_amount > 0 AND status IN [APPROVED, RECEIVED PARTIALLY, RECEIVED]`
- [x] 1.3 Implement `telatBayar` query: same as belumDibayar plus `due_date < today`
- [x] 1.4 Implement `pelunasan` query: check `purchase_payments` for ACTIVE rows with `date >= 30 days ago`; if found use that table, else fall back to `purchases.date >= 30 days ago AND payment_status = PAID`
- [x] 1.5 Register the component in `Modules/Purchase/Providers/PurchaseServiceProvider.php` (or verify auto-discovery covers it)

## 2. Blade View — Summary Cards

- [x] 2.1 Create `Modules/Purchase/Resources/views/livewire/purchase-summary-cards.blade.php` with three Bootstrap cards in a row
- [x] 2.2 Style cards: blue left-border for belum dibayar, red for telat bayar, green for pelunasan (use Bootstrap utility classes, no custom CSS)
- [x] 2.3 Display count and Rupiah-formatted total on each card
- [x] 2.4 Add `wire:click` or Alpine `@click` on each card to dispatch a `purchase-filter` browser event with the filter payload (`type: 'unpaid' | 'overdue' | 'paid'`)

## 3. DataTable Filter Integration

- [x] 3.1 Add `public ?string $paymentStatusFilter = null` and `public bool $overdueOnly = false` properties to `app/Livewire/Purchase/PurchaseTable.php`
- [x] 3.2 Add `#[On('purchase-filter')]` listener method to `PurchaseTable` that sets `paymentStatusFilter` and `overdueOnly` from the event payload
- [x] 3.3 Apply `paymentStatusFilter` and `overdueOnly` to the DataTable query in `PurchaseTable` (alongside existing `statusFilter`)

## 4. Index View Integration

- [x] 4.1 Add `<livewire:purchase.purchase-summary-cards />` above the DataTable in `Modules/Purchase/Resources/views/index.blade.php`

## 5. Tests

- [x] 5.1 Write a Livewire test for `PurchaseSummaryCards` asserting correct counts for each card given seeded purchases
- [x] 5.2 Write a Livewire test asserting `PurchaseTable` filters correctly when `purchase-filter` event is dispatched with `type = unpaid`
- [x] 5.3 Write a Livewire test asserting `PurchaseTable` filters correctly when `purchase-filter` event is dispatched with `type = overdue`
- [x] 5.4 Run `composer test:fresh-sqlite` and confirm all tests pass

## 6. Spec Sync

- [x] 6.1 Archive `specs/purchase-summary-cards/spec.md` into `openspec/specs/purchase-summary-cards/spec.md`
