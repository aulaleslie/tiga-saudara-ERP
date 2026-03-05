---
id: ticket-6
title: Ops Links and Visibility
status: queued
depends_on:
  - ticket-1
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - resources/views/layouts/menu.blade.php
  - resources/views/layouts/header.blade.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSReportingPackTest
  - php artisan test --filter POSReconciliationViewTest
  - php artisan test --filter POSLiveSessionMonitorTest
done_when:
  - Cashier flow exposes links to supported operations pages (reports/monitor/reconciliation/sessions/terminals) via allowed UI entry points.
  - Link visibility remains permission-aware.
  - No unsupported flow is exposed as fully operational from cashier UI.
---

## Objective

Make supported operational modules easily reachable from POS, without breaking current permission model.

## Scope

1. Ensure `Lainnya`/ops entry points map to existing POS pages.
2. Keep visibility controlled by existing permission checks.
3. Keep quick-open behavior in header/menu consistent.

## Out of Scope

1. New authorization model.
2. New report/reconciliation backend logic.
3. Non-POS module refactors.

## Implementation Steps

1. Add/update ops links from sell view where appropriate.
2. Respect existing guards and route middleware.
3. Confirm no cross-setting leakage in links.

## Validation Notes

1. Unauthorized users must still receive forbidden/hidden access.
2. Authorized users must reach each route from POS entry points.
