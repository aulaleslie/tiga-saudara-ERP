# sell.blade.php Navigation Map

**File:** `Modules/Pos/Resources/views/sell.blade.php`
**Size:** 4,219 lines
**Structure:** Single monolithic view with inline CSS and JS

---

## Quick Jump Links

### CSS Section
| Section | Lines | Purpose |
|---------|-------|---------|
| **Styles** | 1–729 | All inline `<style>` tags |

### HTML Content
| Area | Lines | ID Anchor | Purpose |
|------|-------|-----------|---------|
| **Layout Frame** | 729–750 | `.pos-shell` | Main container, landscape lock |
| **Info Bar** | 752–768 | `.pos-area-info` | Session info strip |
| **Navigation** | 770–850 | `pos-nav-menu-dropdown` | Menu, session controls |
| **Product Search** | 852–870 | `pos-shell-search` | Product lookup input |
| **Shopping Cart** | 872–950 | `pos-shell-cart-body` | Line items table |
| **Customer Picker** | 895–910 | `pos-customer-search` | Customer selection |
| **Payment Summary** | 920–945 | `pos-payment-summary-total` | Totals display |
| **Checkout Modal** | 948–1030 | `pos-checkout-modal` | Single-stage payment UI |
| **Staged Payment Modal** | 1033–1370 | `pos-staged-checkout-modal` | Multi-stage payment flow |

### JavaScript Section
| Section | Lines | Init | Purpose |
|---------|-------|------|---------|
| **Module Init** | 1372–1374 | `(function() {` | External module load + IIFE start |
| **DOM Cache** | 1376–1468 | — | Element references (~60 IDs) |
| **Endpoints** | 1456–1467 | — | API route definitions |
| **State Variables** | 1483–1496 | — | Cart, payments, approvals, snapshots |
| **Utility Funcs** | 1498–1566 | — | Status messages, formatting, HTML escape |

---

## Functional Domains

### 1. **Product Search & Cart Addition** (Lines 2896–2989)
**Entry Point:** `searchInput.addEventListener('keydown'...`

**Functions:**
- `executeModalSearch()` (L3048)
- `renderSearchResultsModal(data)` (L2710)
- `setupSearchResultsModalKeyboard()` (L2854)
- `buildLineRow(line)` (L2175) — renders individual cart item

**Event Listeners:**
- L2896: Search bar keydown (Enter to open modal)
- L2935: "Cari Produk" button click
- L2969, L2980: Modal visibility handlers
- L3062: Modal search input keyboard nav

**State Modified:**
- `currentSnapshot` — cart after adding line
- `checkoutPayments` — if payment was in progress

**IDs Used:**
```
pos-shell-search, pos-shell-search-status
pos-btn-cari-produk
pos-search-results-modal, pos-modal-search-input, pos-modal-search-btn
pos-search-modal-results
```

---

### 2. **Cart Display & Line Management** (Lines 2362–2514, 2175–2361)
**Entry Point:** `renderCart(snapshot)` (L2362)

**Functions:**
- `renderCart(snapshot)` (L2362) — main renderer
- `buildLineRow(line)` (L2175) — each line item HTML
- `renderQtyApprovalSlotButton(...)` (L1776) — approval badge
- `renderTotals(snapshot)` (L2033)

**Event Listeners:**
- L3190: Quantity input change → apply update
- L3261: Click on reduce-qty button → open modal
- L3407: Click on approve-qty button → handle approval

**Cart Display Includes:**
- Serial number badge (if product is serial-tracked)
- Quantity reduction request badge (if pending)
- Quantity approval badge (pending supervisor sign-off)

**State Modified:**
- `currentSnapshot` — refreshed after cart changes
- `clientPendingApprovals` — tracks QTY reduction requests
- `pendingReduceLineId`, etc. — modal state

**IDs Used:**
```
pos-shell-cart-body (the table)
pos-cart-action-status, pos-cart-action-alert
pos-cart-total-subtotal, pos-cart-total-grand
pos-cart-clear
```

---

### 3. **Customer Selection** (Lines 3071–3154)
**Entry Point:** `customerSearchInput.addEventListener('input'...` (L3071)

**Functions:**
- `renderCustomerSearchResults(data)` (L2082)
- `setCustomerStatus(message, tone)` (L1533)

**Event Listeners:**
- L3071: Customer search input (debounced)
- L2114: Result item click → select customer
- L3092: "Create new" button → open modal
- L3102: Customer create form submit

**State Modified:**
- `currentSnapshot.customer` — selected customer

**IDs Used:**
```
pos-customer-search
pos-customer-search-results
pos-customer-resolution
pos-customer-action-status
pos-customer-create-btn
pos-customer-create-modal, pos-customer-create-form
pos-new-customer-name, pos-new-customer-phone, pos-new-customer-tier
```

---

### 4. **Serial Number Modal** (Lines 2546–3044)
**Entry Point:** `openSerialModal(lineId, productName)` (L2546)

**Functions:**
- `openSerialModal(lineId, productName)` (L2546)
- `renderSerialModalList()` (L2522)
- `setSerialAppendInFlight(inFlight)` (L2515)

**Event Listeners:**
- L2989: Modal show (focus input)
- L2996: Modal hidden (cleanup)
- L3011: Serial input keydown (Enter to add)
- L3020: Submit button click
- L3027: List item click (remove serial)

**Triggered By:** Cart row click on serial badge

**IDs Used:**
```
pos-serial-modal, pos-serial-modal-product-name
pos-serial-modal-qty-info
pos-serial-modal-input, pos-serial-modal-submit
pos-serial-modal-status, pos-serial-modal-list
```

---

### 5. **Quantity Reduction Flow** (Lines 3407–3549)
**Entry Point:** Cart line → "Kurangi Qty" button click (L3407)

**Functions:**
- `normalizeQtyApprovalState(approvalObj)` (L1756) — parse approval state
- Modal form validation & submission

**Event Listeners:**
- L3435: New qty input validation
- L3462: Submit button → POST reduction request
- L3549: Modal hidden → cleanup

**Approval Rendering:**
- Lines 1776–2028: `renderQtyApprovalSlotButton()` — builds approval badge

**State Modified:**
- `pendingReduceLineId`, `pendingReduceCurrentQty`, `pendingReduceButton`
- `clientPendingApprovals` (tracks request status)
- Cart re-renders with new badge

**IDs Used:**
```
pos-reduce-quantity-modal
pos-reduce-qty-current, pos-reduce-qty-new
pos-reduce-qty-error, pos-reduce-qty-reason, pos-reduce-qty-submit
```

---

### 6. **Single-Stage Checkout Modal** (Lines 3911–4087)
**Entry Point:** "Bayar" button click (L3984)

**Functions:**
- `openPaymentModal()` (L3911)
- `addPaymentRow(method)` (L1568) — add payment row (Task 4.1)
- `renderPaymentsList()` (L1591)
- `updatePaymentSummary()` (L1675)
- `validatePaymentComposer()` (L1701)
- `renderPaymentMethodResults(results)` (L3883)
- `selectPaymentMethod(method)` (L3907)
- `renderReceiptPreview(snapshot)` (L3835)

**Event Listeners:**
- L3945: Payment method search input
- L3959: Payment method search focus
- L3967: Payment method results click → select
- L4017: Submit button → finalize

**State Modified:**
- `checkoutPayments` — array of {id, method, amount, reference, errors}
- `currentSnapshot` — payment data

**IDs Used:**
```
pos-checkout-modal
pos-checkout-method-search, pos-checkout-method-results
pos-checkout-receipt-lines, pos-checkout-receipt-total
pos-checkout-total-label, pos-checkout-amount-paid-summary
pos-checkout-remaining-label, pos-checkout-submit
pos-checkout-error, pos-checkout-payments-list
```

---

### 7. **Multi-Stage (Staged) Payment** (Lines 1033–1370 HTML + external JS)
**File:** `public/js/pos-staged-payment.js` (imported L1373)

**Triggered By:** `PosStagedPayment.setOnComplete()` callback (L4120)

**Module Pattern:** `window.PosStagedPayment` — encapsulated state machine

**IDs Used:**
```
pos-staged-checkout-modal
staged-method-search, staged-method-results
staged-amount-input, staged-edc-reference
staged-remainder-amount
staged-payment-chain, staged-payment-submit
staged-payment-error, staged-payment-spinner
```

---

### 8. **Cash Pickup & Reconciliation** (Lines 3582–3762)
**Entry Point:** "Pengambilan Kas" menu item → `pickupBtn` click (L3713)

**Functions:**
- `showPickupStep1()` (L3582)
- `showPickupStep2()` (L3589)
- `validatePickupAmount()` (L3597)
- `formatPrice(amount)` (L3620) — ⚠️ duplicate of L1553

**Event Listeners:**
- L3634: Next button (step 1→2)
- L3651: Back button (step 2→1)
- L3657: Confirm button → POST pickup
- L3756: Modal hidden → cleanup

**IDs Used:**
```
pos-cash-pickup-modal
pickup-step-1, pickup-step-2
pickup-amount-input, pickup-amount-error
pickup-next-btn, pickup-back-btn, pickup-confirm-btn
pickup-spinner
```

---

### 9. **Session Close** (Lines 3772–3830)
**Entry Point:** "Tutup Sesi" menu item click (L3772)

**Event Listeners:**
- L3772: Close session button → confirmation → POST

**IDs Used:**
```
pos-close-session-btn
pos-close-session-error (implied)
```

---

### 10. **Reprint & Success Screen** (Lines 4087–4218)
**Entry Point:** After successful checkout (L4120 callback)

**Functions:**
- `window.printReceipt()` (L4079)
- Receipt printing logic

**Event Listeners:**
- L4087: Shortcut reprint button
- L4127: Gratitude screen button
- L4178: Close/new transaction button

**IDs Used:**
```
pos-success-receipt, pos-success-change
pos-shortcut-reprint
gratitudeBtn, closeBtn
```

---

## Global State Variables (Scoped to IIFE)

```javascript
// Line 1483+
latestRequestId                 // debounce counter
latestCustomerRequestId         // customer search debounce
currentSnapshot                 // { cart, customer, totals, etc }
cachedPaymentMethods            // payment method lookup
checkoutPayments                // [ { id, method, amount, ref, errors } ]

// Line 1449+
pendingReduceLineId             // which line is being reduced
pendingReduceCurrentQty          // original qty
pendingReduceButton              // DOM ref for re-render

// Line 1454
clientPendingApprovals          // { lineId: { requestId, status, token } }
```

---

## API Endpoints (Cached, L1456–1467)

| Endpoint | Purpose | Method |
|----------|---------|--------|
| `searchEndpoint` | Product search | GET |
| `scanResolveEndpoint` | Barcode/SKU resolution | GET |
| `customerSearchEndpoint` | Customer lookup | GET |
| `cartShowEndpoint` | Load current cart | GET |
| `cartStoreLineEndpoint` | Add/update line | POST |
| `cartClearEndpoint` | Empty cart | POST |
| `cartCustomerEndpoint` | Set customer | PUT |
| `customerStoreEndpoint` | Create customer | POST |
| `paymentMethodSearchEndpoint` | Payment method lookup | GET |
| `finalizeEndpoint` | Finalize checkout | POST |
| `cartLinesBaseUrl` | Base for line operations | — |

---

## Problems with Current Structure

❌ **Discoverability Issues:**
- 60+ element IDs scattered throughout JS without clear grouping
- 33+ named functions spread across 2,800+ lines
- No clear separation between domains (search, cart, payment, approval)
- State mutations happen in many async callbacks

❌ **Maintenance Issues:**
- DOM selector mistakes are easy (wrong ID)
- Event listener registration is implicit (scattered throughout)
- Refactoring any feature requires hunting multiple locations
- Duplicate functions (e.g., `formatPrice` at L1553 AND L3620)

❌ **Testing Issues:**
- Can't unit test cart logic independently
- No clear module boundaries
- Global state (`currentSnapshot`, `checkoutPayments`) hard to mock

---

## Suggested Extraction Path (Low Risk)

### Phase 1: **Extract Utility Functions** (No HTML changes)
- Extract common utilities to `pos-utils.js`
  - `formatPrice()`, `escapeHtml()`, status setters, etc.
- External file, small refactor

### Phase 2: **Extract Payment Modules** (Partial HTML changes)
- Move single-stage checkout logic to `pos-checkout.js`
- Move approval logic to `pos-qty-approval.js`
- Reference by ID (keep HTML intact)

### Phase 3: **Extract Cart Logic** (Moderate HTML changes)
- Cart rendering and line mgmt → `pos-cart.js`
- Update cart HTML structure minimally
- Benefits: testable cart state

### Phase 4: **Extract Search & Customer** (Full restructure)
- These are more isolated — could become sub-views
- Less risk

---

## Using This Map

- **"How do I find X?"** → Search this doc
- **"Which functions touch Y?"** → Look up functional domain
- **"What breaks if I change ID Z?"** → Search IDs in domain table
- **"Where is state modified?"** → Check "State Modified" in each domain

---

