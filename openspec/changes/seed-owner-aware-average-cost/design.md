## Context

`product:seed-average-cost-from-sales-hpp` derives current average costs from authoritative imported sales HPP snapshots. Its current bucket logic selects a latest HPP candidate per special company or Perdana/global bucket and writes target settings without distinguishing an uninitialized row from a business with an established positive owner-specific cost. It also creates a missing `product_prices` row only when both an HPP candidate and a literal purchase candidate exist, even though average-cost seeding does not require a purchase source.

The system stores one `product_prices` row per product × setting. `average_purchase_price` is the setting-scoped current cost. The related purchase importer and `last_purchase_price` behavior are intentionally outside this change.

## Goals / Non-Goals

**Goals:**

- Ensure every target business with an available HPP baseline receives a non-zero average purchase price, including when its price row is absent.
- Use a deterministic shared baseline priority: Perdana, then CV Top IT Internusa, then CV Tiga Nusa Computer.
- Preserve positive average costs in non-source businesses.
- Apply the latest eligible Top IT and Tiga Nusa HPP only to their respective businesses.
- Preserve dry-run safety and make repeated writes idempotent.

**Non-Goals:**

- Changing purchase import behavior, `last_purchase_price`, or the shared legacy `products.purchase_price` column.
- Calculating a moving average from purchase transactions.
- Creating a synthetic cost for products without any eligible imported HPP source.
- Changing sale HPP snapshot import data or historical sale details.

## Decisions

### Treat zero or null as uninitialized per target setting

For each eligible product, the command will inspect every setting's individual `product_prices` row. A missing row or one with null/zero `average_purchase_price` is eligible for baseline filling; a positive value is preserved unless the row belongs to a special-company owner receiving its own latest HPP.

This per-row rule ensures reruns repair gaps for newly added businesses and fulfills cross-business coverage without allowing one business's later cost to overwrite another's established cost. A one-time “all settings are zero” bootstrap was rejected because it leaves later-added or previously missing businesses at zero.

### Resolve the three sales-import source owners in explicit priority order

For every stock-managed product, the command will resolve the latest eligible HPP separately for the three sales-import source owners, in this explicit order: Perdana, Top IT, then Tiga Nusa. Each owner lookup retains existing deterministic ordering: sale date descending, sale ID descending, then sale-detail ID descending. The baseline selects the first available source in that fixed order.

After filling uninitialized rows from the baseline, the command will overlay Top IT's latest source only onto Top IT rows and Tiga Nusa's latest source only onto Tiga Nusa rows. This lets a special company have a current owner-specific cost while all businesses still receive a viable initial value.

Querying or selecting an arbitrary generic/REST setting as a peer source was rejected because sales import is split only between these three owners and the agreed fallback order must not depend on arbitrary setting-row order.

### Create missing product-price rows using existing normalization conventions

When an eligible baseline exists but a target setting has no row, create a `product_prices` row with the seeded average. Copy available same-product selling/tier/tax metadata from an existing row; if none exists, use zero sale/tier values and null tax IDs. Do not require a purchase candidate.

### Retain command modes and unresolved reporting

Dry-run calculates and reports would-be create/update/unchanged/unresolved results without writing. `--write` makes only the planned average-cost changes. Products with no eligible baseline remain unchanged and are counted/reported as unresolved rather than receiving an invented cost.

## Risks / Trade-offs

- [A product has no eligible imported HPP in any prioritized source] → Leave its average costs unchanged and report it as unresolved for operator follow-up.
- [A positive value is stale but belongs to a non-source setting] → Preserve it by design; this change prioritizes owner isolation over cross-business replacement. Operators can correct such values through an explicit future workflow.
- [A missing row has no same-product metadata template] → Create with safe zero/null non-cost defaults and the validated average cost.
- [Large catalogs] → Keep the existing stock-managed `chunkById` traversal and batch candidate loading pattern; avoid per-setting HPP queries.

## Migration Plan

No schema migration or data migration is required. Deploy the command change, run dry-run to review created/updated/unresolved counts, then run with `--write`. Rollback is code rollback; previously written averages can be restored from a database backup or a reviewed corrective update if necessary.

## Open Questions

None for this change. The scope deliberately excludes changing purchase-price synchronization.
