# Phase 5 UAT Checklist — POS Scan vs. Modal Search Separation

**Date**: March 9, 2026  
**Objective**: Verify Phase 1-4 implementation (scanner-only input, dedicated modal search, SKU removal)  
**Prerequisites**: POS terminal active, hardware scanner available (or manual test), cashier session open

---

## UAT Environment Setup

1. Open POS system at cashier terminal
2. Open a new POS session (Settings → Location → Open Session)
3. Add a test customer (or use walk-in)
4. Verify scanner field is empty and ready

---

## Scanner Input Tests (Hardware Scanner or Manual Typing)

| # | Test Case | Input Method | Expected Result | Status |
|---|-----------|--------------|-----------------|--------|
| **S1** | Scan product barcode | Hardware scanner (or type + Enter) | Product added to cart with qty=1 | ☐ Pass ☐ Fail |
| **S2** | Scan barcode in lowercase | Hardware scanner outputs `abc-123` | Product found (case-insensitive) | ☐ Pass ☐ Fail |
| **S3** | Scan conversion barcode | Hardware scanner (conversion/package code) | Product added with conversion unit (e.g., "Pack of 12") | ☐ Pass ☐ Fail |
| **S4** | Scan serial number | Hardware scanner (or type serial + Enter) | Serial added to product line; if new product, product added first | ☐ Pass ☐ Fail |
| **S5** | Type SKU in scanner field + Enter | Manual type: `SKU-ABC-001` | **Status shows "not found"** — no action, no modal open | ☐ Pass ☐ Fail |
| **S6** | Type nonexistent code + Enter | Manual type: `XXXXXX-INVALID` | **Status shows "not found"** — no action, no modal open | ☐ Pass ☐ Fail |
| **S7** | Scan product without stock | Hardware: code for out-of-stock item | Status shows "not found" (stock guard) | ☐ Pass ☐ Fail |
| **S8** | Scan product without price | Hardware: code for unpriced item | Status shows "not found" (price guard) | ☐ Pass ☐ Fail |

---

## Modal Search Tests (Cari Produk Button)

| # | Test Case | Action | Expected Result | Status |
|---|-----------|--------|-----------------|--------|
| **M1** | Click "Cari Produk" button | Click directly | Modal opens with empty search field; focus on search input | ☐ Pass ☐ Fail |
| **M2** | Search by product name | Type `"Kopi"` in modal search + Click "Cari" button | Results show all products with "Kopi" in name as card grid | ☐ Pass ☐ Fail |
| **M3** | Search by SKU | Type `"SKU-ABC-001"` in modal search + Click "Cari" button | Result shows matching product; **auto_select NOT triggered** | ☐ Pass ☐ Fail |
| **M4** | Search by exact barcode in modal | Type barcode in modal search + Press Enter | Result shows matching product; **auto_select NOT triggered** (requires click) | ☐ Pass ☐ Fail |
| **M5** | Click product card in modal | Click result card/row | Product added to cart, modal closes, scanner field refocused | ☐ Pass ☐ Fail |
| **M6** | Add quantity in modal (if UI allows) | Modify qty before clicking | Added to cart with specified qty | ☐ Pass ☐ Fail |
| **M7** | Close modal without selection | Click X or outside modal | Modal closes, cart unchanged, scanner field refocused | ☐ Pass ☐ Fail |
| **M8** | Search with no results | Type `"ZZZZZZ-NO-MATCH"` in modal + Cari | "No products found" message or empty result list | ☐ Pass ☐ Fail |

---

## Integration Tests (Scanner + Modal Together)

| # | Test Case | Action | Expected Result | Status |
|---|-----------|--------|-----------------|--------|
| **I1** | Scan, then use modal | Scan 1 product (added to cart), click Cari Produk, select another | Both products in cart | ☐ Pass ☐ Fail |
| **I2** | Modal search doesnt interfere with scanner | Search in modal (don't select), close modal, scan barcode | Scanned product added correctly | ☐ Pass ☐ Fail |
| **I3** | Auto-select in scanner field | Scan exact barcode → product auto-adds | Cart shows product, ready for next scan | ☐ Pass ☐ Fail |
| **I4** | No auto-select in modal for SKU | Modal search SKU, see result with **no auto-select** (meta.auto_select_product_id=null) | User must click card to add (click-to-add contract) | ☐ Pass ☐ Fail |

---

## Response Format Validation (Dev/Technical)

*If HTTP inspection is available (browser DevTools, Postman, etc.):*

| # | Test Case | Endpoint | Expected Response Fields | Status |
|---|-----------|----------|-------------------------|--------|
| **R1** | Barcode scan response | GET `/pos/sell/search/resolve?q=<barcode>` | `type=product_exact`, `product.resolved_via=product_barcode`, `product.conversion=null` | ☐ Pass ☐ Fail |
| **R2** | Conversion barcode response | GET `/pos/sell/search/resolve?q=<conv_barcode>` | `type=product_exact`, `product.resolved_via=conversion_barcode`, `product.conversion.id`, `product.conversion.conversion_factor` | ☐ Pass ☐ Fail |
| **R3** | Serial scan response | GET `/pos/sell/search/resolve?q=<serial>` | `type=serial_exact`, `serial.serial_number`, `serial.product_id`, `serial.location_id` | ☐ Pass ☐ Fail |
| **R4** | SKU scan returns none | GET `/pos/sell/search/resolve?q=<sku>` | `type=none` (NOT product_exact) | ☐ Pass ☐ Fail |
| **R5** | Modal search SKU response | GET `/pos/sell/products/search?q=<sku>` | `results` array with `matched_by=sku_exact`, `meta.auto_select_product_id=null` | ☐ Pass ☐ Fail |
| **R6** | Modal search barcode response | GET `/pos/sell/products/search?q=<barcode>` | `meta.auto_select_product_id=<product_id>` (auto-select enabled for barcode in search too, but frontend click-to-add in modal) | ☐ Pass ☐ Fail |

---

## Edge Cases & Status Messages

| # | Test Case | Action | Expected Behavior | Status |
|---|-----------|--------|------------------|--------|
| **E1** | Empty scanner input | Press Enter with empty field | No action, scanner field stays empty | ☐ Pass ☐ Fail |
| **E2** | Scanner field shows feedback | Scan valid barcode | Scanner field shows "✓ Added: [Product Name]" or similar status | ☐ Pass ☐ Fail |
| **E3** | Scanner field shows error | Scan invalid/not-found code | Scanner field shows "✗ Not found" or "Code not found" | ☐ Pass ☐ Fail |
| **E4** | Rapid sequential scans | Hardware scanner: 3x fast scans | All 3 products added; no race condition/duplicate entries | ☐ Pass ☐ Fail |
| **E5** | Typo in manual barcode entry | Type barcode with slight typo + Enter | "Code not found" message (no fuzzy match in scanner) | ☐ Pass ☐ Fail |
| **E6** | Fuzzy match in modal works | Modal search with typo: `"Kop` (missing 'i') | Results show "Kopi" products (fuzzy highlighting) | ☐ Pass ☐ Fail |

---

## Regression Checks (Existing POS Functionality)

| # | Feature | Test Action | Expected Result | Status |
|---|---------|------------|-----------------|--------|
| **REG1** | Add to cart quantity | Scan product, update qty to 5 in cart line | Cart line shows qty=5, totals updated | ☐ Pass ☐ Fail |
| **REG2** | Serial assignment (for serial-required products) | Scan serial-required product, add serial to line | Serial field shows serial numbers, qty/serial validation works | ☐ Pass ☐ Fail |
| **REG3** | Checkout finalization | Complete cart → click Checkout → pay | Transaction saved, receipt printed/shown | ☐ Pass ☐ Fail |
| **REG4** | Multi-item cart | Scan 5 different products, add via modal, etc. | Cart shows all items, totals correct | ☐ Pass ☐ Fail |
| **REG5** | Promotions/Discounts | Apply discount to cart | Original and discounted prices shown correctly | ☐ Pass ☐ Fail |

---

## Accessibility & UI/UX Checks

| # | Test Case | Platform | Expected Behavior | Status |
|---|-----------|----------|-------------------|--------|
| **A1** | Mobile/touchscreen (if applicable) | Tap "Cari Produk" button on mobile | Modal opens, search field has good touch target | ☐ Pass ☐ Fail |
| **A2** | Scanner field labeling | Visual/screen reader | Label clearly says "Scanner Input" or "Scan Barcode (or Serial/Conversion)" | ☐ Pass ☐ Fail |
| **A3** | Modal header | Visual | Modal clearly labeled "Cari Produk" or "Search Products" | ☐ Pass ☐ Fail |
| **A4** | Result cards (modal grid) | Visual | Product cards show: Name, SKU, Barcode, Stock, Price — easily distinguishable | ☐ Pass ☐ Fail |
| **A5** | Keyboard navigation | Keyboard: Tab through scanner field → Cari button → modal search → results | Focus highlights move logically; Enter key triggers actions | ☐ Pass ☐ Fail |

---

## Sign-Off

| Role | Name | Date | Signature/Notes |
|------|------|------|-----------------|
| QA Lead | _________________________ | __________ | __________________ |
| Cashier/User | _________________________ | __________ | __________________ |
| Development | _________________________ | __________ | __________________ |

---

## Notes & Issues Found

```
[Document any issues, quirks, or observations during UAT]

Issue #1:
- Description:
- Impact:
- Resolution:

Issue #2:
- Description:
- Impact:
- Resolution:
```

---

## Go-Live Readiness

- [ ] All mandatory tests (S1-S8, M1-M8, I1-I4) passed
- [ ] No critical regressions in checkout/cart/serial functionality
- [ ] Cashiers trained on scanner-only vs. modal-search split
- [ ] Documentation updated and distributed
- [ ] Hardware scanner tested if available
- [ ] Support/escalation process communicated

**UAT Status**: ☐ **READY FOR PRODUCTION** | ☐ **NEEDS FIXES** | ☐ **ON HOLD**

---

## Rollback Plan (if needed)

1. Revert Phases 1-4 database migrations (if any schema changes)
2. Restore previous POS sell blade template
3. Restore previous JavaScript behavior (scanner + keyword search combined)
4. Restore PosScanResolverService to include SKU matching
5. Communicate to cashiers via SOP update

---

## FAQ for Cashiers

**Q: Why can't I search by SKU in the scanner field anymore?**  
A: The scanner field is now locked to barcode/serial/conversion barcode only for speed and accuracy. To search by SKU, use the "Cari Produk" button for flexible keyword search.

**Q: What if my scanner outputs a barcode and nothing happens?**  
A: Check the product's barcode is correctly entered in the system, or the product has stock and a price set for this location.

**Q: Can I still type barcodes manually if my scanner breaks?**  
A: Yes! The scanner field accepts manual input (type barcode + Enter), just like before.

**Q: What's the "Cari Produk" button for?**  
A: Click it to open a search modal where you can find products by name, SKU, or barcode using keywords. Results appear as clickable cards.

**Q: Do I need to click OK or press Enter after searching in the modal?**  
A: Click the product card to add it to cart, or press Enter after typing to search. The "Cari" button works too.
