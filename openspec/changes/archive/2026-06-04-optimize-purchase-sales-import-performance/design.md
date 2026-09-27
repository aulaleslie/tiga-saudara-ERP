## Context

Purchase and sales CSV imports already run asynchronously through staged import rows and processing jobs. Staging is reasonably efficient because it parses the CSV and bulk inserts `*_import_rows` in 500-row chunks. The slow path is the processing phase, where each source invoice creates real ERP documents and side effects.

Current purchase processing loads all pending rows for a batch, groups all invoices in memory, and processes the whole batch in one job with a 300-second timeout. Purchase lookups for settings, taxes, suppliers, products, units, locations, product prices, and product stocks are mostly repeated through per-row queries.

Current sales processing is more advanced: it chunks pending rows, keeps invoice rows together, preloads several lookup tables, and uses a 7200-second job timeout. It still performs expensive per-line writes for sale details, dispatch details, stock updates, transaction logs, row status updates, and all-settings product price synchronization.

Existing import correctness requirements must remain authoritative, especially:
- document total reconciliation
- payment ledger consistency
- split-owner allocation
- product price synchronization
- Daizu and tag-based ownership
- stock, dispatch, payment, and transaction-log side effects

Representative source files show the scale difference:
- purchase S1 2026: about 2.3k rows and 1.2k documents
- purchase S2 2025: about 3.6k rows and 1.9k documents
- sales S1 2026: about 27k rows and 11.8k documents
- sales S2 2025: about 39k rows and 16.7k documents

## Goals / Non-Goals

**Goals:**
- Make purchase and sales import processing memory-bounded for large files.
- Reduce repeated database reads and writes while preserving imported business records exactly.
- Align purchase processing with the safer chunked approach already used by sales.
- Keep source-invoice groups atomic: one source invoice either imports its owner groups correctly or marks them invalid/skipped consistently.
- Add progress/timing logs that identify staging, preloading, grouping, document creation, stock movement, price sync, and row-status update costs.
- Keep imports compatible with database-backed Laravel queues and focused SQLite tests.

**Non-Goals:**
- Do not change the CSV format or require users to split files manually.
- Do not remove import side effects such as stock movement, dispatch creation, payment rows, transaction logs, tag sync, or product price sync.
- Do not rewrite historical imported purchase or sales records.
- Do not introduce a new queue backend or external import service.
- Do not relax monetary reconciliation, duplicate detection, ownership, tax, or payment requirements.

## Decisions

### Decision 1: Process purchase batches in source-invoice-aware chunks

Purchase processing should adopt the sales pattern of repeatedly loading a bounded set of pending rows ordered by `row_number`, extending the chunk to include all remaining rows for the final source invoice, then grouping and processing only that chunk.

Rationale:
- Avoids loading every purchase import row into memory.
- Preserves invoice-level payment reconciliation and split-owner allocation.
- Keeps the same staged-row model and avoids new schema requirements.

Alternatives considered:
- Process fixed row chunks only: rejected because it can split invoice groups and break document-level reconciliation.
- Load only invoice numbers first, then query rows per invoice: viable but more query-heavy and less consistent with existing sales import structure.

### Decision 2: Add purchase-side caches equivalent to sales caches

Purchase import should preload and cache small/static lookup tables and chunk-specific suppliers/products. The cache set should include settings, taxes, units, locations, suppliers, products, product prices, and product stocks where safe.

Rationale:
- Purchase currently repeats many lookups that sales already avoids.
- Caches reduce N+1 read patterns without changing persisted output.

Alternatives considered:
- Add only database indexes: useful but insufficient because repeated queries still happen at high volume.
- Cache globally across the whole job without bounds: rejected for large product/customer catalogs because it risks memory growth.

### Decision 3: Keep document creation atomic at source-invoice scope

Each source invoice should continue to run inside a database transaction that covers all owner groups derived from that invoice.

Rationale:
- Existing specs require source invoice reconciliation before owner split creation.
- If one owner group fails due to payment, ownership, stock-location, or validation issues, the source invoice should not create partial documents.

Alternatives considered:
- One transaction per chunk: rejected because a single bad invoice would roll back many unrelated invoices.
- One transaction per owner group: rejected because split-owner invoice payment allocation must reconcile across owner groups.

### Decision 4: Batch safe writes, but keep stateful inventory updates ordered

The implementation should bulk update import-row statuses and batch counters per group/chunk. It should bulk insert rows such as details, payments, dispatch details, or transaction logs only when existing code does not require generated model events or immediate model state for subsequent calculations.

Stock quantity updates and weighted-average purchase price calculations should remain ordered per product unless an implementation proves an aggregate update preserves previous/after quantity audit fields exactly.

Rationale:
- Import-row status updates and batch counter increments are safe high-volume wins.
- Inventory transaction logs include previous and after quantities; careless aggregation could destroy audit meaning.

Alternatives considered:
- Fully aggregate all stock movement per product/location: rejected as a first step because it changes per-line transaction audit semantics.
- Leave all writes row-by-row: rejected because it preserves the current bottleneck.

### Decision 5: Deduplicate all-settings product price sync work within a chunk

The importers should avoid repeated `Setting::pluck('id')` and repeated product price row creation for the same product/settings combination. For sales, only the last positive processed unit price for a product in processing order should win, matching the existing price-sync spec. For purchase, weighted-average calculations must preserve current semantics before deferring or batching sync writes.

Rationale:
- All-settings price sync multiplies work by line count and setting count.
- Existing specs require final synchronized values, not necessarily one physical update per source row.

Alternatives considered:
- Disable price sync during historical imports: rejected because existing specs require it.
- Sync only the source setting: rejected because current product price sync capability requires every setting.

### Decision 6: Add instrumentation before and during optimization

Processing should log batch id, chunk number, row count, invoice count, success/error/skipped counts, elapsed time, and rows/documents per second. Phase timing should cover preload, grouping, invoice processing, stock/dispatch, price sync, and row-status persistence where practical.

Rationale:
- Current logs show chunk boundaries for sales but not enough detail to isolate hot phases.
- Performance work needs a feedback loop in staging and production-like imports.

Alternatives considered:
- Use only database query logs: too noisy and expensive for production-like imports.
- Add a new metrics dependency: not needed for this scoped change.

## Risks / Trade-offs

- Row processing order changes could alter "latest price wins" behavior → preserve `row_number` ordering and add tests where multiple rows for the same product carry different prices.
- Bulk row-status updates could hide per-row error messages → only bulk update rows that share the same status/message/document id; keep row-specific messages when errors differ.
- Chunking could still split invoices if CSV rows for the same invoice are not contiguous → preserve the current assumption that source invoice rows are contiguous and document it; add detection/logging if later rows with the same invoice remain pending after a chunk.
- Caches can become stale after creating new suppliers/products/taxes/units → immediately write newly created entities back into the relevant cache.
- Purchase job timeout may still be exceeded on very slow environments → align timeout with sales and ensure queue worker timeout remains higher than job timeout.
- More batching reduces per-row Eloquent model event execution → use bulk writes only for tables where import behavior does not rely on model events, observers, or mutators.

## Migration Plan

1. Add or verify indexes needed by chunk queries and duplicate checks, especially `(batch_id, status, row_number)` for import rows and source document duplicate indexes.
2. Update purchase processing to chunk pending rows while preserving source-invoice atomicity.
3. Add purchase caches and align timeout behavior with large batch expectations.
4. Add safe bulk status/counter updates and price-sync deduplication to purchase and sales.
5. Add timing/progress instrumentation.
6. Run focused import tests for purchase and sales correctness.
7. Run a representative local/staging import using purchase and sales CSV samples and compare created record counts and totals.

Rollback is application-code rollback only unless new indexes are added. New indexes are additive and can remain in place if code is rolled back.

## Open Questions

- What throughput target should be considered acceptable for production-like data: rows per minute, documents per minute, or total time per semiannual file?
- Are source CSV rows guaranteed to be grouped contiguously by `Nomor Transaksi` in all files users will import?
- Should the UI expose phase progress beyond the existing batch status and row counts, or are logs sufficient for this change?
