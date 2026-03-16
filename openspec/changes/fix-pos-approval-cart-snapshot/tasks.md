## 1. Update PosCartApprovalController

- [x] 1.1 Add `PosCartService` dependency via constructor injection in `PosCartApprovalController`
- [x] 1.2 Modify `store()` method to fetch `cart_snapshot` after successful approval request creation
- [x] 1.3 Include `cart_snapshot` in the JSON response alongside `request_id` and `status`

## 2. Update Tests

- [x] 2.1 Verify existing test `POSCartReduceQtyWithApprovalTest` passes with new response contract
- [x] 2.2 Add assertion to verify `cart_snapshot` is present in approval request creation response
- [x] 2.3 Verify cart snapshot contains correct line data and approval metadata after request submission

## 3. Verify Frontend Integration

- [x] 3.1 Confirm frontend `requestApproval()` handler can receive and process `cart_snapshot` from response
- [x] 3.2 Verify `buildLineRow()` is called and `console.log("I'm called")` fires after approval submission
- [x] 3.3 Verify "Periksa Persetujuan" button renders immediately after approval request creation in browser
