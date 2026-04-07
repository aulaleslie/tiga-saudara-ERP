## Context

The POS transaction loading feature currently allows any `DRAFT` or `LOADED` transaction to be pulled into an active session's cart. With multiple terminals using the same centralized database, this creates a risk of two users simultaneously loading and editing the same draft, leading to data overwrites or inconsistent state.

## Goals / Non-Goals

**Goals:**
- Implement a server-side guard to strictly enforce that only `DRAFT` transactions can be loaded.
- Use database-level locking to prevent race conditions during the loading process.
- Synchronize UI visibility with the server-side state by hiding the "Load" action for already `LOADED` transactions.

**Non-Goals:**
- Implementing a "Force Unload" feature (admins can still clear sessions manually if needed).
- Changing how `LOADED` transactions are returned to `DRAFT` (the existing `unload()` on cart clear/save remains the standard way).

## Decisions

### 1. Database Atomic Guard
- **Choice**: Use `lockForUpdate()` within the `loadToCart` service method.
- **Rationale**: Standard Laravel/Eloquent row-level locking ensures that concurrent requests for the same transaction ID are queued. The status check must happen *after* acquiring the lock to be truly atomic.
- **Alternative**: Optimistic locking using `snapshot_hash`. While we already have a drift check, it doesn't prevent two users from *starting* the load process at the same time. Row-level locking is safer for this state transition.

### 2. UI Conditional Visibility
- **Choice**: Modify the `buildActions` function in the `index.blade.php` to filter based on `row.status`.
- **Rationale**: Since the list data already includes the `status` field for every row, this is a zero-latency UI improvement.
- **Alternative**: Polling the server for individual row statuses. Rejected as too heavy for the current AJAX-datatable implementation.

### 3. Error Code Standardization
- **Choice**: Introduce `TRANSACTION_ALREADY_LOADED` error code in the API response.
- **Rationale**: Allows the frontend to explicitly handle this conflict and show a human-readable message instead of a generic "Failed" alert.

## Risks / Trade-offs

- **Risk**: A transaction might get "stuck" in `LOADED` if a browser crashes and the session is never cleared.
- **Mitigation**: Standard session timeouts and the ability for users to clear their own active transactions (which calls `unload`) mitigate this. Manual database intervention is a rare fallback.
