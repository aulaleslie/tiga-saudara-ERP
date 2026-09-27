## Context

The existing `sales:backfill-cost-snapshots` command reconstructs historical sale detail costs by replaying product purchase, purchase return, and sale events by effective date. In production-like data, a forced write run failed after computing a negative running-average cost of `-1,645,235,000`, which exceeded the `sale_details.cost_unit_snapshot decimal(15,6)` range and indicates poisoned replay state rather than a valid product cost.

The current implementation loops every product, loads Eloquent models and relations, builds an in-memory event collection per product, sorts it, and writes each sale detail with `save()`. With thousands of products, this amplifies query count and model overhead. Existing behavior must remain compatible with Laravel 10, Livewire/module conventions, MySQL/MariaDB production, and SQLite-focused tests.

## Goals / Non-Goals

**Goals:**

- Prevent negative-stock replay from carrying invalid negative inventory value into future average costs.
- Ensure historical purchase cost uses tax-exclusive DPP after line discount.
- Prevent negative, non-finite, and unrealistic unit costs from being written as valid snapshots.
- Keep write runs operationally safe by reporting suspicious rows and continuing where possible.
- Preserve existing command options and idempotent behavior.
- Make date-filtered runs correct by replaying prior events needed for opening inventory state while only writing/reporting matching sale details.
- Reduce query amplification, default eager-loading overhead, sorting overhead, and row-by-row update overhead.
- Add focused tests for financial correctness, guardrails, and filtered replay behavior.

**Non-Goals:**

- No FIFO/LIFO implementation.
- No accounting ledger redesign.
- No change to live sale/POS snapshot behavior except where shared DPP calculation helpers are reused.
- No destructive migration or historical sale rewrite outside explicitly selected backfill writes.
- No automatic repair of source data quality issues such as missing purchases, impossible dates, or unit-conversion errors.

## Decisions

### D1: Reset poisoned moving-average basis when replay quantity is not positive

When running quantity reaches `<= 0`, the command must not preserve negative running value as the basis for later averages. The next valid purchase event reseeds the average basis from that purchase cost and quantity rather than combining with stale negative value.

Rationale: a moving average represents cost of available inventory. If replay inventory is zero or negative, there is no reliable positive stock value to average with the next purchase. Carrying negative value forward can produce impossible costs and numeric overflow.

Alternative considered: widen `cost_unit_snapshot` to accept larger numbers. Rejected because the calculated value is invalid domain data, not a storage-capacity issue.

### D2: Use DPP after discount for purchase event cost

Backfill purchase event cost must calculate:

```text
line_cost = sub_total - product_tax_amount - product_discount_amount
unit_cost = line_cost / quantity
```

Receipt-prorated events use the same line cost prorated by received quantity over ordered quantity.

Rationale: the archived design and specification require discount to reduce product cost. Ignoring discount overstates inventory value and sale cost.

Alternative considered: continue using `sub_total - product_tax_amount`. Rejected because it contradicts the existing capability contract.

### D3: Treat suspicious unit costs as warnings, not writable snapshots

The command must not write a stock-managed running-average snapshot when the computed unit cost is negative, not finite, or above a configured maximum. The default maximum should be `100,000,000` IDR unless the project already has a better configuration surface. Suspicious rows should receive a distinct warning/source path and be listed in audit output with product ID/code, sale detail ID, date, running quantity, running value, and computed cost.

Rationale: product costs above `100,000,000` IDR are operationally suspicious for this ERP, and above `1,000,000,000` IDR should never be silently persisted. Continuing with warnings lets a long write run finish other rows without hiding bad source data.

Alternative considered: clamp costs to the threshold. Rejected because clamping would create fabricated financial data.

### D4: Replay all prior state for filtered runs, but only write in scope

When `--start` or `--end` is used, purchase, purchase-return, and sale events before the write/reporting window must still be replayed if they affect opening stock and average. The date filter controls which sale details can be written or counted as fillable/skipped, not which prior events exist in the replay state.

Rationale: excluding earlier sales inflates opening inventory and average cost for the selected period.

Alternative considered: keep filtering sale events at query time for speed. Rejected because it can produce incorrect financial snapshots.

### D5: Prefer a streamed event replay over per-product Eloquent reconstruction

Implementation should move toward a single ordered event stream keyed by `product_id`, effective date, event order, and event ID. The stream may be built from query-builder rows or chunked union-style queries. It should select only fields required by replay, disable default eager loads where Eloquent remains, and process product state as rows arrive.

Event order for identical timestamps should be deterministic:

1. purchase/approved receipt events
2. purchase return events
3. sale events

Rationale: this reduces product-by-product query amplification and avoids repeated Eloquent relation loading. Deterministic same-date ordering also makes reruns stable.

Alternative considered: keep the current implementation and add only indexes. Rejected because 4,857 products already imply thousands of repeated queries before eager-load queries are counted.

### D6: Batch writes without changing command semantics

Computed snapshot updates should be buffered and persisted in chunks using query builder updates/upserts or another deterministic batch strategy. Updates must still set `cost_snapshot_at`, preserve `--force` semantics, and avoid overwriting non-backfill snapshots unless existing behavior intentionally allows them.

Rationale: one Eloquent `save()` per sale detail is slow and invokes model overhead that is unnecessary for a controlled backfill command.

Alternative considered: keep row-by-row `save()` for simplicity. Acceptable only as an interim fix if the correctness guardrails are implemented first.

## Risks / Trade-offs

- [Risk] Resetting negative-stock state can understate cost when missing purchase history actually exists before the dataset. -> Mitigation: record negative-stock warnings with product/detail context and keep future-purchase/no-purchase fallback markers.
- [Risk] A fixed suspicious-cost threshold may reject legitimate high-value products. -> Mitigation: make the threshold configurable while defaulting to `100,000,000` IDR.
- [Risk] Streamed query-builder rows bypass Eloquent casts. -> Mitigation: explicitly cast numeric fields to floats/strings at replay boundaries and cover decimal/fractional quantities in tests.
- [Risk] Batch updates can make per-row debugging harder. -> Mitigation: keep an audit list of computed suspicious rows and write counts by source marker.
- [Risk] Adding indexes can lock large MySQL tables during deployment. -> Mitigation: add only necessary composite indexes and coordinate production migration timing.
- [Risk] Date-filtered replay may read more events than expected. -> Mitigation: keep writes scoped to the requested window and document that prior events are required for correct opening state.

## Migration Plan

1. Add focused tests that reproduce the numeric overflow path with negative stock followed by later purchases.
2. Fix DPP discount handling and negative-stock average reset.
3. Add suspicious-cost guardrails and warning reporting.
4. Correct filtered replay semantics.
5. Reduce eager loading and selected columns as an initial performance pass.
6. Add necessary composite indexes if query plans show missing support.
7. Move replay toward a streamed/chunked event path and batch updates.
8. Run focused command tests with SQLite and, where available, dry-run against staging MySQL data.
9. After deployment, run dry-run first, inspect suspicious rows, then run `--write --force` because the failed write run may have partially updated earlier rows.

Rollback strategy: changes are command-level and additive-index only. If the optimized stream path causes unexpected behavior, revert to the guarded per-product replay while keeping the correctness guardrails and tests.

## Open Questions

- Should the suspicious-cost threshold live in config, an environment variable, or a command option?
- Should suspicious rows remain null, receive zero fallback, or keep existing snapshot values in force mode? The safest default is to leave existing values unchanged and report the row.
- Can original purchase cost be reliably resolved for purchase returns through existing `po_id`/detail links, or should this remain a later enhancement?
