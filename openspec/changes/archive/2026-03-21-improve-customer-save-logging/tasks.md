## 1. Controller Updates

- [x] 1.1 Add exception logging to `CustomersController@store` method including request payload.
- [x] 1.2 Add exception logging to `CustomersController@update` method including request payload.
- [x] 1.3 Add debug logging before the database save operation in `CustomersController@store`.
- [x] 1.4 Add debug logging before the database save operation in `CustomersController@update`.

## 2. Verification

- [x] 2.1 Test customer creation failure scenarios locally to ensure errors are logged.
- [x] 2.2 Test customer update failure scenarios locally to ensure errors are logged.
- [x] 2.3 Verify that the application log contains the expected context (request data) on failure without causing application crashes.
