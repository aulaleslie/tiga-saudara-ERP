# Implementation Plan: Terminal POS Clarity and Scope Alignment

## 1. Objective
Make `/pos/terminals` unambiguous for operations by separating:
- `configuration policy` (what terminal is allowed/required to do), and
- `runtime occupancy` (whether a cashier is actively running a session now).

At the same time, enforce the intended ERP boundary:
- POS behavior is scoped by active `setting_id`.
- POS sale source locations come from `sales-location-configurations` (`setting_sale_locations`), not from terminal binding.

## 2. Current Problem (What Went Wrong)
1. Mixed semantics in one label
- Terminal index shows `Sesi: Aktif/Nonaktif` under `Kebijakan`, but that value comes from `require_session_open` policy, not live cashier activity.

2. Overloaded word `Aktif`
- `Aktif` is used for terminal status, policy toggles, and session runtime status, making users read `Sesi: Aktif` as "cashier currently working".

3. Runtime state is disconnected from terminal list
- Active session truth already exists in `pos_sessions` and monitor/session pages, but terminal list does not surface it.

4. Scope inconsistency
- Most POS routes are gated by `pos.enabled`, but terminal routes are not.
- This creates confusion whether terminal management is POS runtime or settings configuration.

5. Policy-to-runtime contract drift
- `require_session_open` is displayed as configurable, but current sell flow already enforces active session globally.
- `require_opening_float` is also displayed as configurable while open-session validation currently always requires float > 0.

6. Residual technical debt from old terminal-location model
- Terminal model still carries location fallback behavior while schema intent is terminal-as-station.
- Some tests still use `location_id`, masking model drift (especially on sqlite path).

## 3. Target State (ERP-Correct)
### 3.1 Terminal page semantics
- `Terminal Status` = master data lifecycle (`Aktif/Nonaktif terminal`), where `Aktif` means terminal is allowed to serve transactions.
- `Kebijakan Terminal` = policy flags only, with explicit labels (`Wajib buka sesi`, `Wajib saldo awal`, etc.).
- `Sesi Berjalan` (new) = runtime occupancy from `pos_sessions` (`OPEN/CLOSING`), meaning a cashier is currently using that terminal.

### 3.2 Scope and source of truth
- Terminal records remain scoped by current `setting_id`.
- Stock/tax source locations remain resolved from `setting_sale_locations` priority.
- Terminal page shows a read-only notice: "Sumber lokasi POS diatur di Konfigurasi Lokasi Penjualan" with link.

### 3.3 Drilldown behavior
- From terminal row, user can drill down to relevant active session details (session list filtered by terminal + active statuses).

## 4. Implementation Workstreams

### WS1. Terminology and UI clarity (`/pos/terminals`)
Changes:
- Replace ambiguous text in policy summary:
  - `Sesi: Aktif/Nonaktif` -> `Wajib buka sesi: Ya/Tidak`
  - `Saldo Awal: Aktif/Nonaktif` -> `Wajib saldo awal: Ya/Tidak`
- Add dedicated `Sesi Berjalan` column with badges:
  - `Tidak ada sesi aktif`
  - `Sedang digunakan - {cashier} sejak {time}`
  - `Perlu cek` if more than one active session exists for same terminal.

Files:
- `Modules/Pos/Resources/views/terminals/index.blade.php`
- `Modules/Pos/Resources/views/terminals/_form.blade.php` (helper text alignment)

Acceptance:
- No policy text can be read as runtime cashier occupancy.
- Terminal page can answer both questions directly:
  - "Apakah sesi wajib?"
  - "Apakah terminal ini sedang dipakai sekarang?"

### WS2. Runtime occupancy data contract
Changes:
- Extend terminal index query to include active session runtime snapshot (terminal -> active session + cashier + opened_at).
- Add terminal filter support in session index for drilldown.

Files:
- `Modules/Pos/Http/Controllers/PosTerminalController.php`
- `Modules/Pos/Http/Controllers/PosSessionController.php`
- `Modules/Pos/Entities/PosTerminal.php` (relation for runtime lookup)
- `Modules/Pos/Resources/views/session/index.blade.php` (filter chip/label)

Acceptance:
- Clicking from terminal row opens session list already filtered for that terminal.
- Runtime data shown on terminal page matches session index data.

### WS3. Settings scope hardening
Changes:
- Keep terminal management in current POS area (no menu relocation), but enforce strict setting scoping for read/write and runtime lookup.
- Keep terminal as physical cashier terminal master data (`active/inactive`) separate from session runtime state.
- Align route middleware, sidebar, and header quick-link behavior.

Files:
- `Modules/Pos/Routes/web.php`
- `resources/views/layouts/menu.blade.php`
- `resources/views/layouts/header.blade.php`
- `Modules/Pos/Http/Middleware/PosEnabledMiddleware.php` (if needed for message clarity)

Acceptance:
- User experience clearly communicates terminal master data status vs session runtime status.
- No route/menu contradiction between POS enabled flag and terminal access.
- No cross-setting access path for terminal read/write actions.

### WS4. Location-source clarity on terminal screen
Changes:
- Add terminal page info panel:
  - Current setting/company
  - POS source locations count + priority preview
  - Link to `sales-location-configurations`
- Improve empty-config handling on session open:
  - redirect with actionable message instead of hard abort page.

Files:
- `Modules/Pos/Http/Controllers/PosTerminalController.php`
- `Modules/Pos/Http/Controllers/PosSessionController.php`
- `Modules/Pos/Resources/views/terminals/index.blade.php`

Acceptance:
- Users can immediately see where POS sales locations are configured.
- No dead-end error when sale-location configuration is missing.

### WS5. Policy contract cleanup
Changes:
- Reconcile policy toggles with real runtime behavior:
  - `require_session_open`: remove toggle from UI and enforce global mandatory session-open rule.
  - `require_opening_float`: enforce faithfully per terminal policy (`off` allows zero open, `on` requires > 0).
- Apply session concurrency policy: one active session per terminal (not per cashier+terminal).
- Remove/deprecate terminal location fallback logic if no longer part of domain.

Files:
- `Modules/Pos/Http/Middleware/EnsureActivePosSessionMiddleware.php`
- `Modules/Pos/Services/PosSessionLifecycleService.php`
- `Modules/Pos/Entities/PosTerminal.php`
- `Modules/Pos/Resources/views/terminals/_form.blade.php`

Acceptance:
- Every visible policy on terminal UI has enforceable runtime effect.
- No legacy location-bound behavior remains in terminal domain unless explicitly intended.
- Exactly one active session is allowed per terminal for each setting context.

### WS6. Test and regression coverage
Add/update tests:
- Terminal index wording and runtime occupancy display.
- Terminal -> session drilldown filter behavior.
- Route/menu behavior when `pos_enabled` is on/off.
- Sale-location missing flow uses actionable redirect.
- Policy behavior parity (`require_session_open`, `require_opening_float`).

Likely test files:
- `Modules/Pos/Tests/Feature/POSTerminalRegistryPolicyTest.php`
- `Modules/Pos/Tests/Feature/POSSessionIndexTest.php`
- `Modules/Pos/Tests/Feature/POSRouteFeatureFlagTest.php`
- `Modules/Pos/Tests/Feature/POSPermissionRoleMappingTest.php`
- `Modules/Pos/Tests/Feature/POSNavigationMenuVisibilityTest.php`

## 5. Hardened Phase Plan

### Phase 0. Alignment and Contract Freeze
Goal:
- Freeze shared terminology and operational meaning before implementation.

Scope:
- Confirm final glossary for `Terminal Active` vs `Session Active`.
- Freeze D1-D4 decisions as non-negotiable implementation contract.
- Prepare a short scenario pack for UAT (normal shift, terminal inactive, missing sale location config).

Deliverables:
- Approved terminology matrix and UI copy baseline.
- Approved phase gate checklist.

Exit criteria:
- Product/ops sign-off that terminal status and session status meanings are unambiguous.
- No unresolved policy interpretation on session and opening float.

### Phase 1. Clarity UX and Runtime Visibility
Mapped workstreams:
- WS1 + WS2

Goal:
- Remove ambiguity on `/pos/terminals` immediately with minimum operational risk.

Scope:
- Rename policy labels to explicit requirement wording.
- Add `Sesi Berjalan` runtime column.
- Add drilldown from terminal row to session list (filtered by terminal and active statuses).

Out of scope:
- Route/middleware scoping changes.
- Concurrency and policy enforcement refactors.

Deliverables:
- Updated terminal index UX contract.
- Runtime occupancy data contract for terminal list.

Exit criteria:
- Terminal page answers both questions in one view:
  - is terminal allowed to serve transactions?
  - is cashier currently using this terminal?
- No policy field can be interpreted as runtime occupancy.

### Phase 2. Settings Scope and Location Source Clarity
Mapped workstreams:
- WS3 + WS4

Goal:
- Enforce setting-scope consistency and make location-source ownership explicit.

Scope:
- Keep terminal management in current POS area while enforcing strict `setting_id` scoping.
- Align route/menu/header behavior so there is no POS-enable contradiction.
- Show location source panel linking to `sales-location-configurations`.
- Replace session-open hard abort with actionable redirect/error flow.

Out of scope:
- Deep policy behavior refactor.
- DB-level session concurrency migration.

Deliverables:
- Scope-consistent access behavior across routes and navigation.
- Terminal page context panel for source-location configuration.

Exit criteria:
- No cross-setting read/write access path for terminals.
- Operators can discover source-location configuration directly from terminal page.

### Phase 3. Policy Contract Enforcement
Mapped workstreams:
- WS5 (policy enforcement core)

Goal:
- Make runtime behavior exactly match visible policy contract.

Scope:
- Remove `require_session_open` toggle from UI and enforce global mandatory session open.
- Enforce `require_opening_float` faithfully per terminal policy.
- Enforce one active session per terminal (within setting scope), including app guard and DB constraint strategy.

Out of scope:
- Broad reporting redesign.
- Non-terminal POS feature expansion.

Deliverables:
- Updated policy model contract and validation flow.
- Session concurrency enforcement design and implementation checklist.

Exit criteria:
- No visible policy field without runtime effect.
- One-terminal-one-active-session rule is consistently enforced.

### Phase 4. Debt Cleanup and Regression Hardening
Mapped workstreams:
- WS5 (legacy cleanup) + WS6

Goal:
- Remove legacy confusion points and stabilize with automated coverage.

Scope:
- Remove/deprecate terminal location fallback behavior if confirmed obsolete.
- Update/add feature tests for wording, drilldown, scope, policy parity, and conflict handling.
- Validate behavior in sqlite test path and production path assumptions.

Deliverables:
- Regression test suite updates.
- Technical debt closure notes for terminal model and tests.

Exit criteria:
- Critical POS test suite passes with updated policy/runtime assertions.
- No residual terminal-location ambiguity in code or tests.

### Phase 5. UAT and Controlled Rollout
Goal:
- Validate operational understanding and prevent rollout regressions.

Scope:
- Run store-level UAT on terminal/session semantics.
- Validate access scope across multi-setting users.
- Validate failure UX (missing sale location config, inactive terminal, blocked session open).

Deliverables:
- UAT sign-off checklist and incident fallback steps.
- Go-live recommendation per business setting.

Exit criteria:
- UAT scenarios pass with no semantic confusion from operators.
- Rollout readiness confirmed by ops and product owner.

### Phase Gates (Mandatory)
Gate A (after Phase 1):
- Label ambiguity eliminated in terminal UI and validated by ops.

Gate B (after Phase 2):
- Setting scope and location source responsibilities are clear in UX and access behavior.

Gate C (after Phase 3):
- Session/opening-float contracts match runtime behavior with no policy drift.

Gate D (after Phase 4):
- Regression suite passes and legacy model ambiguity is closed.

Gate E (after Phase 5):
- UAT approved for phased rollout.

## 6. Decision Lock (Confirmed)

### D1. Terminal management model
Selected: keep current placement (under POS) and enforce strict `setting_id` scope.
- Terminal = physical cashier terminal master data (`Aktif/Nonaktif` for usability).
- Session = separate runtime state indicating cashier occupancy.

### D2. Session concurrency rule per terminal
Selected: one active session per terminal.
- Enforce app-level check and DB uniqueness for active session per terminal.

### D3. `require_session_open` policy strategy
Selected: hard-required globally and remove toggle.
- Session-open remains mandatory before POS sell access.

### D4. `require_opening_float` policy strategy
Selected: enforce policy faithfully.
- If policy on: opening float required.
- If policy off: allow zero/open without float.

## 7. Success Criteria
- Terminal page semantics are unambiguous to store operators.
- Runtime occupancy is visible and drilldown-ready from terminal list.
- POS setting scope is consistent across routes/menu/header behavior.
- Source locations for POS are clearly shown as configuration-driven, not terminal-bound.
- Policy fields shown in UI are either enforced or removed.
