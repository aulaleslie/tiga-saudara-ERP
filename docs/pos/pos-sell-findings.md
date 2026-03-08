# POS Sell Findings Log

This document tracks root-cause investigations for reported POS Sell issues.

## POS-001 - `GET /pos/sell/cart` returns `{"message":"Server Error"}`

- Reported date: 2026-03-08
- Status: Open
- Severity: High (blocks POS Sell cart bootstrap)

### Reported symptom

Calling `GET /pos/sell/cart` from the POS Sell screen returns HTTP 500 with body:

```json
{
  "message": "Server Error"
}
```

### Root cause

`selected_customer_id = null` is a valid cart state in session storage, but snapshot generation now treats it as an exception:

1. `PosCartSessionStore::emptyCart()` initializes `selected_customer_id` to `null`.
2. `PosCartService::buildSnapshot()` always calls `PosCheckoutCustomerResolverService::resolve(...)`.
3. `PosCheckoutCustomerResolverService::resolve()` throws `DomainException('CUSTOMER_NOT_SELECTED')` when selected customer is `null`.
4. `PosSellController::cartShow()` does not catch `DomainException`, so the exception bubbles into a generic HTTP 500 response.

This is a contract mismatch: cart snapshot flows still expect unresolved customer state to be representable (not fatal), while the resolver now hard-fails for unresolved customer.

### Evidence

- Runtime log confirms unhandled exception in cart show path:
  - `storage/logs/laravel.log:1185` (`CUSTOMER_NOT_SELECTED`)
  - stack includes:
    - `Modules/Pos/Services/PosCheckoutCustomerResolverService.php:24`
    - `Modules/Pos/Services/PosCartService.php:534`
    - `Modules/Pos/Http/Controllers/PosSellController.php:149`
- Code path:
  - `Modules/Pos/Services/PosCartSessionStore.php:98` (default `selected_customer_id => null`)
  - `Modules/Pos/Services/PosCartService.php:534` (always resolves customer inside snapshot)
  - `Modules/Pos/Services/PosCheckoutCustomerResolverService.php:24` (throws when no customer selected)
  - `Modules/Pos/Http/Controllers/PosSellController.php:143-150` (no `DomainException` handling in `cartShow`)
- Behavior contract evidence from tests/UI:
  - `Modules/Pos/Tests/Feature/POSWalkInCustomerSelectionTest.php:127-129` expects null customer snapshot with `resolution_source = 'none'` after clearing selection.
  - Running `php artisan test Modules/Pos/Tests/Feature/POSWalkInCustomerSelectionTest.php --filter=test_clearing_customer_selection_sets_customer_to_null` currently fails with `expected 200, got 422`.
  - `Modules/Pos/Resources/views/sell.blade.php:1160` explicitly renders "Belum ada pelanggan dipilih." for unresolved customer state.

### Impacted behavior

- `GET /pos/sell/cart` can 500 when customer is not selected.
- Clearing customer (`PATCH /pos/sell/cart/customer` with `customer_id: null`) returns 422 instead of valid unresolved snapshot.
- Any flow that builds a snapshot before explicit customer selection is unstable and may fail hard or return unexpected 422.

### Fix direction (not implemented in this finding pass)

1. Keep strict customer requirement at checkout finalization, not during cart snapshot retrieval.
2. Update customer resolver or snapshot builder to return unresolved customer payload (for example, `resolution_source = 'none'`) instead of throwing for null selection.
3. Add regression coverage:
   - `GET /pos/sell/cart` without selected customer should return `200`.
   - Clearing selected customer should return `200` with unresolved customer payload.
   - Checkout finalize without selected customer should still return the expected validation error.

## POS-002 - Clicking product suggestion returns `{"message":"CUSTOMER_NOT_SELECTED"}`

- Reported date: 2026-03-08
- Status: Open
- Severity: High (cashier cannot reliably add items from search)
- Related finding: POS-001 (same customer-resolution failure cluster)

### Reported symptom

From POS Sell search:

1. Type `sam`, select `SAMSUNG GALAXY Z FOLD`.
2. Frontend sends `POST /pos/sell/cart/lines` with payload `{"product_id":1,"qty":1}`.
3. API responds with:

```json
{"message":"CUSTOMER_NOT_SELECTED"}
```

### Root cause

`POST /pos/sell/cart/lines` fails for the same unresolved-customer reason, but this endpoint catches the exception and returns it as a 422 message:

1. Product suggestion click uses `addProductToCart()` -> `POST /pos/sell/cart/lines`.
2. `PosSellController::cartStoreLine()` calls `PosCartService::addLine()` inside `try/catch (DomainException)` and returns the exception message as HTTP 422.
3. `PosCartService::addLine()` writes the new/updated line to session first (`putCart`) and then calls `buildSnapshot()`.
4. `buildSnapshot()` always resolves customer, and resolver throws `DomainException('CUSTOMER_NOT_SELECTED')` when no customer is selected.
5. Controller catches it and responds with `{"message":"CUSTOMER_NOT_SELECTED"}`.

### Evidence

- Frontend call path:
  - `Modules/Pos/Resources/views/sell.blade.php:1467-1470` posts to cart lines endpoint with `product_id` and `qty`.
- Controller behavior:
  - `Modules/Pos/Http/Controllers/PosSellController.php:160-170` catches `DomainException` and returns message with status `422`.
- Service behavior:
  - `Modules/Pos/Services/PosCartService.php:113-115` persists cart first, then builds snapshot.
  - `Modules/Pos/Services/PosCartService.php:534` resolves customer during snapshot.
  - `Modules/Pos/Services/PosCheckoutCustomerResolverService.php:24` throws `CUSTOMER_NOT_SELECTED` when `selected_customer_id` is null.
- User-reported API response:
  - `POST /pos/sell/cart/lines` -> `{"message":"CUSTOMER_NOT_SELECTED"}`.

### Impacted behavior

- Cashier flow is blocked when adding products before explicit customer selection.
- Hidden state mutation risk: line write happens before the exception, so user can receive an error while cart has already changed.
- Repeated retries can unintentionally increase quantity or create cart state that appears inconsistent with UI feedback.

### Fix direction (not implemented in this finding pass)

1. Make unresolved customer non-fatal for cart snapshot APIs (`cart show`, `add line`, `update line`, etc.).
2. Keep strict customer-required validation only at checkout finalization boundary.
3. Prevent write-then-error behavior in cart mutation endpoints by ensuring snapshot cannot fail for unresolved customer, or by making mutation+response path atomic.

## POS-003 - Pressing Enter in product search does not open expected result modal and triggers 405

- Reported date: 2026-03-08
- Status: Open
- Severity: High (primary keyboard search flow is broken/misaligned)
- Related findings: POS-001, POS-002 (additional 500/422 noise seen in same console trace)

### Reported symptom

When typing `sam` in the POS product search input and pressing Enter (without clicking suggestion):

- Expected: search-result modal opens.
- Actual:
  - `POST /pos/sell/search/resolve` returns `405 Method Not Allowed`.
  - Additional errors appear in console:
    - `GET /pos/sell/cart` -> `500`
    - `POST /pos/sell/cart/lines` -> `422`

### Root cause

There are multiple implementation mismatches in the Enter-key path:

1. Enter is hardwired to "scan resolve" behavior, not to opening a search-result modal/list flow.
2. The Enter handler calls `jsonRequest(scanResolveEndpoint, 'POST', { q })`.
3. Backend route for `/pos/sell/search/resolve` only supports `GET`/`HEAD`, so the request fails with `405`.
4. The scan resolver service only returns exact-match outcomes (`product_exact`, `serial_exact`, `none`); it does not return `ambiguous`, so fallback intended for showing regular search results from Enter path is effectively unreachable.
5. Current page template has no product search-result modal implementation (only inline suggestion list and other unrelated modals), so expected "open search result modal" behavior is not represented in this code path.

### Evidence

- Enter handler is scan path:
  - `Modules/Pos/Resources/views/sell.blade.php:1610-1649`
  - calls `jsonRequest(scanResolveEndpoint, 'POST', { q: query })` at `:1626`
- Route only supports GET:
  - `Modules/Pos/Routes/web.php:89` (`Route::get('/pos/sell/search/resolve', ...)`)
- Controller endpoint:
  - `Modules/Pos/Http/Controllers/PosSellController.php:387-400` (`scanResolve`)
- Scan service return types:
  - `Modules/Pos/Services/PosScanResolverService.php:27` documents `ambiguous`
  - implementation returns `product_exact`, `serial_exact`, or `none`; fallback at `:109-110`
- No product result modal exists:
  - search input uses inline list `#pos-shell-search-results` at `Modules/Pos/Resources/views/sell.blade.php:660-664`
  - modal IDs present are checkout/success/customer-create (`#pos-checkout-modal`, `#pos-success-modal`, `#pos-customer-create-modal`)
- Supporting comment confirms intended Enter behavior change:
  - `Modules/Pos/Resources/views/sell.blade.php:1730` ("Enter key now handles scan resolution")

### Notes on additional console errors

- `GET /pos/sell/cart` 500 and `POST /pos/sell/cart/lines` 422 in the same session are consistent with POS-001/POS-002 (`CUSTOMER_NOT_SELECTED`) and are cascading errors, not unique to Enter-key routing.

### Fix direction (not implemented in this finding pass)

1. Decide a single Enter behavior contract:
   - scan-first exact resolve, or
   - open search-result UI when query is non-exact.
2. Align frontend/backend transport for scan resolve:
   - either change frontend to `GET` with query params, or expose `POST` route intentionally.
3. If modal is the requirement, implement explicit modal rendering path and invoke it from Enter handler.
4. If ambiguous fallback is required, implement ambiguous output in `PosScanResolverService` (or call normal search directly from Enter path when non-exact).
