# POS Expected UI Component Checklist

Scope for this checklist:
- Target UI: cashier screen from the provided screenshot.
- Theme behavior: colors should follow current app theme (no hardcoded fixed palette).
- Ecommerce: intentionally out of scope (no ecommerce integration needed).
- Goal: keep current POS capabilities in this codebase represented in the target UI.

## Components We Can Support (Current Codebase)

- [x] Top POS session strip (cashier/session/terminal/status/opened time) using existing active session context from `pos.sell`.
- [x] Product scan/search input (barcode, SKU, name) with exact barcode auto-select from `GET /pos/sell/products/search`.
- [x] "List PLU" behavior via existing product search result list/modal backed by the same search endpoint.
- [x] Member/customer selector (name/phone search + default walk-in fallback) from `GET /pos/sell/customers/search` and `PATCH /pos/sell/cart/customer`.
- [x] Main cart table (items, qty, unit price, line discount, tax estimate, total, remove).
- [x] Item list/table columns like screenshot (`PLU/Nama Barang`, `Harga`, `QTY`, `Total`) from cart snapshot lines.
- [x] Row quantity controls can be implemented as `- / +` buttons (backend already supports qty update via `PATCH /pos/sell/cart/lines/{lineId}`).
- [x] Running total panel (`Total RP`) using cart grand total from `cart_snapshot.totals.grand_total`.
- [x] Cart-level summary panel (subtotal, discount total, tax estimate, grand total).
- [x] Payment CTA ("Pilih Pembayaran") with existing finalize flow and modal.
- [x] Cash/Transfer/QRIS payment flow, including cash change and non-cash reference validation.
- [x] Receipt print and reprint flow (`/pos/sell/checkout/{id}/receipt` and `/reprint`).
- [x] Reprint shortcut button can be added in bottom action bar and wired to existing reprint flow.
- [x] "Lap. Sales" shortcut can be wired to existing POS reports page (`/pos/reports`).
- [x] "Lainnya" shortcut can open menu to existing POS pages (`/pos/sessions`, `/pos/monitor`, `/pos/reconciliation`, `/pos/terminals`).
- [x] Retur shortcut can deep-link to existing Sales Return module (outside POS sell flow).
- [x] Theme-adaptive styling can use existing CoreUI/Bootstrap tokens (`btn-*`, `text-*`, `bg-*`) so it follows current theme.

## Components We Cannot Support Yet (Or Out Of Scope)

- [ ] Ecommerce order panel (`Cek Pesanan Ecommerce`) and ecommerce sync/check API.
- [ ] Promo engine panels (`Item Sponsor`, `Ringkasan Promo`, sponsor totals).
- [ ] Pending/parked cart workflow (`Pending`) for storing and restoring multiple suspended transactions.
- [ ] Clerk handover/switch workflow (`Clerk`) directly from cashier screen.
- [ ] Non-commerce cashier flow (`Non Commerce`) for non-stock/service transaction type.
- [ ] Embedded POS calculator component (`Kalkulator`) if required inside POS UI.
- [ ] In-flow POS return/exchange transaction from cashier screen (current return flow is separate module).
- [ ] Split payment/multi-method checkout in one transaction (currently single method only).
- [ ] Dynamic payment buttons from payment method master (`is_available_in_pos`) - current flow is fixed to cash/transfer/qris.
- [ ] Serial assignment UX controls in cashier screen (backend endpoints exist, but sell screen currently has no serial picker UI).
- [ ] "Load Data" workflow from cashier screen (no existing POS endpoint for loading external/suspended transaction data).

## Screenshot-Specific Mapping (Second Image: Item Rows Visible)

- [x] Multi-row cart rendering with line numbering.
- [x] Per-line totals displayed on the right.
- [x] Editable quantity per line (can be rendered with `-` / input / `+` style).
- [x] Bottom shortcut bar shell (`Input KP`, `Reprint`, `Lap. Sales`, `Lainnya`) can be mapped to existing modules/routes.
- [ ] `Pending`, `Clerek`, `Non Commerce`, `Load Data` business flows are not implemented in current POS sell flow.
- [ ] Sponsor/promo panels remain unavailable until promo engine exists.

## Screenshot-Specific Mapping (Third Image: Payment Slide)

- [x] Receipt preview panel is supportable (reuse current receipt structure/layout data in POS flow).
- [x] Cash payment summary block (`Total`, `Cash`, `Change`) is already supported by current checkout logic.
- [x] Quick amount presets (e.g. `5,000`, `10,000`, etc.) are supportable as optional UI shortcuts that fill amount paid.
- [x] Payment method selector is supportable with current methods (`cash`, `transfer`, `qris`) and labels can follow target UI.
- [ ] Full on-screen numeric keypad is intentionally not required for this phase (will be omitted).

## Notes For UI Build

- Keep the layout blocks from the screenshot, but wire actions only to supported features above.
- For unsupported blocks, either hide them or show disabled placeholders with "coming soon" labels.
- Do not add ecommerce-specific controls for this phase.
