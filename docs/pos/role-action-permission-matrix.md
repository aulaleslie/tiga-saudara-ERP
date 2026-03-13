# POS Role Action Permission Matrix

This document defines the server-enforced POS action policy used by `PosRolePolicyService`
and cart/session authorization flows.

## Roles

- `Floor Staff`
- `Cashier Staff`
- `Store Manager`

## Core Actions

| Action | Permission Key | Floor Staff | Cashier Staff | Store Manager |
| --- | --- | --- | --- | --- |
| Open session without terminal selection | role policy | Allowed | Not allowed | Allowed |
| Open session with terminal selection | `pos.sessions.open` | Allowed | Required | Allowed |
| Clear cart | `pos.cart.clear` | Approval flow if no direct permission | Approval flow if no direct permission | Direct if permission |
| Remove line | `pos.cart.line.remove` | Approval flow if no direct permission | Approval flow if no direct permission | Direct if permission |
| Reduce quantity | `pos.cart.line.reduce` | Approval flow if no direct permission | Approval flow if no direct permission | Direct if permission |
| Override line price | `pos.overrides.price` | Approval flow | Approval flow | Direct if permission |
| Finalize checkout payment | `pos.sell` + role policy | Not allowed | Allowed | Allowed |
| Open supervisor queue | `pos.supervisor.approval` | If granted | If granted | If granted |

## Notes

- Backend authorization is authoritative; UI capability states mirror backend outcomes.
- Restricted actions use request/check/execute flow with deterministic states:
  `pending`, `approved`, `rejected`.
- Approved execution tokens are single-use.
- Cancelling after approval invalidates the issued token and leaves cart state unchanged.
