## Context

Sales and Purchase calculate editable cart rows in Livewire and normalize them again before persistence. POS instead uses `PosCartTotalsCalculator` as its minor-unit authority and carries calculated rows through drafts, payment validation, checkout snapshots, owner splitting, generated Sales, and receipts. All three flows support exact manual pricing, while POS additionally supports packed pricing and fixed internal bundle-component allocations.

The change is per business and applies only when a user interaction calculates an automatically priced, customer-facing row. Tax is part of the rounding base. Document-level adjustments and internal allocations remain outside the rounding operation.

## Goals / Non-Goals

**Goals:**

- Provide one deterministic half-up increment-rounding rule shared by Sales, Purchase, and POS.
- Preserve exact manual unit-price and line-total authority.
- Make rounded tax-inclusive row totals reconcile exactly with stored pre-tax and tax values.
- Preserve bundle component prices while assigning the rounding difference to the parent residual settlement.
- Keep UI calculation, persistence, checkout, payment, and reporting values aligned.

**Non-Goals:**

- Rounding document grand totals, global discounts, shipping, returns, owner fragments, stock allocations, or internal bundle components.
- Revaluing historical documents or changing existing return-allocation conventions.
- Replacing the application's broader decimal-money conventions or introducing an external money library.
- Running unrelated full-suite verification as part of this change.

## Decisions

### Store the increment on each business setting

Add a non-negative decimal setting such as `row_total_rounding_increment`, defaulted to `100.00` for new and existing rows. Zero disables rounding. The effective document/POS setting is resolved once and passed to calculators rather than repeatedly reading session state.

Alternative considered: a global environment configuration. This was rejected because pricing and tax behavior are already business-scoped and transactions may be created or moved across business settings.

### Use a shared minor-unit rounding primitive

Introduce a small shared service/value calculator that accepts an amount and increment in minor units and returns the half-up nearest multiple. Integer quotient/remainder logic avoids binary floating-point boundary errors and is reusable from PHP normalizers and POS. UI values remain two-decimal presentation values, while authoritative backend submission repeats the rule.

Alternative considered: calling PHP `round($amount / $increment)`. This is simpler but makes exact midpoint and decimal-increment behavior depend on floating-point representation.

### Round after tax and then allocate tax backward

For eligible automatic rows, calculate the existing raw final amount including tax, round that amount, then use the existing authoritative-total tax-allocation pattern to derive pre-tax subtotal and tax. The final invariant is `pre-tax subtotal + tax = rounded row total` to two decimals for included and excluded input modes.

Alternative considered: rounding the pre-tax base or tax separately. This was rejected because it can produce a customer-facing total different from the requested rounded value.

### Make pricing authority explicit and durable

Only automatic pricing sources are eligible. Sales already persists automatic/manual source distinctions; POS has explicit base, packed, and override sources. Purchase must gain equivalent durable pricing-source metadata, with pre-change/legacy details treated as manual to prevent silent repricing. A manual unit price remains authoritative during later quantity, discount, and tax changes; a manual total remains exact for its commit and follows the module's established later-change semantics.

Alternative considered: detecting manual entry by comparing values with catalog prices. This was rejected because identical numeric values can still be deliberate manual commits and catalog prices can change.

### Treat loading and calculation as separate events

Create/edit hydration preserves stored monetary values and pricing source without applying rounding. Eligible add, quantity, row-discount, tax, automatic tier/customer, automatic bundle, and automatic packed repricing actions invoke rounding. Backend normalizers validate/reproduce eligible interaction results so UI-only state cannot bypass the rule.

### Round visible bundle rows but preserve component allocations

Sales bundle component informational values remain non-billable and unchanged. POS calculates the rounded customer-facing bundle row once, retains all captured component allocations, and sets parent residual to `rounded row total - sum(component allocations)`. Existing negative-residual validation remains authoritative. Owner splitting allocates the already-rounded amount without rerounding fragments.

Alternative considered: distributing the rounding delta across components. This was rejected because component allocations are immutable transaction snapshots used for settlement and returns.

### Keep document-level arithmetic outside the rounding operation

Grand totals sum authoritative row values and then apply existing global discount and shipping rules. The resulting grand total may not be a multiple of the configured increment. POS payments, receipts, and generated Sales consume that unrounded-again grand total.

## Risks / Trade-offs

- [UI and backend calculate different row values] → Route both through equivalent minor-unit rules and assert parity in focused feature tests.
- [Legacy Purchase rows lack pricing authority] → Add durable source metadata with a conservative legacy-manual default and hydrate without mutation.
- [Rounded tax changes DPP/tax by a small amount] → Make the rounded inclusive total authoritative and deterministically assign the two-decimal remainder so tax reconciles.
- [POS bundle rounding creates a negative parent residual] → Preserve existing preflight/finalize rejection with an actionable error.
- [Split-owner allocation drifts] → Allocate the authoritative rounded row in minor units and assign deterministic remainder without rerounding fragments.
- [Changing a business setting unexpectedly alters open work] → Never round on load; apply the current effective setting only on a later eligible automatic interaction.
- [Very small positive rows can round to zero] → Preserve mathematical half-up behavior and cover the boundary explicitly during implementation; business users can choose a smaller increment or disable rounding.

## Migration Plan

1. Add the business setting with a database default of `100.00`, update model/factory defaults, validation, and Business Configuration UI.
2. Add any required durable Purchase pricing-source column with a conservative legacy default.
3. Introduce the shared minor-unit rounding service and integrate focused Sales, Purchase, and POS automatic calculation paths.
4. Update tax allocation, bundle residual, snapshots, persistence, checkout, and receipt consumers to use authoritative results.
5. Deploy additively; do not rewrite historical transaction amounts.

Rollback removes application use first. Schema rollback may drop only newly added configuration/metadata columns; transaction amounts remain as originally persisted and are not reverse-recalculated.

## Open Questions

None blocking. The agreed defaults are a `100.00` increment, zero as disabled, half-up midpoint behavior, tax-inclusive row rounding, exact manual-price bypass, unchanged bundle-component allocations, and no grand-total rounding.
