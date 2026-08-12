## Context

The Purchase and Sales lists are Livewire components whose search submission replaces table rows through DOM morphing. Purchase and Sales actions use Alpine state and teleported menus; Global Purchase Payment retains a Bootstrap/CoreUI dropdown partial, while Global Sales Payment uses the Sales Alpine action partial in global mode. The data query and returned rows are correct, but the action control can no longer expose its menu after a morph.

This change is a targeted recovery patch. It must restore existing actions without altering document searches, permissions, routes, payment eligibility, or the broader menu architecture.

## Goals / Non-Goals

**Goals:**

- Ensure every rendered Purchase, Sales, Global Purchase Payment, and Global Sales Payment result row has a working three-dot action menu after search, clearing a search, filtering, sorting, or pagination.
- Preserve the existing menu contents and authorization checks.
- Use stable Livewire identities for dynamic Sales rows and action instances.
- Restore the legacy Bootstrap/CoreUI Global Purchase dropdown after Livewire updates.
- Add browser coverage of the client-side interaction.

**Non-Goals:**

- Replacing all action-menu implementations with a shared component.
- Redesigning the current Alpine teleport strategy or changing menu styling/positioning.
- Changing document search predicates, list filters, payment calculations, or permissions.
- Removing every possible stale teleported node as part of this urgent patch.

## Decisions

### Force fresh action-menu instances for refreshed results

Action-menu roots will receive a stable, search-sensitive Livewire identity composed from the document ID and the current table refresh/search state. Sales table rows will also receive stable document-ID keys. A search result will therefore receive a newly initialized Alpine action instance rather than inheriting menu state from a DOM node previously used for another result.

This is chosen over a full menu rewrite because it is small, local to the affected views, and directly targets the failure occurring after DOM morphing. A single global menu component remains a better long-term lifecycle model, but is out of scope.

### Reinitialize only legacy plugin dropdowns after a table update

The Global Purchase Payment action partial uses Bootstrap/CoreUI dropdown markup rather than Alpine. Its dropdown controls will be safely initialized or refreshed after the Livewire table update, scoped to the affected table so other page controls are not disturbed.

This is chosen over a page reload because it preserves the user’s search result and filters. It is not used as a substitute for fresh Alpine instances because it cannot repair Alpine state.

### Verify in a real browser

The regression test will search for a known matching record, click the resulting row’s three-dot control, and assert a permitted action is visible. Coverage will span normal Purchase, normal Sales, Global Purchase Payment, and Global Sales Payment, including a follow-up search change or clear where practical.

Server-side Livewire tests remain useful for search correctness but cannot validate Alpine teleport state or plugin initialization after DOM morphing.

## Risks / Trade-offs

- [Repeated searches can retain invisible teleported nodes from earlier action instances] → The recovery patch accepts this short-term limitation; ensure only the current result menu is visible and schedule a menu-lifecycle refactor if DOM growth or recurrence is observed.
- [Plugin reinitialization can duplicate handlers] → Scope initialization to current Global Purchase action triggers and use the plugin’s idempotent/dispose API where available.
- [Search-sensitive keys reset an open menu during any list refresh] → This is desirable for predictable state and prevents a menu being associated with a replaced result.
- [Browser testing infrastructure may not be present] → Use the project’s available end-to-end/browser runner; if unavailable, document repeatable UAT steps alongside focused server-render tests.

## Migration Plan

1. Deploy as a UI-only change with no database migration or API contract change.
2. Validate the four list flows after deployment using search, clear-search, pagination, and filter refreshes.
3. Roll back the view/script changes if an unrelated dropdown regression occurs; persisted data is unaffected.

## Open Questions

- Confirm the project’s preferred browser test runner and CI entry point before implementing the regression test.
