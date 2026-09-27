## Why

Non-terminal POS sessions are now a valid runtime path, but the sessions index still assumes `session.terminal` always exists in action attributes. This causes a runtime crash for privileged users and blocks operational close workflows for active non-terminal sessions.

## What Changes

- Make `/pos/sessions` action rendering null-safe for sessions without a terminal so the page never crashes when admin/supervisor permissions are present.
- Keep admin force-close available for OPEN non-terminal sessions, with terminal-aware modal labeling that degrades to a non-terminal label.
- Explicitly exclude non-terminal sessions from supervisor finalization: hide finalize action in list UI and keep backend finalization guarded for terminal-less sessions.
- Add regression coverage for non-terminal OPEN/CLOSED rows under elevated permissions (`pos.sessions.close-admin`, `pos.supervisor.approval`).

## Capabilities

### New Capabilities
- <!-- none -->

### Modified Capabilities
- `pos-sessions-list`: Session index rendering and action controls must remain stable and null-safe when `terminal_id` is null.
- `pos-admin-force-close`: Force-close workflow must support OPEN sessions without a terminal and present non-terminal-safe modal metadata.
- `pos-supervisor-cash-finalization`: Finalization action visibility must exclude terminal-less sessions (no finalize path for non-terminal sessions).

## Impact

- Affected UI: `Modules/Pos/Resources/views/session/index.blade.php`, session action modal wiring, and `public/js/pos-session-handlers.js` data binding assumptions.
- Affected backend guardrails: finalization path behavior for sessions where `terminal_id` is null.
- Affected tests: POS session index and settlement workflow feature tests for non-terminal scenarios.
- No database schema changes.
