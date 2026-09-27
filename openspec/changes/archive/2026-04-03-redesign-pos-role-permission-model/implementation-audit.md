## POS Permission Inventory Audit

### Active Runtime Permissions

- `pos.access`
- `pos.sell`
- `pos.checkout.payment`
- `pos.sessions.open`
- `pos.sessions.close`
- `pos.sessions.close-admin`
- `pos.sessions.approve-variance`
- `pos.sessions.view`
- `pos.safeDrops.create`
- `pos.safeDrops.approve`
- `pos.cart.clear`
- `pos.cart.line.remove`
- `pos.cart.line.reduce`
- `pos.overrides.price`
- `pos.overrides.discount`
- `pos.void`
- `pos.supervisor.approval`
- `pos.reports.access`
- `pos.reconciliation.access`
- `pos.receipts.reprint`
- `pos.terminals.access`
- `pos.terminals.edit`
- `pos.transactions.view`
- `pos.transactions.save`
- `pos.transactions.load`
- `pos.transactions.edit.any`

### Missing But Required Before This Change

- `pos.transactions.edit.any`
  Used by transaction load/cancel policy and transaction UI, but missing from the centralized permission registry before this implementation pass.

### Grouped Exception Permissions

- `pos.safeDrops.create`
- `pos.cart.clear`
- `pos.cart.line.remove`
- `pos.cart.line.reduce`
- `pos.overrides.price`
- `pos.overrides.discount`
- `pos.void`

These remain individually assignable but are not required for the default `manager`, `cashier`, or `floor staff` bundles.

### Deprecated / Unsupported On Supported Assignment Surface

- `pos.sessions.require-terminal`
  Runtime session-open policy no longer relies on a standalone permission gate.
- `pos.monitor.access`
  No supported runtime route currently consumes it.
- `pos.approval.requests.view`
  Approval queue visibility is represented by `pos.supervisor.approval`.
- `pos.settings.edit`
  No supported POS runtime screen currently uses it.

## Default Bundle Matrix

### Owner

- Super Admin bypass only

### Manager

- Core shell: `pos.access`, `pos.sell`, `pos.sessions.open`, `pos.sessions.close`
- Draft handoff: `pos.transactions.view`, `pos.transactions.save`, `pos.transactions.load`
- Checkout: `pos.checkout.payment`, `pos.receipts.reprint`
- Oversight: `pos.sessions.view`, `pos.sessions.close-admin`, `pos.sessions.approve-variance`, `pos.supervisor.approval`, `pos.safeDrops.approve`, `pos.reports.access`, `pos.reconciliation.access`, `pos.transactions.edit.any`
- Administration: `pos.terminals.access`, `pos.terminals.edit`
- Operational support: `pos.safeDrops.create`

### Cashier

- Core shell: `pos.access`, `pos.sell`, `pos.sessions.open`, `pos.sessions.close`
- Draft handoff: `pos.transactions.view`, `pos.transactions.save`, `pos.transactions.load`
- Checkout: `pos.checkout.payment`
- Operational support: `pos.safeDrops.create`

### Floor Staff

- Core shell: `pos.access`, `pos.sell`, `pos.sessions.open`, `pos.sessions.close`
- Draft handoff: `pos.transactions.view`, `pos.transactions.save`, `pos.transactions.load`
- No checkout authority by default

## Screen / Action Clusters

- Core shell access: enter POS shell, open or close own session, build cart, resolve customer, assign serials
- Draft handoff: view POS transactions, save draft, load draft for continuation
- Checkout: payment-method lookup, staged payment, finalize checkout, receipt reprint
- Oversight: session index, session summary for non-owner sessions, admin close, variance approval, approval queue, reconciliation, reports, cross-user draft takeover
- Administration: terminal management
- Exceptions: safe drop creation, destructive cart mutations, direct price or discount override, void

## Migration Matrix

### Supported Live Role Targets

- Existing owner or Super Admin roles -> `owner`
- Existing cashier roles with `pos.checkout.payment` and without oversight permissions -> `cashier`
- Existing helper / sales-floor roles with `pos.sell` plus draft permissions but no checkout permission -> `floor staff`
- Existing supervisor / manager roles with oversight permissions such as `pos.sessions.view`, `pos.sessions.close-admin`, `pos.supervisor.approval`, `pos.reports.access`, `pos.reconciliation.access`, or `pos.transactions.edit.any` -> `manager`

### Custom Exception Roles

- Roles carrying direct mutation overrides (`pos.overrides.price`, `pos.overrides.discount`, `pos.cart.clear`, `pos.cart.line.remove`, `pos.cart.line.reduce`, `pos.void`) without the full manager bundle should be treated as custom-exception roles and reviewed explicitly before rollout.

### Deprecated Permission Remapping

- `pos.sessions.require-terminal` -> remove; terminal selection is now policy-driven
- `pos.monitor.access` -> remove until a supported monitor surface is reintroduced
- `pos.approval.requests.view` -> replace with `pos.supervisor.approval`
- `pos.settings.edit` -> remove from supported POS role composition
