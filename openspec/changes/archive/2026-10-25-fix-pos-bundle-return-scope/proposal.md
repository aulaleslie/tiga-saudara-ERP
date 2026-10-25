# Proposal

## Why

A completed return for `TNC-TXN-2026-10-0638` returned the camera but omitted its split-owner microSD component, leaving Rp260,000 billable despite the user's whole-bundle intent. The non-serial return snapshot classified the parent as a normal product because its components were attached to another owner's zero-quantity carrier sale detail.

## What Changes

- Add an explicit cash-return scope per selected bundle parent: `whole_bundle` (default) or `parent_only`.
- Whole bundle includes the selected parent quantity and proportional original components across owner sales; parent only leaves retained components and their allocated charges unchanged.
- Resolve original bundle identity for non-serial as well as serial products using persisted checkout evidence, and block incomplete or ambiguous whole-bundle execution.
- Persist scope through draft editing, submission, approval preview, execution, and audit display. Show expected refund and debt reduction separately.
- Provide a dry-run-first, idempotent, audited correction for the omitted microSD in `TNC-TXN-2026-10-0638`, without replaying the completed camera return.
- Exclude new individual-component cash returns and changes to replacement behavior.

## Capabilities

### New Capabilities

- `pos-return-bundle-correction`: Evidence-based, narrowly scoped correction of an omitted component from a historical whole-bundle return.

### Modified Capabilities

- `pos-return-draft-resolutions`: Explicit whole-bundle and parent-only cash-return intent, defaulting new bundle selections to whole bundle.
- `pos-return-approval-preview`: Display and revalidate selected bundle scope, retained charges, complete source composition, refund, and debt effects.
- `pos-return-approval-execution`: Execute scope-sensitive physical and commercial reversals across owner sales.

## Impact

Affected areas include `Modules/Pos` return services, the shared bundle resolver, Livewire create/edit forms, approval planning/persistence, return detail display, additive scope persistence, and focused feature tests. Reuse existing Sales Return, owner/location stock buckets, serial, payment, and audit services. No new dependencies or checkout-posting rewrite. Historical returns must not be inferred as deliberate parent-only selections or silently upgraded to whole-bundle returns.

The correction targets POS Return `111` (`TNC-POSRT-2610-0005`), component Sale `210539` (`PD-JL-2026-10-00653`), SaleBundleItem `900`, and DispatchDetail `424502`, subject to fresh validation. Proposal creation performs no application or database mutation.
