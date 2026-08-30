## Context

Global Purchase Payment and Global Sales Payment deliberately use dedicated cross-setting controllers and render the normal detail templates with `globalMode = true`. That flag hides general transaction mutations and the shared date-adjustment modal. Existing monetary edit controllers and their Livewire forms enforce the active session setting, while reporting-date and due-date policies also require the active setting for ordinary users. Consequently, exposing existing URLs in Global Payment would either fail for another business or tempt a broad relaxation of normal-route tenant guards.

The existing adjustment implementations already encode the important domain rules: lifecycle-specific monetary edit modes, one-to-one detail preservation, protected operational fields, atomic date audits, and granular permissions. The change should add a trusted Global Payment context around those implementations rather than duplicate their business logic.

## Goals / Non-Goals

**Goals:**

- Expose monetary-only and combined date adjustments from purchase and sale Global Payment details when all applicable permissions are present.
- Support transactions belonging to a setting other than the active session setting.
- Preserve the viewed transaction's setting as the validation, presentation, and persistence context.
- Preserve normal route setting guards and all existing monetary/date domain constraints.
- Return users to a coherent Global Payment destination after adjustment.

**Non-Goals:**

- General cross-setting purchase or sale editing.
- Exposing approval, receiving, dispatch, return, archive, deletion, duplication, attachment, or correction actions globally.
- Adding permissions, changing monetary fields, changing audit schemas, or combining monetary and date changes into one save.
- Reworking Global Payment eligibility, summaries, allocation, or payment services beyond refresh behavior after an adjustment.
- Planning or running the full application test suite.

## Decisions

### Use explicit global adjustment routes

Add dedicated purchase and sale Global Payment routes for monetary edit entry/update and date adjustment. Each route requires the applicable Global Payment access permission in addition to existing adjustment permissions and resolves the transaction without the active-setting ownership guard.

Normal `purchases.*`, `sales.*`, and ordinary date-adjustment routes retain their existing active-setting behavior. A query parameter or conditional bypass on normal routes is rejected because it could broaden unrelated mutations and makes authorization depend on client-controlled context.

### Reuse existing edit modes, forms, and services

Global monetary entry resolves `Purchase::resolveEditMode()` or `Sale::resolveEditMode()` and proceeds only in `MONETARY_ONLY` mode. It reuses the current edit presentation and save logic, with an explicit return context and the document's actual setting supplied for settings, PKP, payment-term, counterparty, and validation lookups.

The global path does not provide full editing for approved but unfulfilled documents. Although their model edit mode can be `FULL`, the requested capability is monetary adjustment from a payment workspace, and allowing full cross-setting editing would exceed the intended exception.

Extracting shared controller/service orchestration is preferred where necessary to prevent the global and normal save paths from diverging. Copying the monetary persistence logic into Global Payment controllers is rejected.

### Make adjustment context server-authoritative

The global route and its middleware establish global context; hidden form inputs may carry a return destination but do not grant cross-setting authority. The server reloads the target document, verifies it is non-archived and base-eligible for Global Payment inspection, checks global access, checks ordinary edit plus lifecycle monetary permission for monetary changes, and checks the applicable field-specific override permissions for date changes.

Date authorization receives an explicit trusted global-adjustment context so an ordinary authorized user may target the viewed document's setting. Super Admin retains the existing application-wide bypass. A user holding only Global Payment access receives no adjustment authority.

### Keep the combined date UI field-granular

The existing shared modal is rendered in Global Payment context when the user can adjust at least one field. Reporting-date and due-date controls remain independently visible according to their existing permissions. The global form submits to a dedicated global date-adjustment endpoint and continues using the existing atomic `DocumentDateAdjustmentService` and audit behavior.

### Render only the two adjustment families

The Global Payment detail footer gains:

- `Ubah Nilai (Moneter)` only for an eligible fulfilled document whose resolved edit mode is monetary-only.
- `Penyesuaian Tanggal` when at least one reporting/due-date override ability is authorized in global context.

Existing global hiding for every other footer/action remains in place. Purchase received-correction remains separate and is not exposed globally.

### Redirect by current eligibility after save

After a successful global adjustment, redirect to the same global detail when the document remains base-eligible for global inspection. If its new monetary state or concurrent activity makes that detail unavailable under the current global route contract, redirect to the corresponding Global Payment index with a success message. Redirect targets are selected server-side; arbitrary return URLs are not accepted.

Date-modal AJAX success may reload the same detail because date changes do not alter base lifecycle eligibility. The UI and summary/list state may naturally reflect a changed due-date or reporting-date filter after navigation.

## Risks / Trade-offs

- [A normal-route tenant guard is accidentally weakened] → Add dedicated global routes and focused assertions that ordinary cross-setting edit/date requests remain forbidden.
- [Global access becomes implicit edit authority] → Require global access and every existing ordinary/lifecycle or field-specific permission at both rendering and backend boundaries.
- [Forms calculate against the active setting] → Resolve settings-dependent inputs from the document's actual `setting_id` and cover a mismatched-session case.
- [Normal and global monetary behavior drift] → Share the existing edit-mode and persistence orchestration rather than clone calculations.
- [A save removes the row from the active payment view] → Re-evaluate the safe return destination and fall back to the global index.
- [The shared modal exposes an unauthorized field] → Compute reporting and due-date abilities independently in trusted global context and retain backend rejection tests for tampered payloads.
- [Existing read-only contract tests fail] → Replace only assertions affected by these two adjustment families and retain assertions for all unrelated mutation controls.

## Migration Plan

1. Add the dedicated global adjustment routes and server-side context/authorization support.
2. Reuse the monetary edit and date-adjustment implementations with actual-setting resolution and global return handling.
3. Render permission-specific actions in both Global Payment detail pages.
4. Run focused purchase and sale tests for touched paths and likely regressions.

Rollback removes the added routes and controls and restores universal read-only Global Payment detail behavior. No data migration or cleanup is required; adjustments already completed remain valid ordinary audited document changes.

## Open Questions

None. The proposal intentionally limits global monetary access to post-fulfillment monetary-only mode and composes Global Payment access with existing granular permissions.
