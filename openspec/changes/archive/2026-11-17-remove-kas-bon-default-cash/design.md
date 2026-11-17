# Design

## Context

See proposal.md for motivation. Review rendering currently creates a cash row for the full live due. The existing summary calculation already derives amounts from selected rows and disables submission when none exist. Payment methods are cached, so populating the picker only on initial load does not reliably clear a previous selection on later reviews.

## Goals / Non-Goals

**Goals:** Make review initialization consistently empty, including the cached method picker, while reusing existing calculations and explicit add-method behavior.

**Non-Goals:** Change settlement services, ordinary checkout defaults, APIs, payment evidence handling, or the amount assigned when a cashier explicitly adds a method.

## Decisions

- Initialize selected methods as an empty array and reset the picker to its placeholder on every newly loaded review. Remove the automatic cash-row construction and initialize the derived payment amount consistently to zero. Rely on existing rendering and summary validation. Keeping a zero-value CASH row was rejected because it still selects a method implicitly.
- Preserve explicit add-method allocation of the remaining due. Changing this amount default would expand the requested behavior.
- Extend the existing DOM regression to assert the empty initial state, explicitly add CASH before its existing payment scenarios, and cover subsequent review/reopen and final-row removal. Run only `node tests/js/pos-kas-bon-collection-state.test.cjs`, plus a short manual check when a running POS is available. No full PHP or JavaScript suite is required for this client-only change.

## Risks / Trade-offs

- Cached picker state could retain a previous choice -> explicitly clear the picker on review initialization and cover a pending choice across reviews.
- Updating initial test expectations could weaken existing mixed-payment coverage -> retain its amount, notes, reference, evidence, and submission assertions after explicitly adding CASH.

## Migration Plan

Deploy the JavaScript asset through the existing release process. No data migration is needed. Rollback restores the prior asset and automatic CASH selection.
