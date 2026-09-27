## Why

Currently, when creating or editing a customer fails at the `/customers/create` endpoint, there are no visible error messages or logs to help trace the cause. This lack of observability makes debugging difficult for administrators and developers. Adding comprehensive logging around the customer save and edit process will provide the necessary context to troubleshoot these silent failures.

## What Changes

- Add extensive try-catch logging around customer creation and updating logic.
- Log error details (exception message, trace, request payload) when a customer save operation fails.
- Introduce debug logging for the customer save payload prior to the database transaction.

## Capabilities

### New Capabilities
- `customer-crud-observability`: Enhances observability for customer creation and update operations by adding detailed info and error logging to trace execution and failures.

### Modified Capabilities

## Impact

- Customer controllers and services handling the store and update actions.
- Application error logging configurations.
