# Design

## Context

See proposal.md for motivation. `SplitPosCheckoutPostingAdapter::post` derives the terminal business from the checkout context, copies that context for each owner group, and replaces `setting_id` with `source_setting_id`. `InlinePosCheckoutPostingAdapter::post` subsequently passes that replaced setting to `assertSerialCurrentlyPostable` at both parent and component call sites. That helper uses `SalesLocationResolver` to check enabled sales locations.

`POSSplitSerialBundleCheckoutTest`, `POSCheckoutSplitPostingTest`, and `POSSerialValidationCheckoutTest` provide existing feature fixtures. Location resolution is cached, so fixtures that alter sales-location settings must invalidate the relevant business caches. Existing specs require allowed-source checks but do not explicitly distinguish the originating business from the source owner; the delta makes that distinction explicit.

## Goals / Non-Goals

**Goals:** Separate the business authorizing serial access from the business owning the generated sale using a minimal internal context addition.

**Non-Goals:** No new permission model, allocation rewrite, resolver/cache behavior change, settings workaround, or editing of the reported transaction.

## Decisions

### Preserve a server-derived origin identity in posting context

Before switching the owner group's `setting_id`, set `pos_setting_id` from the original checkout context's business identity. This value must come from the trusted checkout service context, never from client request fields or a saved draft's arbitrary metadata. Retain `setting_id` as the source owner for all existing posting behavior.

Alternative: leave `setting_id` unchanged and add a separate owner field. Rejected because it changes many existing consumers of posting ownership. Enabling the warehouse in the source owner's configuration is also rejected because it changes business policy to accommodate a validation defect.

### Use the origin only in the locked serial location check

In the inline adapter resolve the serial authorization business as `pos_setting_id ?? setting_id`, and supply it to `assertSerialCurrentlyPostable` for both parent and component serials. Name local variables and the helper argument to express location authorization. Do not replace ownership variables elsewhere. Preserve row locking, canonical sellability, pending dispatch guards, and the existing atomic transaction boundary.

Alternative: remove the final location check or trust earlier allocation alone. Rejected because serial location can change after assignment. Looking up terminal/session state again is unnecessary when the trusted original context already carries the identity.

### Verify behavior with existing feature fixtures

Add parent and component success cases with the location enabled for the originating business and disabled for its owner. Assert owner-aligned sale/dispatch and inventory effects and correct serial linkage/history where applicable. Add rejection coverage for the inverse permission arrangement and a location change between earlier validation and final locked posting, exercising the posting boundary through existing test hooks or adapter fixtures. Retain inline fallback coverage and run existing serial lifecycle tests.

## Risks / Trade-offs

- [Origin accidentally reused for ownership] → Restrict its use to serial location authorization and assert owner-aligned posting in regression tests.
- [Only parent call site updated] → Cover serialized bundle components independently.
- [Client-provided identity bypass] → Derive origin inside the server-side split adapter from trusted checkout context.
- [Cache masks fixture permissions] → Invalidate `SalesLocationResolver` caches when fixture settings change; do not alter production caching.

## Migration Plan

Deploy the adapter change and focused tests through the normal application release. No database migration or draft rewrite is required. Existing drafts can be retried through normal checkout after deployment; this plan does not execute the reported payment. Rollback is a code revert and restores the previous rejection behavior.
