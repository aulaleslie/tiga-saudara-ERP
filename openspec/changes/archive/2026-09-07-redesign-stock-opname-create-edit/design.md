## Context

Normal adjustment create/edit uses `AutoComplete/LocationLoader`, `Purchase/SearchProduct`, and `Adjustment/AdjustmentProductTable`. The browser search calls `/api/products/search`, which already uses tokenized `Product::globalSearch()` but returns `is_serialized`; the adjustment table reads `serial_number_required`, causing the reported 500. Ordinary rows initialize from system stock. Location changes clear products but leave parallel quantity/error arrays. Update validates location but does not persist it, and edit/error restoration is inconsistent.

Serial entry reuses a purchase-return loader with existence/status/dispatch/return restrictions. Save validation requires existing serial IDs and accepts per-serial tax flags. Normal adjustment details have quantity_tax/quantity_non_tax and serial JSON, without separate proposed good/bad counts. Create/update do not post inventory; legacy approval interprets those old fields. These constraints require draft persistence changes in addition to UI work.

## Goals / Non-Goals

**Goals:** Consistent create/edit counting with location selection, POS-style scan and dialogs, base-unit quantities, good/bad classification, product-scoped serial deduplication, location-derived tax classification, explicit comparison, and lossless pending-draft persistence.

**Non-Goals:** Implement approval reconciliation, create live serials, transfer stock, resolve shortages, handle approval-time stock drift, redesign separate breakage/POS/purchase workflows, add camera scanning, run full test suites, or automate browser testing.

## Decisions

### 1. Adjustment-owned editor and resolver

Keep Laravel/Livewire/module patterns and use the shared location dropdown with standard-location filtering, selected label, targeted event adapter, and one submitted location field. Resolve PKP from the selected location's setting on the server. Require a location before counting. Confirm before clearing populated rows on location change; clear counts, serials, errors, and comparison state together. Normalize ID types.

Use adjustment-owned scan/search handling so sale eligibility and purchase-return serial restrictions do not leak into opname. Reuse `Product::globalSearch()` and POS interaction patterns rather than copy POS cart mutation endpoints. Search stock-managed products using existing active-product conventions, without the purchase API's is_sold restriction. Load product identity, serial requirement, and units server-side; never depend on a browser-provided serial flag. Existing serialized stock can be recorded regardless of serial location/status/dispatch/return flags.

Alternative: modifying the shared purchase search/serial loader globally risks changing unrelated workflows and still leaves draft persistence incomplete.

### 2. One row per product, two counted conditions

Each row has product identity, base unit, good_count, bad_count, serial entries, and comparison baseline. The Good/Bad toggle defaults to Good and directs subsequent scan increments. Switching conditions preserves existing counts. Product search creates zero-count rows or focuses existing rows. Ordinary barcode adds one; conversion barcode adds its stored factor in base units. Serialized product/conversion barcodes create a zero-count row or focus it without increments. Serial addition alone increases serialized counts.

Represent conversion increments exactly within existing quantity precision. Current integer schema does not establish that all factors are integral merely because they are >=1; validate representability and report unsupported factors without rounding or changing counts. Fractional inventory support is outside this change.

### 3. Serial text is a draft value, not necessarily a live record

Use entries containing serial text, proposed condition, optional source serial ID, and source product/location/status metadata for review. Main scanning resolves existing serial records only. A unique match creates/focuses its product and attaches the serial. Unknown main-field serials show not found. Row dialog accepts unknown text for its selected product, including text existing only on another product, without creating a live serial.

Use product ID plus trimmed serial text, with case semantics consistent with existing serial lookup, as the duplicate key across Good and Bad. Distinct products can share text. An existing duplicate does not increment or silently switch condition; provide explicit condition reassignment/removal. Counts are recomputed from accepted entries server-side. Existing serial status, location, dispatch linkage, or return flags do not block draft entry. Ambiguous product/serial/conversion matches open a choice dialog; nothing changes until selection. In row entry, the row product supplies context; do not attach another product's record ID.

Alternative: requiring existing IDs cannot represent newly observed serials; eagerly creating serials would violate approval-only inventory changes.

### 4. Versioned additive draft storage

Add a nullable JSON `count_draft` column on adjustments containing schema_version, location_id, location_setting_id, is_pkp snapshot, baseline_captured_at, and product rows. Each row records base-unit good/bad counts, source comparison quantities (including original tax/non-tax good/bad buckets), and serial entries. This payload is authoritative for new-format count proposals. Preserve legacy detail rows and records rather than projecting new data into old approval fields with incompatible semantics.

Validate and normalize through one shared create/update persistence service; recompute serialized counts and tax allocations rather than trusting posted totals/flags. Save header and payload atomically with pending status and no ProductStock, ProductSerialNumber, transaction, or history writes. Maintain appropriate existing approval notification conventions with clear pending-review wording. Omitted products are untouched; explicit zero-count rows remain included.

For PKP locations, both proposed condition counts map entirely to tax buckets; for non-PKP locations, entirely to non-tax buckets. Existing serial tax metadata does not override the target location's rule. Existing counts for comparison sum each condition's tax/non-tax buckets without hiding historical discrepancies.

Alternative: adding good/bad fields to legacy details alone still cannot safely model unknown serials and would let legacy approval misinterpret new drafts. A version marker creates an explicit compatibility boundary.

### 5. Edit and review semantics

Edit restores saved counts/conditions/raw serial text without reinitializing from system stock; validation failure restores attempted input. Persist changed location with its new state. Restrict new counting saves/edits to pending normal adjustments and retain permission checks. Legacy pending records load through an adapter: their existing quantities become proposed good counts, bad defaults to zero, and saved serials retain product-specific identity. Convert to versioned format only on save, leaving legacy records untouched on GET.

Capture comparison baseline server-side when a row is added, retain it across edit, and display its timestamp. This is review evidence, not approval authorization; approval-time refresh/drift policy is deferred. Show existing good/bad, proposed good/bad, and signed differences. Serial review displays known source location/condition and proposed condition, marking unknown serials as draft entries. Do not claim shortages or cross-location reconciliation are resolved.

### 6. Guard legacy approval

Every server-side approval entry point capable of reaching normal adjustment posting must reject a versioned count draft before mutation, with an explanatory message that the new approval workflow is pending. Existing legacy approvals remain unchanged. Add minimal show/list presentation needed to identify the pending count proposal and avoid misleading empty legacy detail displays. Full approval UI and posting remain out of scope.

## Risks / Trade-offs

- New drafts cannot yet be approved → Explicit compatibility guard and visible explanation; separate approval exploration is a deployment dependency for end-to-end use.
- Existing serial lookup may have global scopes → Inspect resolver queries so location/status restrictions do not silently contradict allowed draft entry while preserving authorization.
- Fast scans and duplicate submissions can race → Serialize client scan processing, validate deduplication on the server, retain existing form submission protections, and do not drop legitimate repeated ordinary barcodes.
- Baseline can become stale → Display capture time and retain provenance; defer approval drift policy without claiming live reconciliation.
- Legacy documents have incomplete condition information → Adapt conservatively as good counts and do not infer missing bad counts from current stock.
- JSON storage trades relational querying for safe isolation → Keep a documented versioned schema and server validation for later approval integration.

## Migration Plan

1. Apply additive nullable draft-payload migration with MySQL/SQLite compatibility.
2. Deploy editor/persistence and legacy approval guard together; keep historical data unchanged.
3. Validate focused schema, resolver, component, persistence, and guard tests; provide human browser checklist.
4. Before rollback, retain/export new-format payloads and prevent old code from approving their documents. Do not drop populated payload data or deploy an unguarded legacy handler over new drafts.

## Open Questions

No blocking create/edit questions remain. Approval policies for shortages, source location moves, serial creation/reactivation, and stock drift are explicitly deferred. Implementation must inspect actual conversion precision and serial collation before choosing normalization details, without silently rounding or widening scope.
