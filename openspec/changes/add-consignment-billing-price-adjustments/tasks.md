## 1. Pricing contract and calculation

- [ ] 1.1 Define stable conversion row IDs from the approved commercial groups and allocation IDs; expose original monetary evidence beside each editable row without allowing source identity or quantity changes.
- [ ] 1.2 Implement one server-side billing pricing calculator shared by preview and conversion for unit price, fixed-per-unit or percentage row discount, authoritative final row total, PKP tax selection/inclusion, fixed or percentage global discount, and exact currency rounding.
- [ ] 1.3 Preserve the legacy total for unchanged submissions, including PKP rows whose displayed gross unit price must reconcile to stored allocation DPP plus tax; reject invalid or unreconcilable values.

## 2. Conversion form and persistence

- [ ] 2.1 Add Purchase-style row price, discount, tax, and row-total controls plus document discount and live totals to the consignment billing conversion page; keep supplier, product, quantity, lot, and serial evidence read-only.
- [ ] 2.2 Apply active-setting PKP policy: default tax included and initial tax choices for PKP, and hide/forbid tax for non-PKP.
- [ ] 2.3 Validate pricing intent in preview and conversion requests, rebuild row identities from locked allocation evidence, reject stale/unknown/duplicate rows, and recalculate all submitted totals server-side.
- [ ] 2.4 Persist reviewed detail/header prices, discounts, selected tax, inclusion flag, tax totals, total and due amount during atomic conversion; retain original allocation and serialized lineage unchanged and record original-versus-final terms in the conversion audit.
- [ ] 2.5 Review consignment reconciliation and Purchase payment/report reads so they use persisted Purchase amounts for the payable while displaying original lineage as source evidence.

## 3. Focused verification

- [ ] 3.1 Add focused tests for non-PKP fixed/percentage row and global discounts, row-total back-solving, unchanged conversion, and confirmation #3's Rp50,280,000 example.
- [ ] 3.2 Add focused tests for PKP default tax included, valid tax selection, tax-exclusive toggle, gross-price rounding, and rejection of tax input for non-PKP.
- [ ] 3.3 Add focused conversion tests for persisted monetary fields and audit, unchanged source/stock evidence, stale or invalid intent, idempotent retry, and atomic rollback; run only the relevant Consignment/Purchase test filters.
