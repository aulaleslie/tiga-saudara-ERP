## Context

ProductUnitConversion stores shared unit/factor/barcode metadata. ProductUnitConversionPrice stores prices by conversion and setting (business). ProductController and ProductCreator seed initial prices across existing settings and update existing prices for the current setting. Sales ProductCart reads these prices directly. Purchase uses Product::eligiblePurchaseConversions(), with historical snapshot fallback and duplication handling. POS has separate scan/search lookup and cached pricing-basis paths.

UnitConfiguration already separates hidden canonical prices from visible RP values, but its parser removes all dots even when editing raw decimals. Existing nominal-field specifications already require raw `1234.56` to survive.

## Goals / Non-Goals

**Goals:** Independent business-local sales/purchase switches, enabled defaults, disabled Sales/POS barcode rejection, purchase eligibility enforcement, and reliable conversion-price input.

**Non-Goals:** Global conversion metadata redesign, factor precision changes, historical transaction rewriting, mid-cart POS repricing policy changes, inventory/stock-transfer scan restrictions, new-business provisioning redesign, full-suite testing, or agent-run browser testing.

## Decisions

### Store switches beside business prices

Add non-null boolean `sales_enabled` and `purchase_enabled`, default true, to product_unit_conversion_prices with boolean casts. This reuses the existing conversion/business identity; a separate preferences table would duplicate it, while global flags would violate business isolation. Missing business rows imply enabled for eligibility only; existing missing-price behavior remains unchanged.

Seed new conversion rows with enabled flags for every existing business. Product-edit controls start enabled for newly added rows; an explicit choice to disable during creation applies only to the acting business after seeding. Existing price-only writes/backfills must preserve stored flags, and omitted fields on existing rows must not reset disabled values. Explicit false values must survive request normalization, validation, old input, Livewire row changes, and persistence. Derive setting identity from trusted application context; never accept another business ID through a conversion row.

### Use workflow-specific eligibility

Expose reusable business-aware checks on the conversion/business record, using preloaded prices where practical. Do not globally filter the conversions relationship: stock denomination, historical rendering, and other businesses still require shared conversions.

Sales automatic pricing skips disabled conversion candidates and keeps existing normal-price fallback and enabled-candidate behavior. POS scan resolver, exact conversion-barcode search, and pricing-basis construction all consult sales eligibility for the acting business. A disabled conversion barcode produces an actionable rejection before cart mutation, without falling through to a base-product addition or alternate search route. Ordinary product/base-barcode selection remains available under existing rules.

POS captures only eligible packing candidates for newly added lines. Existing cached pricing bases continue to follow the current frozen-at-add contract; toggling a flag does not trigger new pricing queries on quantity changes. New scans always check current eligibility, including scans that would merge into an existing line.

Product::eligiblePurchaseConversions must receive/resolve the acting business explicitly and combine the new flag with existing unit, factor, base-unit, and serialization rules. Apply this in dropdown loading, new selection validation, save normalization, and duplicate intent resolution. Preserve persisted historical snapshots; unchanged historical lines retain existing edit/receiving rules. New or switched selections cannot use disabled conversions. Duplication follows its existing base-unit fallback when the original conversion is no longer eligible. Do not allow the historical dropdown fallback to authorize a newly submitted disabled conversion.

### Treat editing values as canonical decimals

Keep the hidden raw numeric value as the source of truth. On focus show its ungrouped dot-decimal representation; while typing parse raw decimal syntax without removing dots. On blur format the canonical value with RP prefix, dot grouping, comma decimal, and two decimal places. Submitting an already blurred field must reuse the canonical value rather than reinterpret formatted display as raw text. Preserve native input events, dynamic-row binding, validation round-trips, and existing server support for explicitly formatted legacy requests. Incomplete input must not silently become a different valid price. Reuse existing formatting helpers if they meet this contract; do not add a new formatting dependency. Factor inputs retain existing precision.

## Risks / Trade-offs

- Alternate scan/search paths could bypass rejection → trace Sales/POS entry routes and cover disabled scan plus exact-search fallback with focused tests.
- Default true could overwrite explicit false → test price-only updates, business isolation, and migration defaults.
- Historical fallback could leak into new purchase selection → separate persisted snapshot acceptance from new selection eligibility.
- POS cart flags can become stale after administrative edits → retain existing frozen basis intentionally; check every new scan/add against current flags.
- Formatter event order could multiply decimal values → verify focus/input/blur/submit and repeated formatting with a focused non-browser harness where available; human performs browser acceptance.

## Migration Plan

Deploy an additive Laravel migration compatible with the project's MySQL/MariaDB and SQLite patterns, giving existing rows enabled defaults, then deploy flag-aware reads/writes. No historical amounts or conversion definitions are rewritten. Rollback application behavior and drop only the two added columns if rollback is required; exported flag choices would be needed to restore preferences after a destructive schema rollback.

## Verification

Run only focused Product, Sales/POS, and Purchase tests relevant to changed behavior and a small formatter regression check where supported. Verify migration defaults, two-business isolation, independent flags, scan rejection, pricing exclusion, purchase selection/duplicate guards, historical preservation, and raw decimal round-trips. Do not run a full suite, add browser automation, or require implementation-agent browser testing. Provide a short human browser checklist covering switches/business switching and numeric row lifecycle.

## Open Questions

None blocking. Cached POS lines retain the existing freeze policy; this change governs new capture and scans.
