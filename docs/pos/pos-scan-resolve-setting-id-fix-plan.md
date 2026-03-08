# POS Product Search/Scan Tenant Mismatch Investigation and Fix Plan

Date: 2026-03-08  
Owner: POS / Product domain

## Scope

Reported issues:

1. Newly created product `KERTAS ...` is not found from current active POS.
2. Typing `sam` and pressing Enter does not open selectable search results as expected.

## Investigation Summary (Verified with Tinker)

### 1. Product exists, but belongs to different tenant than active POS session

- Product record:
  - `products.id = 2`
  - `product_name = KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET`
  - `setting_id = 2`
  - `stock_managed = 1`
  - `product_quantity = 100`
- Active POS session:
  - `pos_sessions.id = 1`
  - `setting_id = 1`
  - `status = OPEN`

### 2. Stock exists and is in a location allowed by current setting

- `product_stocks` has `product_id = 2`, `location_id = 2`, `quantity = 100`.
- `SalesLocationResolver::resolveLocationIds(1)` includes location `2`.

This confirms stock/location is not the blocker.

### 3. POS search is currently hard-scoped to product.setting_id

- `Modules/Pos/Services/PosProductSearchService.php` applies:
  - `->where('p.setting_id', $settingId)`
- Tinker verification:
  - `search(1, "kertas")` => `0` results
  - `search(2, "kertas")` => `1` result

Root cause for missing `KERTAS` from current POS: tenant filter on `products.setting_id`.

### 4. Enter key flow is scan-first and does not open a modal

- In `Modules/Pos/Resources/views/sell.blade.php`, Enter calls `/pos/sell/search/resolve` first.
- Non-exact result falls back to inline `#pos-shell-search-results`, not a dialog modal.
- Current code has no product-result modal path for Enter-based fallback.

### 5. Historical 500 was real, and explains prior "nothing happened"

- Log evidence:
  - `storage/logs/laravel.log` shows prior `GET /pos/sell/search/resolve?q=sam` 500:
    - `Unknown column 'setting_id' in product_unit_conversions`
- Current source no longer has that invalid conversion query, but when that 500 happened, Enter flow stopped before fallback suggestions, so UX appeared broken.

## Fix Plan

## Phase 0 - Confirm Product Visibility Contract (Required Decision)

### ✅ DECISION: SHARED CATALOG APPROVED

**Date**: 2026-03-08  
**Chosen Contract**: Shared product catalog across tenants

Any product can be sold in any POS setting if:
1. Stock exists in an allowed sale location for the active setting
2. A `product_prices` row exists for the active setting

### Decision Rationale

**Evidence (verified):**
- `ProductCreator::create()` seeds `product_prices` for **ALL** active settings (see `Modules/Product/Services/ProductCreator.php` L36-45, L106-114)
- `ProductPrice` model enforces `unique(['product_id', 'setting_id'])` — exactly one price row per product per setting
- `product_unit_conversions` conversions also seed prices for all settings on creation (see `ProductController::update()` L256-260)
- `SalesLocationResolver::resolveLocationIds($settingId)` returns enabled location IDs for a setting, providing the stock-availability guard
- Current user expectation: newly created product should be findable across all POS terminals even if created in different setting

**Scope Boundary:**
- ✅ Shared catalog applies to **PRODUCTS ONLY**
- ❌ Customers, payments, POS sessions remain strictly tenant-scoped
- ❌ No schema changes required
- ❌ No changes to product creation flow (already correct)

### Original Decision Framewor (archived)

Options considered:

1. Tenant-isolated products (current behavior): only `products.setting_id == active setting`.
2. Shared product catalog across tenants (recommended for this report): any product can be sold if:
   - stock exists in allowed sale locations for active setting
   - `product_prices` row exists for active setting

Reason for recommendation:

- Product creation already seeds `product_prices` for all settings.
- Current user expectation matches shared-catalog behavior.

## Phase 1 - Enter Key UX Reliability

Goal: Enter must always produce actionable UX (auto-add exact, else show selectable results UI).

### Backend/Frontend changes

1. Keep scan-first behavior for exact barcode/SKU/serial.
2. In Enter handler catch path, call normal product search fallback instead of only status error.
3. Implement explicit result dialog/modal for Enter fallback:
   - show result list
   - click/Enter on item adds to cart
4. Keep inline list behavior for typing if needed, but Enter should open modal to match requirement.

### Files

- `Modules/Pos/Resources/views/sell.blade.php`

### Acceptance criteria

1. Typing `sam` + Enter always shows selectable results UI when no exact scan match.
2. User can select an item from the dialog and item is added to cart.
3. Resolver failure does not end in silent/no-op UX.

## Phase 2 - Tenant Scope Alignment for POS Product Discovery

Goal: align POS search/scan/add-to-cart with chosen product visibility contract from Phase 0.

If shared-catalog is approved, apply these changes:

1. `PosProductSearchService`
   - Remove strict `p.setting_id = $settingId` filter.
   - Keep stock availability filter via allowed sale locations.
   - Keep price join from `product_prices` by active `setting_id`.
2. `PosScanResolverService`
   - For product barcode/SKU and conversion barcode resolution, avoid strict product tenant filter.
   - Add guard: product must have stock in allowed location(s) to resolve.
3. `PosCartService::resolveCartProduct`
   - Remove strict `product.setting_id = $settingId` filter.
   - Keep stock availability check by allowed location IDs.
   - Keep pricing via `product_prices` for active setting.
4. Preserve strict tenant scoping for customers/payments/session entities.

### Files

- `Modules/Pos/Services/PosProductSearchService.php`
- `Modules/Pos/Services/PosScanResolverService.php`
- `Modules/Pos/Services/PosCartService.php`

### Acceptance criteria

1. Product created in setting 2 is discoverable in POS setting 1 when stock is available in setting 1 allowed locations.
2. Add-to-cart succeeds and uses active setting price row.
3. No cross-tenant leakage for customer/payment/session data.

## Phase 3 - Regression Coverage

Add or update tests for:

1. Cross-setting product discovery in POS search.
2. Cross-setting scan resolve returns expected product when stock/location qualifies.
3. Cart add line succeeds for cross-setting-discovered product and uses `product_prices(setting_id=current)`.
4. Enter fallback UX:
   - non-exact query opens results dialog path
   - resolver 500/error still falls back to search UI
5. Existing scan regression:
   - no SQL 42S22 on `/pos/sell/search/resolve`.

Suggested test files:

- `Modules/Pos/Tests/Feature/POSScanResolveEndpointTest.php`
- new: `Modules/Pos/Tests/Feature/POSProductSearchTenantVisibilityTest.php`
- optionally browser/e2e test for Enter dialog behavior

## Phase 4 - Rollout and Verification

1. Deploy in two PRs:
   - PR-A: Enter fallback + dialog UX
   - PR-B: tenant visibility alignment in POS services + tests
2. Validate with tinker + manual UAT:
   - `search(1, "kertas")` result present after fix
   - POS page `sam` + Enter opens dialog
3. Monitor 24h:
   - 500 rate on `/pos/sell/search/resolve`
   - non-200 rate on `/pos/sell/products/search`
   - non-200 rate on `/pos/sell/cart/lines`
