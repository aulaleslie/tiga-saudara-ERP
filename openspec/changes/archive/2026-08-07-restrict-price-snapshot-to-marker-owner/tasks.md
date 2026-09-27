## 1. Owner-scoped snapshot mutation

- [x] 1.1 Remove the Sales Price & Stock Snapshot import path's non-owner `product_prices` seeding so each resolved row mutates only its owner setting's price record.
- [x] 1.2 Preserve the resolved owner's Sale Price, Tier 1 Price, Tier 2 Price, stock snapshot, and adjustment transaction behavior in the same atomic mutation.
- [x] 1.3 Preserve DAIZU-first ownership resolution for KEDELE, KEDELAI, and RAGI rows, including rows that also contain `*` or trailing ` TP` markers.

## 2. Verification

- [x] 2.1 Update or replace cross-business seeding tests to assert that `*`, trailing ` TP`, and unmarked rows update only CV Tiga Nusa, CV Top IT, and Perdana respectively, without creating or changing non-owner price rows.
- [x] 2.2 Add or retain coverage proving DAIZU precedence still applies to all three selling tiers and stock ownership.
- [x] 2.3 Run the focused Sales Price & Stock Snapshot import test suite and resolve regressions.
