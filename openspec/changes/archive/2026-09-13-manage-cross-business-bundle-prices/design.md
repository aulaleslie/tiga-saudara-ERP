## Context

The existing cross-business product price page loads one base-price row per setting and a setting-by-conversion matrix, then persists both through `CrossBusinessPriceService` in one transaction. It uses signed conversion snapshots and row timestamps to reject stale or structurally forged submissions. Bundle prices are currently managed from setting-scoped bundle edit pages; a grouped edit can optionally propagate one sale price to all copies sharing the route bundle's persisted `replica_group_uuid`.

`product_bundles` already contains the required lineage and pricing data. Local data contains 483 grouped rows across 70 replica groups, no null group identities, intentionally different per-setting prices in three groups, and missing group/setting combinations caused by later business creation or partial group membership. The design therefore must preserve three distinct states: an existing positive-priced copy, an existing zero-priced copy, and no bundle copy.

## Goals / Non-Goals

**Goals:**

- Add a setting-by-replica-group bundle price matrix to the existing page.
- Allow independent price changes for every existing bundle copy while providing a group-scoped apply-to-all shortcut.
- Preserve missing bundle copies as visibly unavailable, non-submittable cells.
- Validate authorization, product ownership, setting membership, replica lineage, matrix structure, and loaded versions before writing.
- Commit base, conversion, and bundle price changes atomically and emit existing feed events for qualifying bundle changes.
- Follow the page's current view/edit/cancel, Rupiah masking, validation restoration, and duplicate-submit conventions.

**Non-Goals:**

- Creating or backfilling missing bundle copies.
- Inferring replica groups from names, composition, prices, or other mutable attributes.
- Editing bundle names, descriptions, components, active dates, enabled state, or informational component prices.
- Removing the existing setting-scoped bundle edit and its explicit synchronize-price option.
- Adding a new permission, route, bundle database column, external dependency, or browser automation suite.

## Decisions

### Add forward migration for shared operation UUID on feed events

`product_price_feed_events.operation_uuid` was originally defined with a unique constraint. Because combined saves emit separate event rows for base product prices and each changed bundle replica group, all sharing the same `operation_uuid`, a forward migration drops the unique constraint `product_price_feed_events_operation_uuid_unique` and creates a non-unique index `product_price_feed_events_operation_uuid_index`. Its `down()` migration disambiguates any duplicate UUIDs prior to restoring uniqueness to allow safe rollbacks.

### Build columns from persisted replica lineage

Load bundles whose `parent_product_id` equals the routed product and whose `replica_group_uuid` is non-null. Group by UUID and render one column per group, with rows keyed by the complete current setting list. A cell is editable only when the group contains an actual bundle row for that setting.

This uses the stable lineage deliberately introduced for cross-business bundle operations. Grouping by name or composition was rejected because those fields are independently editable and existing bundle requirements prohibit inferred lineage.

### Preserve missing-copy semantics

A missing group/setting intersection renders `Paket tidak tersedia`, remains read-only in every page mode, has no price input, and is omitted from the payload. It is not displayed as zero. Existing zero-priced rows remain editable and display as an explicit zero.

Automatically creating a bundle was rejected because price management lacks the composition and lifecycle decisions required to create a valid setting-scoped copy, and a new setting is explicitly not entitled to automatic historical bundle replication.

### Use trusted bundle IDs for submitted cells and verify the complete loaded structure

Each existing cell submits its bundle ID, setting ID, sale price, and loaded version. The signed loaded-state evidence is extended or complemented with bundle structure containing the routed product ID, current setting IDs, each group UUID, and each existing cell's bundle ID, setting, stored price, and version.

During save, the service locks the routed product and relevant bundle rows, rebuilds current membership from the database, and compares it with trusted loaded-state evidence. It rejects added, removed, moved, duplicated, foreign, or stale cells. The server derives product and replica membership from persisted rows; a submitted UUID cannot select update targets.

Reusing only unsiged hidden inputs was rejected because it would allow a forged bundle ID or lineage to redirect cross-business updates. Checking only changed cells was rejected because a concurrent structural change elsewhere in the displayed matrix would leave the page representation stale.

### Keep apply-to-all as a client-side, existing-cell-only operation

When an existing bundle price changes, its apply-to-all control copies the numeric value to every other existing editable cell in that replica-group column. Missing cells remain unchanged and unavailable. Copying changes form state only; Save remains explicit and Cancel restores each cell's loaded value.

This mirrors base and conversion price behavior while respecting the stronger missing-copy semantics of bundles.

### Extend the existing transaction and event recording

Bundle validation and writes occur inside the same transaction as base and conversion prices. Any validation, conflict, persistence, or feed-recording failure rolls back all three sections. Only changed bundle rows produce `bundle_price_updated` snapshots, with their setting IDs and one operation UUID for the save. Unchanged bundle cells do not create noise in the feed.

A separate bundle endpoint or transaction was rejected because it could leave the page partially saved and contradict the current combined-save behavior.

### Retain inactive groups and tolerate defensive null-lineage handling

Inactive existing bundles remain visible, marked `Tidak aktif`, and their prices remain editable. Although the inspected local database has no null replica identities, the loader defensively excludes null-lineage bundles from the grouped matrix rather than guessing a relationship. If a group has differing display names, the header uses a deterministic representative name and indicates that names differ across businesses.

Hiding inactive groups was rejected because it would prevent comprehensive price maintenance. Treating null-lineage records as singleton columns was rejected for this change because the agreed column count is specifically the selected product's distinct non-null `replica_group_uuid` values.

### Keep verification focused

Automated verification will use focused Product module feature tests for loading, authorization, validation, stale-state rejection, atomic persistence, feed events, and price masking/apply-to-all behavior. A human will perform browser validation of the rendered matrix and page interactions. A full application test-suite run is outside this change plan.

## Risks / Trade-offs

- [Wide tables as bundle counts grow] → Keep the section horizontally scrollable using the existing responsive-table pattern. Current local data has at most five bundle groups for one product.
- [Group names can diverge across settings] → Use UUID as identity, a deterministic name only as presentation, and show a differing-name indicator.
- [A business can be added while the page is open] → Include the complete setting list in trusted loaded-state evidence and reject stale saves.
- [Bundle rows can be edited or deleted concurrently] → Lock relevant rows and compare price, version, membership, and identity before applying any writes.
- [Existing form complexity increases] → Isolate bundle matrix loading/snapshot mapping within the service and reuse current masking/edit-state conventions rather than introducing a second form lifecycle.
- [Inactive or partially deleted groups may surprise users] → Mark status explicitly and render absent copies as unavailable rather than silently filling gaps.

