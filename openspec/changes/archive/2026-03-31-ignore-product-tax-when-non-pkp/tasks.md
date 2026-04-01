## 1. Purchase Enhancements

- [x] 1.1 Update `PurchaseController@store` to forcefully strip `tax_id` and zero out `product_tax_amount` when processing the cart array or cart instance if `is_pkp` evaluates to false.
- [x] 1.2 Update `PurchaseController@update` to enforce the same logic (strip `tax_id` and zero `product_tax_amount`) when rebuilding the purchase details if `is_pkp` evaluates to false.

## 2. Sale Enhancements

- [x] 2.1 Investigate `SaleController@store` and `SaleService` to locate where Cartesian details are translated into `SaleDetails`. Update this pipeline to forcefully strip `tax_id` and zero out `tax_amount` when `is_pkp` evaluates to false.
- [x] 2.2 Replicate the PKP-tax-stripping logic within the `SaleController@update` or `SaleService` update path prior to saving the cart item updates.

## 3. Post-Implementation Testing

- [x] 3.1 Verify that creating a Purchase for a non-PKP business using a tax-laden cart item resolves cleanly to exactly 0 tax.
- [x] 3.2 Verify that creating a Sale for a non-PKP business behaves correctly and records no product-level tax.
