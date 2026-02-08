# POS Rebuild Phase 0 - Codebase Discovery

## 1) Project overview (tech stack, architecture style)
- Backend framework: Laravel 10 (`composer.json`) with PHP 8.1.
- Architecture style: modular monolith using `nwidart/laravel-modules` (`Modules/*`), plus shared app layer (`app/*`).
- UI architecture: Blade + Livewire 3 components (server-driven UI), with Alpine.js and Bootstrap/CoreUI.
- Build toolchain: Vite (`package.json`) + legacy ecosystem dependencies.
- Permissions/RBAC: Spatie Permission (`spatie/laravel-permission`) + policy/`can:` middleware + `Gate::before` super-admin bypass (`app/Providers/AuthServiceProvider.php`).
- POS state/storage primitives:
  - Shopping cart state via `Cart::instance('sale')` (`anayarojo/shoppingcart`).
  - Tenant/business context via `session('setting_id')`.
  - POS session context via `pos_sessions` + middleware `pos.session`.

## 2) Key directories and responsibilities (POS-focused)
- `Modules/Sale/Routes/web.php`
  - Main POS web route contracts and POS transaction history/receipt print routes.
- `Modules/Sale/Http/Controllers/PosController.php`
  - Core POS transaction write path (sales, sale details, payments, receipts, dispatch, stock mutation, serial linking).
- `Modules/Sale/Resources/views/pos/*`
  - POS pages: session page, POS main page, cash settlement/pickup/reconciliation pages, supervisor monitor page.
- `app/Livewire/Pos/*`
  - POS runtime components: `Checkout`, `ProductList`, `SerialNumberPicker`, `SessionManager`, `SessionMonitor`, cash movement components.
- `app/Livewire/SearchProduct.php` + `resources/views/livewire/search-product.blade.php`
  - POS search input, barcode/serial exact-match handlers, suggestion flow.
- `resources/views/livewire/pos/*`
  - Existing POS UI pieces (product cards/list, cart/checkout, cash forms).
- `app/Support/PosLocationResolver.php`, `app/Support/PosSessionManager.php`
  - POS location resolution and POS session lifecycle orchestration.
- `Modules/Setting/*` (especially `SaleLocationConfigurationController`)
  - Cross-tenant location assignment and POS-enabled location configuration (`setting_sale_locations`).
- `database/migrations` + `Modules/*/Database/Migrations`
  - POS sessions/receipts/cash movement tables and related sales/product/setting schema.

## 3) Current POS entry points (routes, pages, components, state management)

### Routes and route names (current contracts)
Defined in `Modules/Sale/Routes/web.php` (inside `auth` + `role.setting`):
- `GET /app/pos/session` -> `app.pos.session`
- `GET /app/pos/sessions/monitor` -> `app.pos.monitor` (`can:reports.access`)
- `GET /app/pos` -> `app.pos.index` (`pos.session` middleware)
- `POST /app/pos` -> `app.pos.store`
- `POST /pos/store-as-quotation` -> `app.pos.store-as-quotation`
- `POST /app/pos/reprint-last` -> `app.pos.reprint-last`
- `GET /app/pos/cash-settlement` -> `app.pos.cash-settlement`
- `GET /app/pos/cash-pickup` -> `app.pos.cash-pickup`
- `GET /app/pos/cash-reconciliation` -> `app.pos.cash-reconciliation`
- `GET /pos-transactions` -> `pos.transactions.index` (`can:pos.transactions.access`)
- `GET /pos-receipt/{receipt}/print` -> `pos.receipt.print` (`can:pos.transactions.access`)

Navigation coupling:
- Header has direct POS button and cash-page links (`resources/views/layouts/header.blade.php`).
- Sidebar includes POS monitor/history links (`resources/views/layouts/menu.blade.php`).

### Pages and component composition
- POS main page: `Modules/Sale/Resources/views/pos/index.blade.php`
  - `<livewire:search-product/>`
  - `<livewire:pos.product-list/>`
  - `<livewire:pos.checkout/>`
  - Receipt print trigger through hidden iframe (`/pos-receipt/{id}/print`).
- Session page: `Modules/Sale/Resources/views/pos/session.blade.php` -> `<livewire:pos.session-manager/>`.
- Supervisor page: `Modules/Sale/Resources/views/pos/supervisor.blade.php` -> `<livewire:pos.session-monitor/>`.
- Cash pages:
  - `sale::pos.cash-settlement` -> `livewire.pos.cash-settlement`
  - `sale::pos.cash-pickup` -> `livewire.pos.cash-pickup`
  - `sale::pos.cash-reconciliation` -> `livewire.pos.cash-reconciliation`

### State management model
- Cart and sale draft-in-progress: `Cart::instance('sale')` (shared with non-POS sale flows).
- POS session guard:
  - `app/Http/Middleware/EnsureActivePosSession.php` blocks POS usage without active session.
  - Active session stored in request attribute `pos_session`.
- Location/tenant context:
  - `session('setting_id')` defines active tenant context.
  - `PosLocationResolver::setActiveAssignment()` may update session `setting_id`.
- Component event bus:
  - Search component dispatches `productSelected`, `serialScanned`, `posSearchUpdated` to checkout/list components.

## 4) Current sales flow (cart/order/invoice/payment)

### UI to cart
- Search path (`app/Livewire/SearchProduct.php`):
  - Exact serial scan, exact conversion barcode, exact product barcode, fallback suggestion search.
  - Emits selection events to checkout.
- Product list path (`app/Livewire/Pos/ProductList.php`):
  - Queries products + tenant price rows + stock aggregates constrained by POS locations.
- Checkout path (`app/Livewire/Pos/Checkout.php`):
  - Adds/updates/removes cart lines.
  - Handles serial-required items and optional bundle flow.
  - Computes totals and split payments.

### Persisting POS sale
`Modules/Sale/Http/Controllers/PosController::store()`:
1. Validates request (`StorePosSaleRequest`) and payment constraints.
2. Resolves active POS location assignment.
3. Partitions cart by owning tenant (`partitionCartByTenant` / `expandCartItemsBySetting`).
4. Creates `pos_receipts` record.
5. Creates one or more `sales` rows (per tenant partition).
6. Creates `sale_details` and optional bundle rows.
7. Deducts stock from `product_stocks` (non-tax/tax buckets), updates product summary quantity.
8. Creates `dispatches` and `dispatch_details`, including serial payload and tax.
9. Creates `sale_payments` allocations across sales.
10. Updates payment status on sales + receipt, commits transaction.

### Printing/transaction history
- Last transaction reprint: `PosController::reprintLast()` sets `session('pos_receipt_id')`.
- POS page JS auto-opens `/pos-receipt/{id}/print` after successful transaction.
- Historical transaction UI: `Modules/Sale/Http/Livewire/PosTransactions.php`.

## 5) Current inventory model and multi-location concepts

### Core inventory entities
- Stock-by-location/tax buckets: `Modules/Product/Entities/ProductStock.php` (`quantity_non_tax`, `quantity_tax`, `broken_*`, `tax_id`).
- Serialized inventory: `Modules/Product/Entities/ProductSerialNumber.php` (`location_id`, `tax_id`, `dispatch_detail_id`, status flags).
- Tenant/location assignment for POS availability: `Modules/Setting/Entities/SettingSaleLocation.php` (`setting_id`, `location_id`, `is_pos`, `position`).

### Location resolution
- `app/Support/PosLocationResolver.php` resolves POS-enabled locations ordered by:
  1. `setting_sale_locations.position`
  2. `setting_sale_locations.id`
- "First location" in current behavior is effectively first by this order.

### Allocation behavior (current implementation)
- In `app/Livewire/Pos/Checkout.php::resolveStockForProduct`:
  - Uses ordered POS location IDs.
  - Allocates **non-tax quantity across all locations first**, then **tax quantity across all locations**.
  - Serial-required items constrain availability by serial records and location.
- In `PosController`, cart lines can be split by source tenant/location allocations before creating sales.
- Stock ownership across tenants is mapped from location owner setting (`loadPosLocationSettingMap`).

## 6) Current permission model (roles, policies/guards)
- Global super-admin bypass: `Gate::before` in `app/Providers/AuthServiceProvider.php`.
- Tenant-role middleware: `role.setting` (`app/Http/Middleware/CheckUserRoleForSetting.php`) dynamically syncs role by active setting.
- POS session guard middleware: `pos.session` (`EnsureActivePosSession`).
- POS permissions seeded in `Modules/User/Database/Seeders/PermissionsTableSeeder.php`:
  - `pos.access`
  - `pos.create`
  - `pos.transactions.access`
- Route-level guards:
  - POS monitor uses `reports.access`.
  - POS transaction history and receipt print use `pos.transactions.access`.
- Current POS permissions are coarse-grained (no separate built-in roles for floor user vs cashier-pay-only vs cashier-modify).

## 7) Current tax handling approach
- Product-level tax metadata exists (`products.sale_tax_id`, `products.purchase_tax_id`).
- Stock-level tax bucketing exists (`product_stocks.quantity_non_tax`, `quantity_tax`, `tax_id`).
- Serial-level tax binding exists (`product_serial_numbers.tax_id`).
- Checkout resolves tax context from allocation/serial mix and stores resolved tax in cart options.
- During persistence:
  - `sale_details.tax_id` and `product_tax_amount` are populated from cart-derived values.
  - `dispatch_details.tax_id` is set when tax allocations exist.
  - Stock deductions happen separately for non-tax and tax quantities.

## 8) API surface relevant to POS (REST / WebSocket / events)

### POS-facing HTTP surface
- POS itself is primarily server-rendered web routes + form posts, not a separate REST POS API.
- Related JSON APIs used by UI/search contexts:
  - `routes/api.php` includes `/api/products/search`, `/api/taxes`, `/api/payment-terms`, etc.
  - `Modules/Sale/Routes/api.php` includes global sales search endpoints.

### Events and realtime
- Event class exists: `app/Events/PrintReceiptJob.php` (broadcasts `print-receipt-job.{userId}`).
- Channels declared in `routes/channels.php` (`App.Models.User.{id}`, `print.{userId}`).
- Frontend print handling currently relies on iframe print route and `resources/js/pos-printer.js`.

## 9) DB schema overview relevant to POS (migrations, entities)

### POS-specific tables/migrations
- `database/migrations/2025_12_20_000000_create_pos_sessions_table.php`
- `database/migrations/2025_12_20_000100_add_pos_session_columns.php` (adds FK links from sales/payment to pos session)
- `database/migrations/2025_12_31_000000_add_setting_id_to_pos_sessions_table.php`
- `database/migrations/2026_07_04_000001_create_pos_receipts_table.php` (and links from `sales` / `sale_payments`)
- `database/migrations/2025_10_05_000001_create_cashier_cash_movements_table.php`

### Supporting POS schema
- POS location config and ordering:
  - `Modules/Setting/Database/Migrations/2025_11_15_120000_create_setting_sale_locations_table.php`
  - `Modules/Setting/Database/Migrations/2025_12_01_000001_move_is_pos_to_setting_sale_locations.php`
  - `Modules/Setting/Database/Migrations/2026_01_01_000001_add_position_to_setting_sale_locations.php`
- POS payment method flags:
  - `Modules/Setting/Database/Migrations/2025_10_10_000000_add_pos_flags_to_payment_methods_table.php`
- Product stock/serial/tax structure:
  - `Modules/Product/Database/Migrations/2024_09_27_132637_add_product_stock_table.php`
  - `Modules/Product/Database/Migrations/2024_09_26_115759_add_serial_number_table.php`
  - `Modules/Product/Database/Migrations/2024_10_04_041346_add_product_tax_info.php`
- POS search performance indexes:
  - `database/migrations/2026_01_13_174819_add_pos_search_indexes.php`

### Core entities tied to POS
- `App\Models\PosSession`, `App\Models\PosReceipt`, `App\Models\CashierCashMovement`
- `Modules\Sale\Entities\Sale`, `SaleDetails`, `SalePayment`, `Dispatch`, `DispatchDetail`
- `Modules\Product\Entities\ProductStock`, `ProductSerialNumber`
- `Modules\Setting\Entities\SettingSaleLocation`, `PaymentMethod`

## 10) Existing test strategy (unit/integration/e2e)
- Test framework: PHPUnit (`phpunit.xml`).
- Configured suites: `tests/Unit`, `tests/Feature`.
- Test DB defaults: in-memory SQLite (`DB_DATABASE=:memory:`), `RefreshDatabase` used heavily.
- POS-relevant feature tests include:
  - `tests/Feature/PosCheckoutTest.php`
  - `tests/Feature/PosMultiLocationInventoryTest.php`
  - `tests/Feature/PosMixedLocationDispatchTest.php`
  - `tests/Feature/PosSerialDispatchTest.php`
  - `tests/Feature/SaleListShowsPosSalesTest.php`
- Sales/inventory behavior coverage around POS dependencies:
  - `tests/Feature/SaleStockValidationTest.php`, `SaleMonetaryValuesTest.php`, others.
- Module-local tests exist under `Modules/*/Tests`, but default `phpunit.xml` suites target `tests/*` only.
- No dedicated browser E2E suite found (no Cypress/Playwright/Dusk config discovered in this scan).

## 11) Notable conventions or invariants
- `session('setting_id')` is a hard invariant for tenant scoping in queries and middleware.
- Dynamic role synchronization per active setting happens in middleware (`CheckUserRoleForSetting`).
- POS requires active, non-paused session for main operations (`EnsureActivePosSession`).
- Shared cart instance invariant: `Cart::instance('sale')` is used by POS and regular sales flows.
- Many models inherit `BaseModel` text normalization (uppercase-on-write by default), with selective exceptions.
- Archiving behavior via `Archivable` trait/global scope affects how records appear unless explicitly opting out.
- Several modules rely on idempotency middleware, but current POS store route does not use explicit idempotency middleware.

## 12) Risks for "clean removal" and how to avoid breaking changes

### Risk 1: Route and navigation contract breakage
- Why: POS route names are referenced directly in header/sidebar and views.
- Avoid breaking:
  - Keep route names stable during deprecation (`app.pos.*`, `pos.transactions.*`, `pos.receipt.print`).
  - Use feature flags to hide old UI while preserving route handlers or compatibility redirects.

### Risk 2: Shared cart coupling with non-POS sales
- Why: both flows use `Cart::instance('sale')`.
- Avoid breaking:
  - Preserve cart contract until all dependent sales code is migrated.
  - Introduce adapter/abstraction before replacing internals.

### Risk 3: Data model/reporting coupling to POS fields
- Why: `sales.pos_session_id`, `sales.pos_receipt_id`, payment links, and POS history screens are already used.
- Avoid breaking:
  - Keep schema and relationships backward-compatible.
  - If replacing internals, continue writing these links or provide compatibility views.

### Risk 4: Inventory and serial consistency regressions
- Why: POS store path mutates stock buckets, dispatch records, serial links, and transaction logs in one transaction.
- Avoid breaking:
  - Preserve transaction boundaries and mutation ordering.
  - Reuse existing test scenarios (multi-location, tax/non-tax buckets, serial dispatch).

### Risk 5: Tenant context side effects during POS location assignment
- Why: `PosLocationResolver::setActiveAssignment()` can mutate session `setting_id`.
- Avoid breaking:
  - Freeze tenant context behavior explicitly before UI replacement.
  - Add regression tests around assignment switching and cross-tenant stock sourcing.

### Risk 6: Permission model mismatch with new role design
- Why: current permissions are coarse (`pos.access`, `pos.create`, `pos.transactions.access`).
- Avoid breaking:
  - Keep existing permissions during transition.
  - Add new granular permissions in additive way, map old permissions to safe defaults.

### Risk 7: Existing POS runtime defect in quotation path
- Observation: `PosController::storeAsQuotation()` references `$posSession` without local initialization.
- Avoid breaking:
  - Capture this as a known baseline defect before removal/rebuild.
  - Ensure replacement path does not inherit this ambiguity.

