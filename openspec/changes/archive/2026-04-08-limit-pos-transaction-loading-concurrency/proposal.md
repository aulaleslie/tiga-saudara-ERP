## Why

Currently, the POS transaction list allows users to "Load" any transaction that is in `DRAFT` or `LOADED` status. This leads to two main issues:
1. **Redundant UI**: Users can click "Load" on a transaction that is already being processed in another terminal, which is confusing and non-functional for multiple users.
2. **Race Conditions**: If two users click "Load" simultaneously on the same transaction, both might think they succeeded, but only one "owns" the transaction state in their session. The system lacks a guard to reject the second requester.

This change ensures that a transaction can only be "active" (LOADED) in one session at a time and provides a clear UI/API guard to enforce this.

## What Changes

- **UI**: The "Load" button in the transaction list will be hidden for transactions already in `LOADED` status.
- **Service Layer**: The `loadToCart` method will be updated to only allow loading transactions in `DRAFT` status and will implement a database-level lock to prevent concurrent loading.
- **Error Handling**: A specific conflict error (409) will be returned if a user attempts to load a transaction that was just loaded by someone else.

## Capabilities

### New Capabilities
- `pos-transaction-load-concurrency`: Enforces single-session loading of POS transactions with atomic guards and UI visibility logic.

### Modified Capabilities
- `pos-transactions-list-loading`: The list rendering logic will now conditionally hide actions based on the explicit `LOADED` status to prevent redundant loading attempts.

## Impact

- **Affected Files**:
    - `Modules/Pos/Resources/views/transactions/index.blade.php` (UI logic)
    - `Modules/Pos/Services/PosTransactionService.php` (Business logic & Concurrency guard)
- **API**: The `/pos/transactions/{transaction}/load` endpoint will now return `409 Conflict` in case of race conditions.
