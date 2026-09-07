## Context

POS name search and base-barcode resolution currently return no selectable conversion list. The sell page's addProductToCart opens bundle selection first; normal additions pass conversion_id when present, while addBundleToCart currently omits it. PosCartService already resolves conversion ownership and current-business sales enablement and multiplies quantity before pricing, but casts the factor to an integer.

ProductUnitConversion.isSalesEnabledForSetting defaults missing business price rows to enabled. Existing packing prices, bundle prices, merge keys, stock-managed behavior, serial handling, and cached pricing bases must remain authoritative.

## Goals / Non-Goals

**Goals:**
- Offer unit choice for each name-result/base-barcode selection with sales-enabled conversions, before bundle intent.
- Preserve one base-unit quantity model across ordinary products and bundles.
- Safely carry one selection through asynchronous modal transitions and one cart submission.
- Reject unsupported factors and stale disabled conversions before mutation.

**Non-Goals:**
- Fractional POS quantities, conversion-definition changes, or new conversion price calculations.
- Remembering a unit between adds, changing cart quantity button steps, or changing serial-scan bundle intent.
- Database migrations, historical transaction changes, broad pricing/merge refactors, full-suite testing, or automated browser testing.

## Decisions

### 1. Share business-scoped unit metadata between search and scan
Add a small shared POS unit-options resolver used by PosProductSearchService and PosScanResolverService for product selections. Return the base-unit label and sales-enabled conversion IDs, labels, and factors, plus whether selection is applicable. Batch-load options for search results using existing relationships and business enablement semantics. Keep the exact conversion-barcode metadata and entry provenance distinct from the list of available units.

The picker displays base unit plus enabled conversions using the existing bundle modal/card styling. Invalid enabled conversions can be shown unavailable with an explanation; base-unit selection remains usable. A product with no enabled conversions bypasses the picker. Missing business price records retain the existing enabled-by-default behavior.

Alternative: fetch options through a new endpoint on every selection. Shared response enrichment avoids a second mandatory request and keeps scan/search eligibility aligned. Do not show a standalone conversion price as a guaranteed charge: existing packing/tier/bundle pricing determines the final amount.

### 2. Use an explicit per-add selection lifecycle
Route name results and base barcodes through unit choice, then existing bundle choice, then submission. Every new add begins with fresh state. Conversion barcodes carry their resolved conversion directly to bundle choice; serial scans skip unit choice and carry no conversion multiplier.

Use an operation token and phases such as choosing-unit, choosing-bundle, submitting, and finished/cancelled. Capture the selected product, source, conversion ID, bundle intent, and optional serial in the operation. Ignore stale asynchronous responses, disable repeated activation while submitting, and invalidate cancelled operations. Distinguish deliberate modal transition from cancellation so Bootstrap hidden events cannot erase the next modal's state. Keep scanner/global Enter handling from starting another add during an active selection; restore input focus after completion/cancellation.

Alternative: reuse global pendingBundleProduct with additional flags only. Explicit operation ownership better prevents stale callbacks, hidden-modal cleanup races, and double activation. This is UI duplicate protection, not a new durable network idempotency protocol.

### 3. Multiply exactly once on the server
Submit qty: 1 and the selected conversion_id, including when bundle_id is supplied. Base-unit and serial additions omit conversion_id. Revalidate conversion product ownership, current-business sales enablement, and a finite integer factor greater than 1 before multiplication. Reject fractional/invalid factors with an actionable error and no cart mutation; do not round or truncate them. Apply this guard to conversion-barcode and direct cart requests too.

Keep PosCartService's existing pricing and merge paths. Ordinary rows use existing packing/tier rules; bundled rows use existing final bundle sale price for each resulting bundle unit. BOX factor 12 means parent quantity 12; each component requirement equals 12 times its existing per-bundle quantity. Bundle preview must show the selected quantity and retain the authoritative per-bundle price label. Explicit no-bundle continuation retains the selected conversion.

Alternative: send an already-multiplied quantity from JavaScript. That risks double multiplication and trusts client factor data unnecessarily.

### 4. Preserve existing quantity and serial semantics
Manual cart quantities and plus/minus controls remain base-unit operations, including authorization rules for reductions. Each accepted new serial scan contributes one base unit and follows existing bundle intent, duplicate-serial, and row-targeting rules. Scanning a serial must not inherit a conversion from an earlier selection or an existing row. A conversion add for a serialized product still requires the resulting number of serials under existing checkout rules.

### 5. Focus verification on changed contracts
Extend focused POS search/scan/cart tests for business-scoped eligibility, stale flags, factor validation, multiplied bundle quantities, unchanged pricing, and serial behavior. Reuse targeted checkout stock/serial fixtures to establish component multiplication once without expanding into unrelated checkout scenarios. Provide a human browser checklist for modal ordering, repeated selection, cancellation, keyboard/double-click behavior, focus restoration, and bundle preview. Browser execution belongs to a human; automated completion must not claim it was performed.

## Risks / Trade-offs

- [Modal cleanup races or stale responses add the wrong product] → Operation tokens, immutable submission context, and explicit transition/cancel handling.
- [Conversion disabled after options loaded] → Server rechecks at submission and returns an error without fallback mutation.
- [Legacy decimal factor is silently truncated] → Reject before casting; leave stored definitions and historical evidence unchanged.
- [BOX bundle quantity surprises cashier] → Show the factor and resulting bundle count before submission.
- [Accidental double multiplication] → Send qty 1 with conversion_id and multiply only in PosCartService.
- [Existing merge and cached-price behavior differs across row types] → Preserve existing rules and verify representative ordinary/bundle additions without redesigning them.
- [Extra search queries] → Batch option resolution for all returned products.

## Migration Plan

No schema or data migration is needed. Deploy the response metadata, modal, JavaScript, and server validation together using the existing application deployment process. Revert those code changes to roll back; previously persisted base-unit quantities remain interpretable. Run focused automated tests before handoff and have a human execute the browser checklist before release acceptance.

## Open Questions

None. Integer factors are the supported scope, and the user confirmed factor-based bundle multiplication and serial increments of one.
