# Design

## Context

See proposal.md for motivation. `/settings` already edits the active `Setting` via `SettingController`, `StoreSettingsRequest` and `setting::index`. POS enablement, saved transactions and walk-in customer controls currently sit among general settings fields. Updates synchronize session `user_settings` and invalidate `settings_{id}` cache.

Receipt services generate the existing current/historical/preview payloads and `pos::receipt` renders notes, inline item annotations, return-time comparisons, return history, fixed 28 mm tail space and a footer combining PPN with thanks. Current behavior and the recent return corrections are the baseline. No financial recalculation or section relocation is required.

## Goals / Non-Goals

**Goals:** One business-owned source of visibility defaults, fresh configuration per receipt build, independent rendering switches, existing content and financial behavior preserved.

**Non-Goals:** A new settings screen, moving payment-method/location configuration, a separate price-history layout, changes to transaction prices or returns, snapshotting preferences per sale, per-print overrides, configurable stamp-space height, or full-suite verification.

## Decisions

### Persist five booleans on settings

Use additive boolean columns with non-null database defaults and matching model casts/defaults:

| Column | Default |
| --- | --- |
| `pos_receipt_show_note` | false |
| `pos_receipt_show_return_history` | false |
| `pos_receipt_show_price_adjustments` | false |
| `pos_receipt_show_ppn` | false |
| `pos_receipt_show_signature_space` | true |

The migration establishes these defaults on existing rows; new rows inherit them. Do not modify transaction snapshots. A narrow resolver normalizes missing/null legacy values with null-coalescing rather than truthiness, preserving explicit false. Separate columns fit existing settings booleans and simplify validation versus adding a new preferences table or arbitrary JSON setting schema.

### Extend the existing business settings flow

Group existing inline POS fields under `Pengaturan POS`, and add a receipt-appearance subsection with five labeled switches. Preserve selectors and customer-search JavaScript. Reuse the current settings save action and permissions, validate booleans, and explicitly submit unchecked values (including false for signature space). Keep preferences editable and persistable when POS is disabled so enablement does not silently reset receipt choices. Add fields to session synchronization and retain cache invalidation. Separate configuration forms stay where they are; general row-total rounding remains general business configuration.

### Resolve configuration at receipt-build time

Add one normalized visibility payload, e.g. `receipt_visibility`, from a fresh settings read keyed by the authorized receipt's `setting_id`; previews use the transaction's setting. Avoid a stale loaded relation, session user-settings snapshot or checkout snapshot as the preference source. Resolve once per receipt build and share across ordinary, historical, global-payment and preview builders; derive projected data from the same payload rather than duplicate defaults in controllers. If a payload is absent in a legacy direct-render fixture, the view's fallback matches the agreed defaults. Rebuilding after save must see the new values; an already open page need not update live.

Filtering at the render boundary was chosen over suppressing return/price data generation: visibility must not bypass provenance checks, financial validation, return effects or existing projection failure behavior.

### Gate existing markup without relocation

Wrap note and return-history containers with their flags and existing content checks. Gate all inline price annotations and return-time comparison fragments with the price-adjustment flag, retaining historical/preview exclusions. Return-time comparisons remain nested in return history and therefore require both flags. Do not introduce a standalone price-adjustment section or move hidden information elsewhere; the user's final scope is visibility only.

Split the existing combined footer markup into separately renderable PPN and closing content without changing text, typography or order when both are visible. Stamp-space visibility controls the 28 mm spacer and its associated tail divider/margins; when hidden remove the reserved gap. PPN must remain independently visible even without the stamp-space divider, and thanks/receipt number always remain. Preserve shared spacing needed by visible siblings but remove spacing/dividers owned only by hidden blocks.

### Explicitly update existing display contracts

Current specs require notes, return history and PPN unconditionally. Delta specs qualify display by the business switches while preserving eligible content behavior. Prices, balance and return-state facts remain mandatory in the main receipt regardless of explanatory visibility. Existing receipt-content tests that deliberately inspect optional text must enable the relevant switches in their fixtures rather than override production defaults.

### Focused verification only

Use a compact parameterized rendering matrix for the five flags rather than all 32 combinations. Verify defaults, individual visibility, the return-history/price-comparison interaction and footer independence. Add targeted settings save/authorization/business-isolation checks and one configuration-change/reprint check across representative existing entry points, including historical/preview. Compare financial payloads and main-line output across visibility configurations on an adjusted/returned transaction. Reuse existing fixtures; run named tests or precise filters sequentially because test processes use the shared SQLite test file. No full suite or `composer test:fresh-sqlite` run is planned.

## Risks / Trade-offs

- [Existing receipts become quieter immediately] → Explicitly requested defaults apply to all businesses; operators can enable the four information sections in settings.
- [Stale settings cached on a transaction or session] → Fresh business-owned resolution per build plus existing cache/session synchronization after save.
- [Hiding explanations accidentally hides financial corrections] → Rendering-only gates, with comparisons of financial/main-purchase output across toggle values.
- [Shared footer markup hides thanks with PPN or leaves blank space] → Independent fragments and focused assertions for PPN/signature combinations.
- [Existing tests assume optional text always appears] → Explicit opt-in in affected fixtures; retain defaults tests to prevent silent compatibility fallbacks.

## Migration Plan

Deploy the additive settings migration with the application changes. Existing and new businesses receive OFF/OFF/OFF/OFF/ON defaults; no transaction or return backfill is needed. Roll back application rendering first if necessary; removing columns later discards only visibility preferences. Financial data and receipt snapshots are unaffected. Keep all archived OpenSpec history intact.
