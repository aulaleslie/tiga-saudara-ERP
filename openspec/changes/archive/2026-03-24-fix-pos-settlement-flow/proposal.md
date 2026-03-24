## Why

The current POS terminal settlement (finalization) flow is confusing and incomplete. The "Finalize" button visibility is strictly tied to the `CLOSED` status without user guidance, making it unclear to supervisors when a session is ready for settlement. Additionally, when a finalization attempt is blocked by a variance exceeding the terminal's threshold, the system provides only a static error message with no interactive path for a supervisor to authorize the variance on-the-spot, creating a "stuck" state for users without global permission.

## What Changes

- **Finalize Button Visibility**: Update the session index to show the "Finalize" button in a disabled state for `OPEN` sessions with a tooltip explanation, rather than hiding it entirely.
- **Interactive Variance Approval**: Enhance the finalization modal and frontend logic to detect when a finalization is blocked due to variance. Introduce an in-modal supervisor authentication flow (PIN/Identifier) that allows an authorized supervisor to override the variance immediately and complete the settlement.
- **Backend Authorization**: Ensure the `finalizeSession` service can handle immediate overrides via provided supervisor credentials if the primary actor lacks the `pos.sessions.approve-variance` permission.

## Capabilities

### New Capabilities
- `pos-settlement-interactive-approval`: Provides an interactive mechanism for in-person supervisor override during terminal settlement when variance limits are exceeded.

### Modified Capabilities
- `pos-supervisor-cash-finalization`: Update to include specific UI requirements for interactive override handling and button visibility guidance.

## Impact

- `PosSessionController`: Updated frontend button logic and API response handling.
- `pos-session-handlers.js`: Significant logic added to handle 422 `requires_variance_approval` responses and trigger the override flow.
- `PosSessionFinalizeService`: Update to support credential-based variance override in a single request or atomic secondary request.
