## Context

Terminal settlement (finalization) is the second stage of the POS closing process. Currently, it is "all or nothing": if variance is within threshold, it succeeds; if not, it fails with a 422 error and a generic toast message. Supervisors must then manually ensure they have the `pos.sessions.approve-variance` permission or contact someone who does, but they lack a clear path to perform an in-person override.

## Goals / Non-Goals

**Goals:**
- Provide clear UI guidance on when a session is ready for finalization.
- Implement an in-modal supervisor override path for variance-blocked settlements.
- Minimize friction for supervisors by allowing on-the-spot authentication for overrides.

**Non-Goals:**
- Refactoring the core expected cash calculation logic.
- Adding support for asynchronous approval queues (this design focuses on in-person overrides).

## Decisions

### 1. In-Modal Override UI
Instead of navigating away or opening a new modal, the `finalizeModal` will dynamically show a "Supervisor Authorization" section when a `requires_variance_approval` error is caught.
- **Rationale**: Keeps the reconciliation data (expected vs actual) visible while the supervisor authenticates the variance.
- **Alternatives**: Redirecting to a separate approval page (too much friction).

### 2. Dual-Mode `finalizeSession` API Handling
The `submitFinalize` JS function will be updated to handle a standard attempt followed by an "override attempt" that includes `supervisor_identifier` and `supervisor_password`.
- **Rationale**: Reuses the existing endpoint while adding optional authentication fields.
- **Alternatives**: Creating a separate `/finalize/approve` endpoint.

### 3. Session Index "Disabled" Button
The "Finalize" button in `index.blade.php` will be rendered for `OPEN` sessions but with the `disabled` attribute and a Bootstrap tooltip.
- **Rationale**: Improves discoverability. Users won't wonder where the settlement button went.

## Risks / Trade-offs

- **Security** → We must ensure that the override credentials provided in the modal are validated against the `pos.sessions.approve-variance` permission, not just `pos.supervisor.approval`.
- **UI Complexity** → The finalize modal is already data-heavy. The override section must be visually distinct but compact.
