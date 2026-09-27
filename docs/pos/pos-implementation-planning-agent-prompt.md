# POS Planning Prompt (Final Locked Decisions)

## 1) Locked Clarifications
These decisions are finalized and must be treated as constraints in planning.

1. Approval flow style for restricted cart actions: **Option B** (async request/check flow).
2. Who can approve: **Option A** (user with `pos.supervisor.approval` + action permission).
3. Approval granularity: **Option A** (per-row/per-action).
4. Approval token validity: **Option B** (TTL-based single-use).
5. Permission design for restricted actions: **Option B** (granular permissions).
6. `Simpan dan Buka Baru` persistence model: **Option A** (new dedicated persistent POS transaction tables).
7. Load saved POS transaction into current session: **Option C** (only when current cart empty).
8. Editing scope for saved POS transaction: **Option C** (creator + elevated override users).
9. Empty transaction behavior: **Option A** (hard-block; cannot become empty).
10. POS completion with sales split: **Option A** (multiple sales + multiple sale payments proportionally split).
11. Tax fallback rule: **Option B** (default tax if exists; else latest active tax).
12. Serial input UX: **Option A** (scanner-friendly custom modal).

## 2) Default Assumptions (Explicit)
Use these assumptions unless future requirements explicitly override them:

1. Approval async execution path: supervisor approves from a dedicated approval request menu/list.
2. Approval token TTL: **10 minutes**, single-use.
3. Checkout split grouping key: **`source_setting_id + source_location_id + tax_bucket`**.
4. Loading non-completed transaction: **allowed only when current cart is empty** (no merge).

---

## 3) Copy-Paste Prompt For AI Planning Agent

```md
You are a senior Laravel ERP architect. Do **implementation planning only** (no code changes yet).

Project context:
- Stack: Laravel modular monolith (`Modules/*`), Blade + vanilla JS/jQuery in POS shell.
- POS sell page: `http://localhost:8000/pos/sell`.
- Current cart is session-based (`PosCartSessionStore`) and not DB-persistent.

Verified current baseline from code (must respect this in your plan):
1. Routes and POS sell APIs are in `Modules/Pos/Routes/web.php` and `Modules/Pos/Http/Controllers/PosSellController.php`.
2. Cart operations (`add/update/remove/clear/customer/serial`) are implemented in `Modules/Pos/Services/PosCartService.php`.
3. Quantity decrease is currently blocked in backend (`updateLine`) and frontend (`sell.blade.php`).
4. `Simpan dan Buka Baru` button exists in `Modules/Pos/Resources/views/sell.blade.php` but has no behavior yet.
5. Supervisor approval infra already exists (`PosSupervisorApprovalService`, `pos_supervisor_approvals`), currently used for price override/safe drop/session-close variance.
6. Checkout posting currently creates **one unified Sale** via `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`.
7. Stock allocation service exists: `ResolvePosStockAllocationsService` with non-tax-first behavior for taxable lines.
8. Legacy draft/transaction schema was previously dropped (`database/migrations/2026_08_12_000003_drop_pos_transactional_schema.php`), so draft persistence needs a new design.
9. Current permissions include POS global permissions in `Modules/User/Database/Seeders/PermissionsTableSeeder.php`, but no granular permissions yet for clear/remove/reduce cart actions.
10. Serial add in POS UI still uses browser `prompt()` and needs replacement with scanner-friendly modal UX.

Business goals to plan:
1. **Restricted cart actions with permission + approval flow**
- Clear cart: if user has permission, action is direct.
- If no permission: button label/flow should support request-and-check approval (`Permohonan ...` -> `Cek Persetujuan` -> `Lanjutkan/Batalkan`).
- Same concept for:
  - remove row item (add new `Aksi` column),
  - reduce quantity per row (currently forbidden globally; must become controlled by permission/approval).

2. **Save and reopen transaction (`Simpan dan Buka Baru`)**
- Save current open POS transaction as draft/non-completed transaction.
- Add menu/screen to list POS transactions.
- Authorized users can open a non-completed transaction and perform admin edits (reduce qty, remove row, etc.), but transaction must never be left empty.
- Ability to load non-completed transaction to current POS session.

3. **Completion behavior + document splitting**
- POS completion remains one cashier flow, but generated sales documents must be split by product ownership/source business/location policy.
- Serial products: ownership source is explicit from serial/location.
- Non-serial products: allocation should prioritize non-PPN stock deduction first according to sale location config.
- Taxed sales: use default tax when available, otherwise latest active tax.

4. **UI refinement for serial handling**
- Add serial controls should be visually integrated with qty area.
- Serial chips alignment should be improved.
- Replace browser `prompt()` with in-app modal that supports barcode scanner input.

Planning constraints:
- Reuse current POS shell where practical (avoid building separate redundant UI unless justified).
- Favor additive migrations and backward-compatible rollout.
- Provide a phased rollout with low regression risk.
- Include explicit permission matrix and action-state machine.
- Include test strategy (feature/unit/UAT) and regression coverage map.

Locked decisions and assumptions (do not reopen unless explicitly asked):
- Approval flow style: **ASYNC request/check state flow**.
- Approver: users with `pos.supervisor.approval` + action-specific permission.
- Approval granularity: per-row/per-action.
- Approval token: TTL-based single-use.
- Permission model: granular new permissions for clear/remove/reduce.
- Draft model: new persistent POS transaction tables.
- Load transaction: only when current cart is empty (no merge).
- Edit scope: creator + privileged override.
- Empty transaction policy: hard-block (cannot become empty).
- Sales split posting: multiple sales + multiple payments split proportionally.
- Tax fallback: default tax else latest active tax.
- Serial UX: scanner-friendly custom modal.
- Approval queue UX: supervisor approves from dedicated approval request menu/list.
- Approval token TTL default: **10 minutes**.
- Split grouping key default: **`source_setting_id + source_location_id + tax_bucket`**.

Now produce a **phase-based implementation plan** (mandatory):
1. **Phase Map (Overview Table)**
- columns: phase, objective, main deliverables, dependencies, rollout risk, exit criteria.

2. **Detailed Plan Per Phase**
- create Phase 1..N sections.
- each phase must include:
  - scope and non-scope,
  - architecture changes (modules/classes/services),
  - data model + migration changes for that phase,
  - permission/approval changes for that phase,
  - API/UI contract changes for that phase,
  - test plan for that phase,
  - risk/rollback notes,
  - concrete acceptance criteria.

3. **Cross-Phase Consolidated Artifacts**
- permission matrix (all roles/actions/endpoints),
- endpoint contract table (method/path/payload/response/error),
- state machines (approval flow + pos transaction lifecycle),
- final migration ordering and deployment sequence.

4. **Checkout Split Strategy**
- algorithm to split by ownership/source,
- tax and stock deduction policy,
- idempotency/reconciliation implications across phases.

5. **Final Delivery Sequence**
- phased PR sequence (PR-1, PR-2, ...),
- cutover strategy and regression guardrails,
- UAT checklist aligned to phases.

File output requirement:
- Write the complete plan to this Markdown file: `docs/pos/pos-implementation-plan-output.md`.
- If the file already exists, overwrite it with the new full plan.
- After writing the file, return only a short confirmation that includes:
  - file path,
  - section checklist written,
  - any unresolved assumptions.

Output format:
- concise but concrete.
- use tables for permission matrix and API list.
- include file paths when referencing existing code.
- highlight any assumptions still requiring business confirmation.
- the output structure must be phase-first (do not produce topic-first structure).
```
