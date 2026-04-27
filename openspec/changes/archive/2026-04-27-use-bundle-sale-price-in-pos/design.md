## Context

Product Bundle configuration now has two runtime-relevant values: `product_bundles.bundle_sale_price` as the final customer-facing sale price for a selected bundle, and `product_bundle_items.informational_item_price` as component reference data. Standard Sales has already been changed to use the final bundle sale price on the parent row and keep bundle component rows non-billable.

POS still uses the older runtime model: selected bundled rows are priced as parent/tier price plus legacy `product_bundles.price`, and POS split posting treats bundle child rows primarily as stock-deduction context. This is insufficient for POS owner-split cases where the parent product and bundle components are stocked by different businesses. The POS customer should still see one bundled parent row, but the generated owner-specific Sales documents need revenue assigned to the correct source owner.

Current POS split posting already groups by source setting, source location, and tax bucket. It also resolves child stock allocations separately using `"{lineIndex}_C_{itemIndex}"` keys. The design should extend that allocation context for revenue planning without changing standard Sales runtime behavior.

## Goals / Non-Goals

**Goals:**
- Price selected POS bundled parent rows from `product_bundles.bundle_sale_price`.
- Keep POS UI behavior unchanged except for the displayed parent row price value.
- Keep bundle component prices hidden from customer-facing POS cart, checkout, receipt, and modal totals.
- Use bundle component informational prices only as internal POS split revenue allocation weights.
- Preserve `sale_bundle_items` as non-billable bundle composition context.
- Allocate POS-generated Sales document totals across parent and component owners with exact checkout reconciliation.
- Calculate PKP included tax from allocated POS bundle revenue using the parent/default tax context and each source owner's PKP policy.
- Keep normal POS customer tier repricing for non-bundled rows while bypassing it for selected bundled rows.

**Non-Goals:**
- Do not change standard Sales create, update, show, print, normalizer, or dispatch behavior.
- Do not display component informational prices in POS UI as billable values.
- Do not make bundle component rows independently editable in POS.
- Do not implement the guard that prevents total informational item prices from exceeding bundle sale price; that will be handled separately.
- Do not implement return/refund changes for split bundle revenue.
- Do not introduce discount behavior for bundle allocation; current POS discount scope remains unchanged.

## Decisions

### 1. POS bundled rows use final bundle sale price as the visible billable price

When a cashier selects a bundle, POS shall initialize the parent cart row unit price from `product_bundles.bundle_sale_price`. POS should not add legacy `product_bundles.price` to the product price, and customer tier repricing should not replace selected bundled row prices. The row remains a single customer-facing POS line.

Rationale: `bundle_sale_price` is the final configured selling price for the bundle. Reusing tier price plus legacy add-on would preserve the ambiguity that the Product Bundle and Sales changes removed.

Alternative considered: Keep POS on legacy add-on pricing and only change split posting. Rejected because POS totals would still disagree with configured bundle sale prices.

### 2. Bundle component informational prices are allocation weights, not customer-facing prices

POS shall continue to keep bundle components as line metadata and `sale_bundle_items` context. Informational prices may be carried in internal snapshot/planning structures, but they must not be displayed as billable POS component prices and must not be persisted as billable `sale_bundle_items.price` or `sale_bundle_items.sub_total` values.

Rationale: The customer bought one bundle row. Component amounts exist to allocate revenue among source owners, not to create visible line-item charges.

Alternative considered: Persist informational prices directly into `sale_bundle_items`. Rejected because existing downstream code can interpret those fields as component monetary values, increasing double-counting risk.

### 3. Split posting decomposes bundled revenue into allocation parts

For each selected bundled POS cart line, split planning should derive internal allocation parts:

```text
component allocation = informational_item_price or fallback product sale price
parent residual = bundle_sale_price - sum(component allocations)
```

Each stock-managed component allocation part follows that component's resolved stock source owner. The parent residual follows the parent product's resolved source owner. If a component and parent share the same owner/tax group, their allocation amounts naturally combine into the same generated Sales document total.

Rationale: This matches the expected examples: when component stock belongs to another business, that business receives the component allocation amount; when it belongs to the parent owner, the parent owner's sale total includes both parent residual and component allocation.

Alternative considered: Assign all bundle revenue to the parent owner and only deduct child stock from child owners. Rejected because it creates owner-specific Sales totals that do not match stock ownership economics.

### 4. Missing component informational prices fall back to active-setting product sale price

If a bundle component has no `informational_item_price`, POS allocation should fall back to that component product's active-setting `product_prices.sale_price`. It should not fall back to legacy `product_bundle_items.price`.

Rationale: The Product Bundle configuration defaulted informational prices from active-setting sale prices. The fallback keeps allocation useful for older or partially configured data without reviving legacy pricing semantics.

Alternative considered: Treat missing informational prices as zero. Rejected because it silently shifts all missing component revenue into the parent residual and can distort split owner totals.

### 5. PKP tax uses parent/default tax context and source owner policy

POS bundle allocation tax should use a deterministic tax candidate:

```text
tax_id = parent cart line tax_id
else active/default sale tax for the POS setting
else no tax
```

Tax extraction remains source-owner gated:

```text
source owner is PKP and tax_id exists -> extract included tax from allocated gross amount
source owner is non-PKP or no tax_id  -> tax amount 0
```

Rationale: POS PKP prices are tax-included, and the user wants bundle tax based on parent/global default tax rather than component-specific tax.

Alternative considered: Use each component product's own tax. Rejected because it would make one bundle row split across component-specific tax rules and diverge from the requested parent/global tax policy.

### 6. Stockless component revenue uses first configured non-PKP sales-location source

For non-stock-managed bundle components, there is no stock allocation to identify an owner. POS split planning should allocate that component revenue to the first configured sales-location source whose setting/business is non-PKP, using the same effective ordering as existing sales-location resolution. If no non-PKP configured source exists, checkout/preflight should fail with an actionable validation error.

Rationale: This keeps stockless component revenue out of PKP owners when no stock evidence exists and grounds the choice in existing sales-location configuration.

Alternative considered: Assign stockless components to the terminal/POS setting. Rejected because it ignores the requested non-PKP ownership rule.

### 7. Exact reconciliation is mandatory

Bundle decomposition and split grouping must use minor-unit arithmetic. The sum of all split group gross totals must equal the POS checkout grand total exactly. The sum of owner-specific Sales totals must equal the customer-facing POS total, even after quantity multiplication and rounding.

Rationale: POS checkout, split summaries, Sales documents, and payment allocation records must reconcile exactly for reports and idempotent replay.

Alternative considered: Allow small rounding drift and adjust at header level. Rejected because split posting already has exact reconciliation guarantees.

## Risks / Trade-offs

- [Informational item total exceeds bundle sale price] -> This proposal assumes a separate guard will prevent the configuration. Implementation should still avoid silently posting negative parent residuals; preflight can fail if encountered.
- [Internal allocation fields leak into POS UI] -> Keep allocation fields internal to service/planner structures or explicitly mark them as non-display metadata.
- [Standard Sales behavior changes accidentally] -> Limit code changes to POS services/adapters and POS tests; avoid touching Sales Livewire/controller runtime paths.
- [Stockless source selection is ambiguous] -> Use existing sales-location configuration ordering and fail when no non-PKP source exists.
- [Tax totals differ between cart estimate and posted split documents] -> Treat cart tax as display estimate and derive authoritative posted tax from source owner PKP policy over allocated gross amounts.
- [Fallback product price is missing] -> Treat missing fallback as zero only if existing Product Bundle configuration permits it, or fail preflight with a clear allocation-price error if reconciliation would be ambiguous.

## Migration Plan

No database migration is required. The Product Bundle price columns already exist.

Deployment is a code-only change. Existing carts created before deployment may contain legacy bundle prices in session state; implementation should either preserve those carts until mutation or rehydrate selected bundled line prices from `bundle_sale_price` when rebuilding snapshots, depending on existing POS session expectations.

Rollback is a code rollback: POS would return to legacy bundle add-on pricing and existing split posting behavior without schema rollback.

## Open Questions

- Should pre-existing open POS carts be forcibly repriced on next snapshot, or only when the bundle line is newly added or customer selection mutates the cart?
- If fallback component active-setting sale price is missing, should checkout fail or allocate zero? The safer implementation choice is fail when the missing value would affect split ownership totals.
