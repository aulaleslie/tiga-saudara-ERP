## Context

The authenticated `/home` route currently renders `resources/views/home.blade.php`, which contains report summary cards and three Chart.js areas. Those elements are already protected in the view by `reports.access`, and their data is supplied through existing AJAX routes handled by `HomeController`. The sidebar links Home to `/home`, but its Dashboard entry has no route and points to `#`.

Home is also the framework's configured post-authentication destination. This change therefore separates the landing and reporting concerns without changing the login redirect or any reporting, purchase, sale, payment, or POS workflow logic. User records have a single normalized `name` field rather than a separate first-name field, and permissions use the existing Spatie permission names.

## Goals / Non-Goals

**Goals:**

- Keep `/home` as the authenticated landing destination.
- Move the complete existing report presentation from Home to a working `/dashboard` page.
- Add a deterministic Indonesian time-based greeting using the authenticated user's first name.
- Provide five Quick Access destinations filtered by the exact permissions and POS setting needed to perform each action.
- Preserve existing route middleware conventions, Blade/CoreUI styling, report authorization, and chart behavior.
- Verify only the routes, presentation behavior, permission/configuration visibility, and greeting boundaries touched by this change.

**Non-Goals:**

- Changing chart queries, report values, AJAX response formats, or report authorization.
- Changing login, role-setting selection, transaction, global-payment, or POS session business logic.
- Introducing first-name storage, user profile changes, database migrations, packages, or a full test-suite requirement.
- Granting permissions or exposing an action merely because its destination route can technically be requested.

## Decisions

### 1. Keep Home as the landing route and introduce a named Dashboard route

`RouteServiceProvider::HOME` and the authentication controllers continue to target `/home`. A new authenticated, `role.setting`-protected Dashboard route will invoke a Dashboard-facing controller action and render a new Dashboard Blade view. The existing Home view becomes the greeting and Quick Access experience.

Alternative considered: redirect `/home` to `/dashboard`. This would remove the requested Home experience and change the established post-login destination, so it is rejected.

### 2. Move report presentation while retaining its existing backend behavior

The existing cards, chart markup, Chart.js asset inclusion, page script push, `reports.access` checks, and template variables move together to the Dashboard view. Existing chart AJAX routes and calculation methods remain in place. The controller's Dashboard action supplies the same view data formerly supplied by `index`; `index` only supplies what the new Home view needs.

Alternative considered: rename or relocate every chart endpoint under a Dashboard URL. This adds compatibility and regression risk without user-visible benefit and violates the no-logic-change constraint.

### 3. Derive greeting and first name at render time using application-local time

Greeting selection uses Laravel/Carbon's current application-local time with explicit hour boundaries: morning 04–10, midday 11–14, afternoon 15–17, and evening/night 18–03. The first name is the first token after trimming and splitting the authenticated user's existing `name`; its stored casing is preserved.

This derivation can live in the controller or a small presentation-oriented helper, with tests freezing time at boundaries. It does not require schema or profile changes.

Alternative considered: JavaScript/browser-local greeting. Server-side application time is deterministic, respects the configured ERP timezone, avoids content changing after render, and is easier to cover with focused tests.

### 4. Treat Quick Access visibility as an intersection of all action prerequisites

Blade authorization checks use the existing permission names and existing named routes:

| Action | Destination | Visibility prerequisites |
|---|---|---|
| Buat Pembelian | `purchases.create` | `purchases.create` |
| Buat Penjualan | `sales.create` | `sales.create` |
| Buka Sesi POS | `pos.sessions.create` | current setting has POS enabled, `pos.access`, and `pos.sessions.open` |
| Buat Pembayaran Pembelian Global | `purchases.global-payments.index` | `purchasePayments.global.access` and `purchasePayments.create` |
| Buat Pembayaran Penjualan Global | `sales.global-payments.index` | `salePayments.global.access` and `salePayments.create` |

The global payment links target their existing workspaces because their direct create routes require a selected supplier or sale. Requiring both access and create permissions ensures the labels promising creation are not shown to read-only users. The Quick Access card remains present with an empty state when all actions are filtered out.

Alternative considered: check only the permission used by each index route. That could display creation-labeled actions to users unable to create the resulting payment and does not meet the required-action interpretation.

### 5. Align sidebar navigation and active state with named routes

The placeholder Dashboard anchor becomes a link to the new named route. Home and Dashboard each use route-aware active classes, following the sidebar's existing pattern. Other links that intentionally return users to Home remain unchanged unless their text specifically promises Dashboard.

## Risks / Trade-offs

- [A permission-protected action may still encounter record-specific business validation after navigation] → Quick Access promises entry to the existing workflow, not guaranteed transaction completion; retain all destination-side validation.
- [A user name containing leading whitespace or a single word could produce poor addressing] → Trim before tokenization and support a single token directly; authenticated records already normalize names on assignment.
- [Report assets could be omitted during the view move] → Move chart markup, third-party script section, and Vite page-script push as one unit and add a focused Dashboard rendering assertion.
- [POS permission alone could expose a dead link when POS is disabled] → Include the current setting's `pos_enabled` state in visibility, matching the destination middleware.
- [Home users with no eligible actions could see an empty container] → Render an explicit empty-state message while keeping the requested Quick Access card visible.

## Migration Plan

1. Add the Dashboard route/controller entry point and Dashboard view containing the existing report presentation.
2. Replace Home's report presentation with greeting and permission-aware Quick Access content.
3. Connect and activate the Dashboard sidebar item.
4. Run focused feature/view tests for Home, Dashboard, time boundaries, permission combinations, POS enablement, and links.

Deployment requires no data migration. Rollback consists of restoring the original Home view/controller mapping and reverting the Dashboard sidebar item and route; existing chart endpoints remain compatible throughout.

## Open Questions

None. The greeting boundaries, permission intersections, destinations, and empty state are defined for implementation.
