## Context

Product base-unit barcodes are stored on `products.barcode`; optional unit-level barcodes are stored on `product_unit_conversions.barcode`. The full product create/update forms validate these namespaces separately, product and conversion barcode columns have non-unique search indexes, and the product model currently normalizes most strings through the shared `BaseModel` mutator. The existing Print Barcode page combines the generic product search component with a Livewire preview component, but it only previews a barcode already stored on a stock-managed product, rejects nonnumeric values, and does not persist assignments.

The requested workflow is a high-volume data-maintenance station used with keyboard-emulating hardware scanners. The primary stakeholders are warehouse/catalog operators who need a narrow permission and rapid focus management, product administrators who need safe replacement behavior, and POS users who depend on unambiguous barcode resolution.

## Goals / Non-Goals

**Goals:**

- Provide a scanner-first loop of search, select, scan, preview, confirm, and automatically return to search.
- Update only the selected product's base-unit barcode without submitting unrelated product, pricing, stock, tax, media, or conversion data.
- Preserve barcode values as strings and prevent collisions with both product and conversion-unit barcodes.
- Make replacement intentional, concurrency-safe, and auditable.
- Follow the existing Laravel 10, Livewire 3, Bootstrap/CoreUI, Spatie permission, Eloquent, and focused feature-test patterns.

**Non-Goals:**

- Assigning or editing unit-conversion barcodes from this workspace.
- Generating new arbitrary barcode numbers for products.
- Changing POS cart resolution, camera scanning, scan acknowledgement, barcode-label printing, or serial-number behavior.
- Bulk spreadsheet import or automated matching of products to supplier barcode feeds.
- Rewriting existing product or conversion barcode values during deployment.

## Decisions

### Decision 1: Build one dedicated Livewire workspace with an explicit state machine

Use a dedicated Product-module page and one Livewire 3 component that owns search criteria, the selected product snapshot, candidate barcode, preview state, confirmation mode, errors, saved count, and bounded recent-session activity. The component state transitions are:

```text
SEARCHING -> READY_TO_SCAN -> REVIEW_INITIALIZE/REVIEW_REPLACE -> SAVING
    ^              ^                       |                     |
    |              +-------- error/cancel--+                     |
    +------------------------- success --------------------------+
```

Browser events emitted after product selection, cancellation, validation failure, and success will control focus without embedding persistence logic in JavaScript. The scanner's first Enter terminator captures the candidate; it cannot also confirm because confirmation is a later state. In review state, a subsequent deliberate Enter or the confirmation button may submit.

**Alternatives considered:** Extending the full product edit form would couple barcode work to unrelated required pricing/unit inputs and side effects. Extending Print Barcode would mix assignment with label quantity/PDF concerns and retain its stock-managed search assumption.

### Decision 2: Use a narrow assignment service and transaction boundary

Introduce a product barcode assignment service invoked by the Livewire component. It will authorize the barcode-specific capability, normalize scanner framing without numeric coercion, lock and re-read the selected product, compare the original barcode snapshot for stale-state protection, validate the candidate in both barcode tables, persist only `products.barcode`, and append audit history within one database transaction.

All expected validation outcomes will be returned as domain-level results suitable for Indonesian UI messages; unexpected failures will roll back and follow existing exception reporting conventions.

**Alternatives considered:** Calling `ProductController::update` would require a complete product payload and could update prices, taxes, conversions, or media. Direct component mutation would make reuse, transaction tests, and concurrency rules harder to isolate.

### Decision 3: Treat barcode values as opaque strings and preview with Code 128

The input will use `type="text"`, retain leading zeroes, enforce the existing 255-character storage limit, remove only scanner framing such as the submitted Enter terminator and surrounding accidental whitespace, and never cast the value to an integer. Barcode persistence must bypass any generic transformation that would alter a supported scanned value; normalization and comparison will be centralized so assignment, duplicate checks, and later resolution use the same canonical behavior.

The review panel will render the candidate through the already-installed Milon barcode library using Code 128 because it supports numeric and common alphanumeric values and requires no new external dependency. The displayed candidate text remains authoritative; the preview is a confirmation aid and does not claim to reproduce the package's original EAN/UPC/Code 128 symbology.

**Alternatives considered:** Inferring EAN/UPC solely from length is unreliable without a trusted symbology signal from keyboard-emulating scanners. Rendering on every keystroke would cause unnecessary Livewire requests while the scanner is transmitting; preview generation begins when the scanner terminator captures the complete candidate.

### Decision 4: Enforce one barcode identity namespace with database-backed serialization

Application validation will query both `products.barcode` and `product_unit_conversions.barcode` and report the conflicting product/unit. To prevent two absent-row checks from succeeding concurrently, add a small barcode identity registry with a unique canonical key and ownership metadata. Existing non-null product and conversion barcodes will be preflighted and backfilled into the registry during deployment. Barcode mutation paths in the Product module will reserve/release registry identities through a shared service so the dedicated workspace, full product forms, quick-add flow, and conversion updates cannot diverge.

The original barcode columns remain the operational source used by POS and other current queries; the registry is the database uniqueness boundary and ownership index, not a replacement resolver. Separate unique constraints should also be added to the two existing barcode columns when preflight confirms the historical data is clean.

**Alternatives considered:** `lockForUpdate()` on matching barcode rows cannot lock a value that does not exist, so it does not prevent concurrent first assignments. A Redis/cache lock introduces runtime infrastructure assumptions and still would not protect writes made outside the new workspace. Cross-table uniqueness cannot be expressed as a normal relational unique index without a shared registry.

### Decision 5: Record barcode-specific mutation history

Create durable product barcode assignment history containing product reference plus product name/code snapshots, old barcode, new barcode, initialization/replacement action, actor, and timestamps. Product and user foreign keys will be nullable on deletion while snapshots preserve the audit meaning. Only successful mutations create records; no-op confirmation and rejected attempts do not.

**Alternatives considered:** The Product entity does not currently implement the auditing contract used by some other entities. Enabling broad Product auditing would capture unrelated changes and expand this feature beyond barcode accountability.

### Decision 6: Add a narrow permission and retain existing product visibility rules

Add `products.barcodes.manage` to the existing permission configuration and protect both page access and mutation. Search will use the product catalog visibility already available to the authenticated setting/user but will not inherit the existing generic search component's `stock_managed = true` restriction. The menu entry will be separate from `barcodes.print`; having print permission does not imply mutation permission.

**Alternatives considered:** Reusing `products.edit` would give barcode operators authority over price, tax, stock, conversion, and product metadata. Reusing `barcodes.print` would unexpectedly turn a read/print permission into a write permission.

### Decision 7: Optimize the repeat loop without auto-saving or auto-selecting arbitrary products

Search results show product name, product code, base unit, and initialized/uninitialized status. The default view favors missing barcodes and provides an explicit all-products/replacement mode. After success, recent activity and the session count update, selected/candidate state clears, and search receives focus. Validation failures retain context and return focus to the corrective control.

**Alternatives considered:** Automatically moving to an arbitrary next product is fast but unsafe when the operator is matching physical items to catalog records. Auto-saving on the scanner terminator removes the requested human confirmation gate.

## Risks / Trade-offs

- **Historical duplicate barcodes may block registry backfill or unique indexes** -> Provide a read-only preflight report and abort the migration safely with actionable product/conversion ownership details; do not rewrite values automatically.
- **Integrating the registry with every Product-module barcode mutation path expands scope** -> Centralize reservation logic in one service and add regression tests for full product create/update, quick-add, and conversion edits.
- **Code 128 preview may look different from the physical EAN/UPC symbol** -> Label the raw candidate prominently and treat the preview as value confirmation, not symbology detection.
- **Livewire latency can make focus transitions feel slow** -> Keep search/result payloads small, debounce textual search, generate one preview only after capture, and use browser focus events after responses.
- **Case/collation differences between MySQL and SQLite can produce inconsistent identity checks** -> Define a canonical registry key in application code and test equivalent collision behavior on both databases.
- **Replacement immediately changes what POS recognizes** -> Show old/new values, require explicit replacement confirmation, record history, and never silently retain two active base-unit barcodes for one product.

## Migration Plan

1. Add the barcode registry and assignment-history tables without changing existing barcode values.
2. Run a deterministic preflight over non-null product and conversion barcodes; report and resolve any historical duplicates before enabling constraints.
3. Backfill clean existing identities into the registry, then add safe per-table unique constraints where supported by the existing migration conventions.
4. Route all Product-module barcode create/update/conversion paths through the shared identity service.
5. Register the new permission, deploy the protected workspace, and grant permission only to intended operator roles.
6. Verify product creation/edit, quick-add, conversion management, POS lookup, and barcode printing against representative existing barcodes.

Rollback removes the workspace route/menu/permission and returns mutation paths to their prior UI while retaining assignment history for audit. Registry constraints and tables SHALL only be removed after confirming no post-deployment values rely on the new cross-table integrity guarantees; stored product and conversion barcode columns are never cleared by rollback.

## Open Questions

None. The workspace assigns base-unit product barcodes only, accepts scanner-produced string values supported by the existing storage limit, and uses Code 128 solely for the review preview.
