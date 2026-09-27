## Context

`sales:backfill-cost-snapshots` fills historical `sale_details` cost snapshots by replaying purchase, purchase return, and sale events in effective-date order. The command currently builds one global timeline per product unless `--setting` is provided, so a sale in one company can be costed from purchases made by another company.

The completed `normalize-product-purchase-price-buckets` change already established a narrow historical repair rule for product purchase prices: `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA` use isolated historical purchase buckets, while all other settings use a REST/global bucket, and runtime purchase approval remains globally synchronized. This change applies the same historical boundary to sale cost snapshot backfill without changing live runtime behavior.

## Goals / Non-Goals

**Goals:**

- Backfill historical sale cost snapshots using separate replay buckets for:
  - `CV TIGA NUSA COMPUTER`
  - `CV TOP IT INTERNUSA`
  - REST/global settings
- Keep purchases, purchase returns, and sales in the same bucket so moving quantity/value is internally consistent.
- Fall back from an empty special-company purchase history to REST/global purchase history for that product.
- Preserve existing DPP cost calculation, date ordering, negative-stock handling, future-purchase fallback, zero fallback, suspicious-cost guardrail, dry-run/write/force behavior, and batch writes.
- Preserve `--setting` as an exact write filter while using the correct replay bucket context.
- Keep existing `BACKFILL_*` source labels.

**Non-Goals:**

- No change to live standard sale or POS sale snapshot capture.
- No change to runtime purchase approval or `ProductAveragePriceSynchronizer`.
- No change to `product:normalize-purchase-prices`.
- No schema changes.
- No new per-company costing model outside this historical repair command.
- No change to product sales prices, tier prices, or tax metadata.

## Decisions

### D1: Classify settings into three cost backfill buckets

The command will resolve setting IDs by case-insensitive company name:

```text
CV TIGA NUSA COMPUTER -> tiga_nusa
CV TOP IT INTERNUSA   -> top_it
all other settings    -> rest
```

Rationale: This mirrors the completed purchase-price normalization rule and keeps the repair explicit to the two companies that require isolation.

Alternative considered: separate every setting into its own timeline. Rejected because the requested business rule keeps all non-special settings pooled as REST/global.

### D2: Replay full bucket timelines, not purchase-only buckets

Each replay bucket must contain its matching purchases, purchase returns, and sales. A sale snapshot uses the pre-sale moving average from its bucket, then consumes quantity/value from that same bucket.

Rationale: If only purchases were bucketed, sales from another bucket could still drain or influence running quantity/value and produce unstable averages.

Alternative considered: compute static weighted averages from bucketed purchases only. Rejected because existing backfill semantics intentionally replay effective-date sales and purchase returns, including negative-stock reseeding behavior.

### D3: Use REST/global purchase fallback for empty special purchase history

When a Tiga Nusa or Top IT sale has no eligible purchase event in its own bucket for the product, the command will use REST/global purchase history for fallback evaluation. This includes the existing future-purchase fallback behavior, but sourced from REST/global when the special bucket has no own purchase history.

Rationale: This matches the product purchase price normalizer fallback rule and avoids zeroing special-company historical HPP for products that were only purchased through the shared/global pool.

Alternative considered: strict special isolation with zero/future fallback only inside the special bucket. Rejected by product decision alignment and explicit clarification.

### D4: Preserve `--setting` as a write filter

`--setting=<special id>` will replay and write that special bucket. `--setting=<non-special id>` will write only that exact setting's sales while using REST/global bucket context for the moving average and fallback.

Rationale: Operators expect `--setting` to limit affected sale rows. For non-special settings, using only that setting's purchases would break the REST/global pooling rule.

Alternative considered: make `--setting` replay only the exact setting for all settings. Rejected because it would produce a fourth behavior that conflicts with REST/global semantics.

### D5: Keep snapshot source labels unchanged

The command will continue writing existing labels such as `BACKFILL_RUNNING_AVERAGE`, `BACKFILL_FUTURE_PURCHASE`, `BACKFILL_ZERO_FALLBACK`, and `NON_STOCK_ZERO`.

Rationale: The bucket is derivable from sale setting and command behavior; adding new source labels would create report and support churn without changing the meaning of the fallback class.

Alternative considered: add labels like `BACKFILL_TIGA_NUSA_RUNNING_AVERAGE`. Rejected because it increases data variation without a clear downstream need.

## Risks / Trade-offs

- [Risk] Company names may differ in production spelling or casing. -> Mitigation: use case-insensitive trimmed matching, consistent with the product normalizer, and cover exact expected names in tests.
- [Risk] Special-bucket fallback to REST/global could hide missing special purchase history. -> Mitigation: preserve existing fallback warning counts and add test coverage showing fallback is deliberate.
- [Risk] `--setting` for a non-special company may be surprising because REST/global purchases from other non-special settings affect the result. -> Mitigation: document and test that `--setting` is a write filter, not a per-setting costing model.
- [Risk] Refactoring event replay can regress existing date-filter and negative-stock behavior. -> Mitigation: keep the existing calculator semantics and add regression coverage around start/end filters, future fallback, purchase returns, and source labels.

## Migration Plan

1. Add bucket classification support for settings used by purchases, purchase returns, and sales.
2. Build per-product replay events with bucket metadata.
3. Replay REST/global first or otherwise make REST/global fallback averages available to special buckets.
4. Replay and write matching sale details according to bucket and `--setting` filters.
5. Preserve existing batch update payloads and summary counters.
6. Add focused tests for special isolation, REST/global pooling, REST fallback, purchase returns, `--setting`, and unchanged runtime behavior.

Rollback strategy: revert the command and tests to the previous single global product timeline behavior. No schema rollback is required.

## Open Questions

- None. The agreed rule is bucket-aware historical sales cost backfill only, with REST/global fallback for empty special purchase history, setting-filter write semantics preserved, purchase returns bucketed by `purchase_returns.setting_id`, unchanged source labels, and unchanged future runtime global behavior.
