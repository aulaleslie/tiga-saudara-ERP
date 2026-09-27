## 1. Normalization

- [x] 1.1 Extract and reorganize UI permission groupings for `Penjualan`, `Pengiriman Penjualan`, `Pembayaran Penjualan`, `Retur Penjualan`, `Penyelesaian Retur Penjualan`, and `Pembayaran Retur Penjualan` in `app/Config/Permissions.php` to be sequential.
- [x] 1.2 Inject `sales.archive`, `saleReturns.archive`, and `sales.approved.edit` into `app/Config/Permissions.php`.
- [x] 1.3 Remove `saleReturnPayments.show` from `app/Config/Permissions.php`.
- [x] 1.4 Validate mapping correctness by running the `PermissionsTableSeeder`, verifying output completes successfully and database contains new permissions for Super Admin.
