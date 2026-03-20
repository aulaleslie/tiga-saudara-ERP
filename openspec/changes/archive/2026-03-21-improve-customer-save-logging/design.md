## Context

Currently, the `CustomersController`'s `store` and `update` methods handle saving customer data. However, when an error occurs during the save process (such as a database exception or a missing field that bypasses validation), the error is sometimes swallowed or not appropriately logged with enough context. This results in silent failures where the user cannot save the customer, and developers have no log trace to identify the root cause.

## Goals / Non-Goals

**Goals:**
- Provide clear, contextual logging when a customer fails to save or update.
- Log the incoming request payload (excluding sensitive data if any, though customer data is typically standard) when a failure occurs.
- Add debug-level logging prior to the save operation to capture the state right before the transaction.

**Non-Goals:**
- Complete refactoring of the customer management logic.
- Implementing a full audit trail database table for customer changes.
- Altering the user interface or returning detailed stack traces to the frontend (frontend should still receive a generic error message).

## Decisions

- **Logging Facade:** We will use Laravel's built-in `Log` facade (`Log::error()` and `Log::debug()`).
- **Log Context:** We will include the `Request::all()` array in the log context to capture what data was being submitted when the error occurred.
- **Exception Catching:** We will wrap the core database operations (e.g., `Customer::create()`, `Customer::update()`, and any related saving of addresses/terminals) in a `try...catch (\Exception $e)` block within the controller or adjust existing try-catch blocks to ensure they generate meaningful logs before redirecting or returning a response.

## Risks / Trade-offs

- **Log Volume:** Adds more entries to `laravel.log`. However, debug logs will only appear if the logging level is set to debug, and error logs will only occur on failures, minimizing the impact on log volume.
- **Sensitive Data:** We must ensure no highly sensitive data (like unencrypted passwords, though not applicable for customers here) is dumped into the logs. Standard customer details (name, email, phone) are acceptable for error tracing in this context.
