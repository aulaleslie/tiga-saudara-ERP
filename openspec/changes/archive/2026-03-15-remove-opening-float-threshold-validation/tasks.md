## 1. Code Changes

- [x] 1.1 Remove threshold validation from PosSessionLifecycleService.openSession() (lines 114-120)

## 2. Testing

- [x] 2.1 Verify existing session open tests still pass
- [x] 2.2 Add test case for opening session with float below previous threshold (should succeed)
- [x] 2.3 Run PosSessionSummaryService tests to confirm threshold calculations still work
- [x] 2.4 Run PosSessionMonitorService tests to confirm threshold monitoring still works
- [x] 2.5 Run PosSafeDropService tests to confirm threshold calculations still work

## 3. Validation

- [x] 3.1 Test manual session open flow via UI at http://localhost:8000/pos/sessions/open with float below policy threshold
- [x] 3.2 Verify session is created successfully
- [x] 3.3 Verify threshold appears in monitoring dashboard summary
