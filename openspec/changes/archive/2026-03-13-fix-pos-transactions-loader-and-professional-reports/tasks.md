## 1. Script Stack Reliability

- [x] 1.1 Add layout-level compatibility so both `page_scripts` and legacy `scripts` stacks are rendered for application pages.
- [x] 1.2 Standardize POS async pages (`transactions/index`, `transactions/show`, `reports/index`, `monitor/index`, `reconciliation/index`) to the canonical script stack to avoid silent script drops.
- [x] 1.3 Verify transaction list bootstrap executes on initial `/pos/transactions` load and no longer stalls at `Memuat data...`.

## 2. Transaction List UX State Hardening

- [x] 2.1 Refine transaction list loading flow so each request resolves into explicit loading, empty, data, or error state.
- [x] 2.2 Ensure `Muat Data` reliably retries using active filter values and updates status messaging consistently.
- [x] 2.3 Validate error and empty-state copy for clarity in Indonesian-language UI.

## 3. Professional POS Reports Dashboard

- [x] 3.1 Redesign reports page structure to KPI-first layout with clear hierarchy above detail tabs.
- [x] 3.2 Implement KPI summary computation/rendering for active date range using existing report endpoints.
- [x] 3.3 Rework detail tabs (`Penjualan Harian`, `Ringkasan Kasir`, `Metode Pembayaran`, `Penjualan Produk`, `Persetujuan Supervisor`) with improved visual structure.
- [x] 3.4 Add deterministic loading/empty/error states per report region and keep refresh/date filter behavior consistent across tabs.
- [x] 3.5 Validate responsive behavior for desktop and mobile breakpoints.

## 4. Verification

- [x] 4.1 Add or update automated tests covering transaction list bootstrap/runtime loading behavior.
- [x] 4.2 Add or update automated tests for report page tab availability and date-filter refresh behavior.
- [x] 4.3 Run manual smoke checks for POS transactions, reports, monitor, and reconciliation pages to confirm script execution and UI states.
