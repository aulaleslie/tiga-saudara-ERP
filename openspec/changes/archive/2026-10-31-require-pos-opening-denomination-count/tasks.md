# Tasks

## 1. Permission and compatibility contract

- [x] 1.1 Register `pos.sessions.open.total-only` in `app/Config/Permissions.php` and the existing POS exception assignment surfaces in `PosPermissionMatrix`; verify registry/runtime parity tests and that cashier/floor-staff bundles do not grant it automatically.
- [x] 1.2 Retire the terminal total-only checkbox for opening cash while preserving its stored column; update `docs/pos/role-action-permission-matrix.md` and `docs/pos/pos-permission-backfill-checklist.md` with permission sync, explicit assignment, request-mode changes, and rollback guidance; verify terminal configuration no longer suggests the flag authorizes bypass.

## 2. Authoritative opening cash and persistence

- [x] 2.1 Add shared opening-cash normalization using globally enabled `CashDenomination` values, integer quantities, positive derived totals and storage bounds; verify focused tests for valid sums, blank/zero counts, invalid/fractional/negative counts, unknown/disabled keys, stale configuration and overflow.
- [x] 2.2 Update `StorePosSessionOpenRequest`, `PosSessionController::store` and `PosSessionLifecycleService::openSession` to carry explicit mode, default to denominations, authorize nominal mode against the acting user at submission, and ignore client totals in counted mode; verify HTTP and direct-service tests reject unauthorized nominal calls, missing counts, unknown modes and revoked permissions while accepting authorized positive nominal entry.
- [x] 2.3 Persist normalized denomination snapshots and mode metadata in OPEN_FLOAT with existing actor fields, using the same total for opening and expected cash; verify atomic failure, nominal null counts despite stale count payloads, historical evidence after configuration edits, and no duplicate events on idempotent opening.
- [x] 2.4 Update existing opening service callers and POS fixtures for the explicit cash mode contract, including `POSOpeningFloatCaptureTest` and `POSSessionRoleTerminalAllocationTest`; verify terminal-free zero cash, floor-staff restrictions, prerequisites, active-session/terminal uniqueness and drawer behavior still pass.

## 3. Opening form

- [x] 3.1 Load globally enabled denominations in `PosSessionController::create` and render counts, unit labels, subtotals and calculated total in `session/open.blade.php`; verify form rendering across two settings uses the same list, disabled denominations are absent, database-added denominations appear, and configuration remains untouched.
- [x] 3.2 Add the permission-controlled nominal switch, formatted nominal amount, validation-state restoration and terminal-dependent visibility; verify fresh forms default to counts for all users, only permitted users see the switch, switching to no terminal requires no cash, and all-disabled configuration gives actionable feedback.
- [x] 3.3 Add opening-form/feature coverage and document the quantity-entry workflow near existing POS operational guidance; verify manual browser behavior for count edits, mode changes, validation retry, and terminal-free opening, with server validation authoritative when client calculations are bypassed.

## 4. Integration verification

- [x] 4.1 Run focused opening-float, role-terminal allocation, setting denomination configuration, permission governance and relevant session lifecycle tests with `php artisan test --filter=...`; verify all pass and review unrelated failures separately.
- [x] 4.2 Run `composer test:fresh-sqlite` and record results; smoke-test counted opening, authorized nominal opening, forged bypass rejection, all-disabled configuration and terminal-free opening, verifying persisted session totals and opening event evidence.
