# POS Sell Redesign Brainstorm - Decision Questions

Date: 2026-03-07

## Current Codebase Snapshot (why these questions matter)

- POS sell UI is a single Blade + inline JS file: `Modules/Pos/Resources/views/sell.blade.php`.
- Search currently supports inline suggestions and barcode auto-add (`auto_select_product_id`) but no Enter-result modal and no serial-direct scan flow.
- Cart model is product-aggregated (`line_id = product_id`) with editable `qty` in `PosCartService`.
- Serial validation exists at checkout (`FinalizePosCheckoutService`) and via `cartAssignSerials`, but UI does not expose serial assignment flow.
- Customer selection currently does not reprice cart totals (existing feature test asserts totals stay the same).
- Payment methods are currently hardcoded in POS (`cash/transfer/qris`) even though `payment_methods` table exists.
- Stock allocation already respects configured location order, but for taxed lines it reads taxed bucket first (`quantity_tax`) and not non-tax first.

## Section 1 - Search Bar / Scanner Flow

### Q1. What should happen on `Enter` in product search?
- Option A: Always open a search results modal.
- Option B: If exact barcode/serial match exists, auto-add immediately; otherwise open modal.
- Option C: Only auto-add exact barcode; never open modal.
- Recommendation: **Option B** (fast for scanner, still supports manual keyboard search).

### Q2. Should serial number be resolvable directly from the same search input?
- Option A: No, serial can only be assigned after product already in cart.
- Option B: Yes, exact serial scan should auto-add product and bind serial in one action.
- Recommendation: **Option B** (matches your scanner requirement and removes extra steps).

### Q3. If scanned code is ambiguous (multiple products), what should scanner flow do?
- Option A: Open modal and require cashier selection.
- Option B: Reject and show error to rescan.
- Recommendation: **Option A** (safer, less silent failure risk).

### Q4. Remove `Siap Pindai` button entirely?
- Option A: Remove and rely on always-focused search input + clear status hints.
- Option B: Keep as optional focus helper.
- Recommendation: **Option A** (cleaner and aligned with request).

## Section 2 - Cart Model / Quantity / Serial

### Q5. Cart pattern for serial-required products?
- Option A: Keep one serial-product row with editable qty; assigned serial count must match qty.
- Option B: Force unit-per-scan rows (`qty = 1`) for serial products.
- Recommendation: **Option A** (aligned with your flow: qty can be increased and serials can be filled incrementally via inline add controls).

### Q6. If same non-serial product is scanned repeatedly, should rows merge?
- Option A: Increment existing row qty (visible qty), or append only when row does not exist.
- Option B: Keep separate rows per scan.
- Recommendation: **Option A** (aligned with your scanner behavior for non-serial items).

### Q7. For serial-required products selected manually (click suggestion), what should happen?
- Option A: Add/increment serial row with qty support and allow serial filling afterward (scan or inline add serial action).
- Option B: Block add unless serial was scanned first.
- Recommendation: **Option A** (aligned with Q5).

### Q8. Duplicate serial scan behavior?
- Option A: Reject duplicate serial globally in current cart.
- Option B: Allow duplicate scan then validate at checkout.
- Recommendation: **Option A** (fail fast, lower cashier error rate).

## Section 3 - Pelanggan + Tier Pricing

### Q9. When customer changes, should existing cart lines be repriced?
- Option A: Reprice all non-overridden lines immediately.
- Option B: Apply tier price only to future scans.
- Recommendation: **Option A** (matches requirement “price automatically adjusted when Pelanggan updated”).

### Q10. Tier mapping for POS pricing source?
- Option A: `WHOLESALER -> tier_1_price`, `RESELLER -> tier_2_price`, else `sale_price` from `product_prices` by active setting.
- Option B: Always `sale_price` regardless of tier.
- Recommendation: **Option A**.

### Q11. If active setting has no `product_prices` row, fallback strategy?
- Option A: Fallback to `products.product_price`.
- Option B: Block add with config error (strict active-setting pricing).
- Recommendation: **Option B** (matches your “not product owner price” requirement).

### Q12. How should pricing behave with no selected customer?
- Option A: No default walk-in fallback; use normal/base pricing from active setting for non-tier or no-customer state.
- Option B: Force customer selection before any price can be resolved.
- Recommendation: **Option A** (aligned with your direction to remove default walk-in behavior while keeping default/base price resolution).

## Section 4 - Payment Methods

### Q13. Checkout payload should use what identifier?
- Option A: Keep `payment.method_code` string.
- Option B: Use `payment_method_id` from `payment_methods` table (derive cash/non-cash behavior from table attributes).
- Recommendation: **Option B** (future-proof and removes fragile name matching).

### Q14. Which payment methods are shown in POS?
- Option A: All methods.
- Option B: Only methods flagged `is_available_in_pos = true`.
- Recommendation: **Option B**.

### Q15. How to handle non-cash reference requirement for dynamic methods?
- Option A: Add/use a per-method flag (e.g. `requires_reference`).
- Option B: Infer from method name.
- Recommendation: **Option A** (explicit and reliable).

### Q16. UI component for method picker?
- Option A: Searchable dropdown in payment modal.
- Option B: Keep static segmented buttons.
- Recommendation: **Option A** (matches requirement and scales beyond 3 methods).

## Section 5 - Confirm Payment Stock Deduction Rules

### Q17. Clarify “for taxed product prioritize non-tax stock”: apply this exact sequence?
- Option A: For taxable line, allocate non-tax bucket first, then tax bucket, while still honoring location priority.
- Option B: Keep current tax bucket first.
- Recommendation: **Option A**, scoped to **non-serial products only** (as you specified).

### Q18. Priority ordering dimension when both location and bucket priorities exist?
- Option A: Location priority first; inside each location use non-tax then tax.
- Option B: Bucket priority globally first, then location.
- Recommendation: **Option B** (aligned with your direction).

### Q19. For serial-number items, should bucket be inferred from serial record (`tax_id`) regardless of product tax config?
- Option A: Yes, serial record is source of truth.
- Option B: Force product tax config and ignore serial tax state.
- Recommendation: **Option A**.

### Q20. If taxable line is fully fulfilled from non-tax stock, should tax become 0 for that fulfilled quantity?
- Option A: Yes, actual tax follows allocated source bucket.
- Option B: No, still charge tax based on product config regardless of source bucket.
- Recommendation: **Option A** (consistent with existing source-based tax snapshot model).

## Section 6 - Rollout / Safety

### Q21. Delivery strategy?
- Option A: Big-bang refactor.
- Option B: Phased rollout with compatibility layer (scanner/search, cart model, pricing, payment, allocation).
- Recommendation: **Option B** (safer with current extensive POS feature tests).

### Q22. Backward API compatibility for current endpoints (`qty`, `method_code`)?
- Option A: Hard switch immediately.
- Option B: Transitional support for old + new payloads, then cleanup.
- Recommendation: **Option B**.

---

## Proposed Next Step

After you choose options for Q1-Q22, I will turn this into a concrete implementation plan with:

- exact backend/frontend file diffs to make,
- migration updates (if needed),
- test updates/additions per module,
- rollout and rollback checklist.

---

## Round 2 Decision Lock (from stakeholder)

- `Q1` = B
- `Q2` = B
- `Q3` = A
- `Q4` = A
- `Q5` = A (serial row with editable qty + incremental serial fill)
- `Q6` = A (non-serial barcode scan increments existing row qty)
- `Q7` = A
- `Q8` = A
- `Q9` = A
- `Q10` = A
- `Q11` = B
- `Q12` = A (no default walk-in; base pricing for no-customer/non-tier)
- `Q13` = B
- `Q14` = B
- `Q15` = A
- `Q16` = A
- `Q17` = A (non-serial only)
- `Q18` = B
- `Q19` = A
- `Q20` = A
- `Q21` = B
- `Q22` = B

## Additional Clarification Questions (Round 2)

### CQ1. If serial product qty is reduced below assigned serial count, what should happen?
- Option A: Block reduction and ask cashier to remove serial(s) first.
- Option B: Auto-remove latest assigned serial(s) until counts match.
- Recommendation: **Option A** (safer and explicit).

### CQ2. For the inline `+ serial` action on a serial row, should it also increase qty?
- Option A: Yes, pressing `+ serial` increments qty by 1 and immediately opens serial input for the new slot.
- Option B: No, it only opens input; qty must be changed separately.
- Recommendation: **Option A** (single action, less mismatch risk).

### CQ3. When scanning a serial and its product row already exists, how should assignment work?
- Option A: Fill existing unfilled serial slot first; if none exist, auto-increment qty and append serial.
- Option B: Always create a new separate row.
- Recommendation: **Option A** (keeps one serial-product row manageable).

### CQ4. For serial scans, should line tax follow serial `tax_id` even if different from line expectation?
- Option A: Yes, serial record is source of truth; line/chunk tax adapts to scanned serial.
- Option B: No, reject scan on mismatch.
- Recommendation: **Option A** (consistent with serial-as-source-of-truth model).

### CQ5. Non-serial row merge key should be?
- Option A: Merge by `product_id` only.
- Option B: Merge by `product_id + effective_unit_price + tax_mode/tax_id`.
- Recommendation: **Option B** (prevents mixing different pricing/tax contexts).

### CQ6. With no default walk-in, should checkout be allowed without selected customer?
- Option A: Yes, allow checkout with no customer.
- Option B: No, customer must be selected before checkout.
- Recommendation: **Option B** (least data-model risk with current sales schema).

### CQ7. If CQ6 = B and customer is missing at checkout click, what UX should happen?
- Option A: Show blocking inline error only.
- Option B: Show error and auto-focus/open customer selection flow.
- Recommendation: **Option B** (faster cashier recovery).

### CQ8. If customer tier changes and a product has no active-setting price row (Q11 strict mode), what to do for existing cart lines?
- Option A: Keep prior line price but show warning.
- Option B: Mark line invalid and block checkout until repriced/rescanned.
- Recommendation: **Option B** (keeps strict active-setting pricing consistent).

### CQ9. For global bucket-first allocation (`Q18 = B`), inner order should be?
- Option A: Non-tax bucket across configured locations in priority order, then tax bucket across same priority order.
- Option B: Within each bucket, pick location with largest stock first.
- Recommendation: **Option A** (deterministic and auditable).

### CQ10. Which flows should global bucket-first apply to?
- Option A: Non-serial allocation only; serial allocation always follows scanned serial location/tax bucket.
- Option B: Both non-serial and serial flows.
- Recommendation: **Option A** (aligned with your Q17 scope rule).

## Round 3 Decision Lock (Clarifications)

- `CQ1` = A
- `CQ2` = A
- `CQ3` = A
- `CQ4` = A
- `CQ5` = B
- `CQ6` = B
- `CQ7` = B
- `CQ8` = B
- `CQ9` = A
- `CQ10` = A

## Open Questions

None. Requirements are now sufficiently locked for final implementation planning.
