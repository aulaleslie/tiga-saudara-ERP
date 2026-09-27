## Requirement: Conditional Transaction Actions Visibility
The POS transaction list UI SHALL conditionally hide actions based on the transaction's current status to prevent redundant or invalid state transitions.

### AS-IS Behavior:
The "Load" action is visible for both `DRAFT` and `LOADED` statuses.

### TO-BE Behavior:
The "Load" action MUST be hidden for transactions with `LOADED` status.
The "Detail" and "Cancel" actions SHALL remain available as per existing permissions.
