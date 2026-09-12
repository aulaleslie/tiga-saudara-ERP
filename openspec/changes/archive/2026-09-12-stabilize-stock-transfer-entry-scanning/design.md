## Context

The transfer form is composed of a parent Livewire form, a search/scan component, and a product-table component. Exact scans are resolved by `TransferScanResolverService`, then browser-dispatched payloads mutate table rows; tokenized search follows a related but distinct path. Final draft save and submission perform authoritative validation, but intermediate table methods still accept client-shaped product, mode, conversion, and serial data more readily than the archived stock-visibility specification permits.

Existing focused tests cover origin gating, mode-filtered search, sequential duplicate scans, allocation, serial quantity synchronization, final-condition rejection, and blind/privileged projections. They do not establish an explicit rapid-submission ordering contract, strict conversion-factor validation, or complete serial eligibility at the independently callable table boundary.

The design must preserve Laravel 10, Livewire 3, the current transfer resolver, base-unit persistence, shared draft editing, and the `stockTransfers.view-system-stock` projection boundary. This delivery precedes movement documents and must not alter inventory.

## Goals / Non-Goals

**Goals:**

- Give every accepted scanner submission a deterministic, at-most-once result in captured order.
- Represent component events as minimal operator intent and reconstruct authoritative product, conversion, stock, condition, and serial state at each server mutation boundary.
- Make exact product scans, conversion scans, serial scans, and text selections converge on one condition-aware row mutation path.
- Reject invalid conversion factors and ineligible or duplicate serials before changing row state.
- Preserve good/broken bucket isolation and blind-safe projections and feedback.
- Establish focused regression coverage suitable for reuse by later dispatch and receipt entry screens.

**Non-Goals:**

- Replacing `TransferScanResolverService` or introducing a general scanning framework.
- Adding movement, dispatch, receipt, or return documents and permissions.
- Reserving or deducting inventory during entry.
- Changing transfer lifecycle, route policy, tax allocation order, or persisted historical records.
- Adding offline scanner support, camera decoding, or device-specific integrations.
- Requiring the full application test suite.

## Decisions

### 1. Serialize captured scans in the scanner component

The scanner UI will capture the trimmed input value before clearing or reusing the field, process only one scan mutation at a time, and retain later captures in FIFO order. Completion—success, duplicate, or rejection—advances the queue and restores focus. Each captured entry receives a request-local operation token so repeated delivery of the same queued operation does not apply it twice.

The queue is interaction safety, not durable business data. It may live in the scanner component and browser coordination layer; no database table is introduced. The server-side row mutation still checks the current accumulated requested quantity or selected serial set, so correctness does not depend solely on disabling the input.

Alternative considered: rely on Livewire's default request behavior. Rejected because the required ordering and at-most-once semantics would remain implicit and difficult to regression-test. Alternative considered: persist every scan operation. Rejected as disproportionate for editable, unsaved form state.

### 2. Pass minimal scan intent and re-resolve it authoritatively

Events crossing from scanner/search into the table will identify the resolution kind and canonical record identifier: product ID for ordinary selection, conversion ID for conversion scans, or serial ID for serial scans. Client-supplied product attributes, stock snapshots, mode flags, conversion factors, serial provenance, and eligibility flags are not mutation authority.

The receiving table boundary will reload the active tenant-owned origin, current form-wide condition, stock-managed product, applicable stock buckets, conversion record and product association when relevant, and canonical serial state when relevant. Shared internal methods will apply the resolved intent to a row only after all checks pass.

Alternative considered: continue sending complete product arrays and validate only at save. Rejected because independently callable Livewire methods would retain a stale and craftable mutation surface. Alternative considered: sign complete payloads. Rejected because signed data can still be stale and exposes unnecessary structure.

### 3. Use one condition-aware availability calculation

Exact resolution, text selection, repeated increment checks, manual quantity validation, and allocation preview will use the same authoritative interpretation of the selected condition. Good mode reads only `quantity_tax + quantity_non_tax`, with the existing legacy aggregate fallback only where the current stock model requires it. Breakage mode reads only the broken tax and non-tax buckets. Neither mode may borrow from the other.

Availability checks used to decide whether an exact code resolves will be condition-aware rather than relying only on aggregate `product_stocks.quantity`.

Alternative considered: resolve any known barcode and reject it later in the table. Rejected because it creates inconsistent messages and makes exact scanning behave differently from search.

### 4. Validate conversion identity and factor without coercion

A conversion scan carries or resolves a conversion record ID. At the table mutation boundary the system reloads that record, verifies it belongs to the resolved product, and accepts it only if its current factor is finite, positive, and mathematically a whole base-unit quantity. The accepted integer factor is added directly to requested base quantity. Fractional, zero, negative, missing, or product-mismatched factors leave all rows unchanged.

Persisted transfer quantity remains the normalized base-unit total. Existing scan-context presentation may be retained where already supported, but it cannot be used as authority.

Alternative considered: cast the factor to integer. Rejected because truncation changes business quantity silently. Alternative considered: allow fractional base units. Rejected because current transfer quantities and stock mutations operate in whole base units.

### 5. Validate serials before table mutation and again at final boundaries

Both serial scan and serial autocomplete selection will converge on a method that reloads the serial and validates tenant product ownership, row product, origin location, canonical status, dispatch reservation, return-process state, selected condition, and uniqueness across all rows. Only then is serial identity appended and row quantity recalculated from the unique selected serial count.

Failure leaves products, selected serials, and quantities unchanged. Save, submit, approval, dispatch, and later movement boundaries continue to revalidate because eligibility can change after entry.

Alternative considered: accept the selection optimistically and defer rejection to save. Rejected because it temporarily presents invalid operator intent as accepted and complicates rapid-scan accumulation.

### 6. Preserve permission-aware projections at every outcome

Scanner results, events, table public state, validation errors, session messages, and focus-restoration events continue to pass through the archived transfer stock-visibility boundary. Blind users receive product and selected-serial identity plus their own requested quantity, but no stock buckets, availability, conversion-derived stock composition, serial provenance, maxima, or shortage values. Privileged users may receive detailed operational context where useful.

Focused tests will inspect state and dispatched payloads using sentinel values. The implementation will not depend on hidden HTML or masked values.

Alternative considered: use identical detailed rejection messages for all operators. Rejected because repeated scans would become an enumeration channel.

### 7. Verify behavior with focused layers

Service tests will characterize resolver precedence, condition-aware availability, and conversion validity. Livewire tests will cover sequential and queued rapid scans, row accumulation, duplicates, crafted component calls, focus behavior, and projection absence. Focused feature tests will cover authoritative save/submit revalidation and unchanged persistence on failure. A targeted browser interaction check will exercise physical scanner-like rapid Enter submissions where the PHP Livewire test harness cannot model overlapping requests faithfully.

The delivery does not require the full application suite. Relevant focused test files and filters are the acceptance gate.

## Risks / Trade-offs

- [A browser-only queue could be bypassed by crafted Livewire calls] → Keep all eligibility and duplicate rules authoritative in the called server method; the queue provides ordering, not authorization.
- [Operation tokens stored only in component state cannot deduplicate across a full page reload] → Scope at-most-once behavior to the active form session and queued request lifecycle; final normalized rows and save validation remain authoritative.
- [Legacy aggregate stock may disagree with explicit good buckets] → Characterize current fallback data and centralize the compatibility rule instead of introducing a new interpretation in each scan path.
- [Stricter conversion validation may reject historical malformed conversion records that previously appeared to work] → Return a stable actionable error and do not rewrite conversion records in this delivery.
- [Immediate serial checks can still race with another transaction after selection] → Preserve final save, submit, and dispatch revalidation; entry validation improves feedback but does not reserve stock.
- [Disabling or queueing the input can reduce perceived scanning speed] → Clear captured input promptly, show minimal busy feedback, restore focus reliably, and verify representative rapid scans in a focused browser check.

## Migration Plan

No database migration or historical-data rewrite is required. Deploy the centralized validation and event-contract changes together with their Blade/Livewire coordination and focused tests. Existing editable transfers continue to hydrate from persisted base quantities and serial identities, which are revalidated under the current origin and condition when next mutated or submitted.

Rollback consists of reverting the application change; no data rollback is needed because persistence format and inventory timing are unchanged.

## Open Questions

- During implementation, characterize whether Livewire's installed version already serializes the relevant component actions. Regardless of the result, retain an explicit testable FIFO/at-most-once contract rather than relying on undocumented incidental behavior.
- Confirm which existing legacy aggregate-stock fallback cases are represented in production fixtures so focused compatibility tests cover them without broadening the fallback.
