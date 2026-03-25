## 1. Core Implementation

- [x] 1.1 Update `PosSessionAdminCloseService` to set `active_marker` using `PosSession::activeMarkerForStatus(PosSession::STATUS_CLOSED)` when transitioning a session to CLOSED.
- [x] 1.2 Update `PosSessionFinalizeService` to set `active_marker` using `PosSession::activeMarkerForStatus(PosSession::STATUS_FINALIZED)` when transitioning a session to FINALIZED.

## 2. Testing

- [x] 2.1 Write or update tests in `POSSessionLifecycleTest` (or equivalent) to verify that an admin force-closed session has `active_marker => null`.
- [x] 2.2 Write or update tests in `POSSupervisorCashFinalizationTest` (or equivalent) to verify that a finalized session has `active_marker => null`.
- [x] 2.3 Verify that the `/pos/sell` route correctly redirects to the Open Session page when the user's latest session is CLOSED or FINALIZED.
