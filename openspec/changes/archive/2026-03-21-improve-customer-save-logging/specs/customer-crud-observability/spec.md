# Customer CRUD Observability

Enhances observability for customer creation and update operations by adding detailed info and error logging to trace execution and failures.

## Context

When creating or updating customer records, failures can sometimes occur silently without adequate log traces or visible errors to the user. This makes troubleshooting difficult. This spec ensures that all saves and updates are wrapped in comprehensive logging.

## Requirements

1. **Error Logging on Save Failure**: When a database exception or any other error prevents a customer from being created, the system MUST log the error.
2. **Payload Inclusion**: The error log MUST include the incoming request data (payload) to assist in reproducing the issue, ensuring no highly sensitive data breaks privacy rules.
3. **Execution Tracing**: The system SHOULD log debug-level information immediately prior to attempting the database transaction, allowing administrators to trace the execution path.
4. **Resilience**: The logging mechanism itself MUST NOT disrupt the user flow; the original error or a generic error response MUST still be returned to the client appropriately.
