## Context

The system currently uses a supervisory approval workflow for sensitive POS actions (e.g., cart clears, price overrides). While the backend infrastructure is functional, the UI reflects a "cashier-first" access model where the approval queue is only found in a dropdown on the POS Sell screen. Because the POS Sell screen requires an active session, supervisors—who generally do not run active cashier sessions—cannot reach the menu to approve requests.

## Goals / Non-Goals

**Goals:**
- Provide a persistent, top-level navigation link for POS supervisors.
- Maintain existing RBAC (Role-Based Access Control) using the `pos.supervisor.approval` permission.
- Ensure the link is only shown when the POS module is enabled.

**Non-Goals:**
- Modifying the approval workflow logic.
- Changing permissions or roles.
- Removing the link from the POS Sell screen (it remains useful for supervisors who *are* also running a session).

## Decisions

### 1. Sidebar Placement
The "Antrian Persetujuan" link will be added to `resources/views/layouts/menu.blade.php`, inside the existing **POS** dropdown group.
- **Rationale**: Keeps all POS-related activities grouped together while making the submenu item visible alongside others like "Sesi POS" or "Transaksi POS".
- **Alternative**: Adding it as a standalone top-level menu. Rejected because it's too specific to the POS module and would clutter the root sidebar.

### 2. Guard Logic
The link will be guarded by:
- `@if($posEnabledForCurrentSetting)`
- `@can('pos.supervisor.approval')`
- **Rationale**: Aligns with the visibility patterns used for other POS menu items.

## Risks / Trade-offs

- **[Risk] Confusion with general approvals** → The label "Antrian Persetujuan (POS)" or clearly placing it under the POS heading will mitigate confusion with other approval workflows (like purchase returns).
