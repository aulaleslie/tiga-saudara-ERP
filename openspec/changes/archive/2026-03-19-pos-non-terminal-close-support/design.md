## Context

POS session opening now allows `terminal_id = null` for selected permission bundles. The sessions index already renders a non-terminal label in table cells, but action attributes for admin-close/finalize still dereference `session.terminal->code`, which crashes Blade rendering for privileged viewers. This creates an operational gap: admins cannot reach the page to close active non-terminal sessions.

Current backend behavior is asymmetric:
- Admin close service can close any OPEN session regardless of terminal presence.
- Finalize service requires terminal policy and rejects sessions without a terminal.

The design must align UI behavior with this backend reality while preventing regressions for terminal-backed sessions.

## Goals / Non-Goals

**Goals:**
- Ensure `/pos/sessions` renders safely when any row has `terminal_id = null`.
- Support admin force-close for OPEN non-terminal sessions.
- Make finalization unavailable for non-terminal sessions at UI level, consistent with backend constraints.
- Add regression tests for non-terminal rows with elevated permissions.

**Non-Goals:**
- Adding non-terminal supervisor finalization support.
- Changing database schema, session status model, or permission model.
- Altering variance policy computation logic.

## Decisions

### Decision 1: Null-safe action metadata in sessions index

**Choice:** Replace direct terminal dereference in action button data attributes with null-safe terminal labels.

**Rationale:**
- Prevents view exceptions before any interaction.
- Matches existing row display pattern that already supports non-terminal labels.
- Keeps modal script contracts stable (`data-session-code` remains present).

**Alternatives considered:**
- Hide whole action column for non-terminal rows: rejected because admin close must remain available.
- Enforce terminal join in query: rejected because non-terminal sessions are valid business data.

### Decision 2: Admin-close remains enabled for OPEN sessions regardless of terminal

**Choice:** Keep the existing OPEN-status + permission gate for admin-close, with non-terminal fallback labeling in modal content.

**Rationale:**
- Service-level flow already supports this path.
- Operationally required to release blocked non-terminal sessions.
- Minimal change with clear user intent.

**Alternatives considered:**
- Disable admin-close for non-terminal sessions: rejected because it strands active sessions.
- Auto-assign virtual terminal before close: rejected as unnecessary and misleading.

### Decision 3: Finalize action explicitly terminal-scoped

**Choice:** Show finalize action only when session is CLOSED and has terminal context; retain backend guard for terminal-less finalization attempts.

**Rationale:**
- Finalize service depends on terminal policy threshold.
- Avoids presenting an action that is guaranteed to fail.
- Preserves existing finalization flow for terminal sessions.

**Alternatives considered:**
- Add default/global variance threshold for non-terminal finalize: rejected (out of scope).
- Keep button visible and rely on API error: rejected due to poor UX and avoidable failures.

### Decision 4: Regression coverage at view and workflow boundaries

**Choice:** Add feature tests that assert sessions index renders for privileged users with non-terminal sessions and that close/finalize controls are correctly exposed/hidden.

**Rationale:**
- Bug origin is a view-level null dereference, best caught by page-render tests.
- Permission-sensitive action visibility is a recurring regression risk.

**Alternatives considered:**
- Unit tests only for helpers: rejected because Blade + permission integration is where failure occurs.

## Risks / Trade-offs

- **Risk:** Different teams may interpret “no finalize for non-terminal” as temporary, creating future divergence.  
  **Mitigation:** Encode explicit scope in specs and tasks with a named non-goal.

- **Risk:** Existing CLOSED non-terminal sessions might appear “stuck” without finalize controls.  
  **Mitigation:** Keep backend error explicit and document that this change intentionally excludes non-terminal finalize.

- **Risk:** Frontend fallback labels may drift from backend terminology.  
  **Mitigation:** Reuse existing “Non-Terminal” label language already used in table cells.

## Migration Plan

1. Update sessions index action rendering and finalize visibility guards.
2. Ensure modal data population remains resilient with null terminal metadata.
3. Add/adjust feature tests for non-terminal OPEN/CLOSED rows with admin/supervisor permissions.
4. Deploy without DB migration.

Rollback:
1. Revert view/script/test changes from this change set.
2. No data rollback required.

## Open Questions

- For CLOSED non-terminal sessions created before this change, should product later introduce an alternate settlement path (outside supervisor finalize)?
- Should admin-close modal title remain “Tutup Terminal” when row is non-terminal, or be renamed to neutral wording in a follow-up UX pass?
