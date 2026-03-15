<?php

/**
 * Central permissions configuration
 *
 * This file is the single source of truth for all application permissions.
 * Permissions are organized by feature group and include human-readable labels
 * for display in the role management UI.
 *
 * To add a new permission:
 * 1. Add it to the appropriate group below (create a new group if needed)
 * 2. Run the PermissionsTableSeeder (php artisan db:seed --class=PermissionsTableSeeder)
 * 3. The permission will automatically be created in the database and added to the Admin role
 *
 * To remove a permission:
 * 1. Remove it from the group below
 * 2. Run the seeder - it will automatically delete the permission from the database
 *
 * Legacy permission mappings have been migrated to this structure:
 * - brand.* → brands.* (legacy singular → modern plural form)
 * - role.* → roles.* (legacy singular → modern plural form)
 * - create_quotations → quotations.create
 * - show_quotations → quotations.access
 * - edit_quotations → quotations.edit
 * - send_quotation_mails → quotations.sendMails
 * - create_sale_returns → saleReturns.create
 * - edit_sale_returns → saleReturns.edit
 * - create_transfers → stockTransfers.create
 * - update_transfers → stockTransfers.edit
 * - create_account/edit_account → chartOfAccounts.create/edit (disambiguated via code search)
 * - sales.search.global → globalSalesSearch.access (kept for backward compatibility)
 */

return [
    'Adjustments' => [
        'adjustments.access' => 'Hak Akses',
        'adjustments.approval' => 'Persetujuan',
        'adjustments.breakage.approval' => 'Persetujuan Kerusakan',
        'adjustments.breakage.create' => 'Buat Kerusakan',
        'adjustments.breakage.edit' => 'Ubah Kerusakan',
        'adjustments.create' => 'Buat',
        'adjustments.delete' => 'Hapus',
        'adjustments.edit' => 'Ubah',
        'adjustments.show' => 'Tampilkan',
    ],

    'Barcode' => [
        'barcodes.print' => 'Cetak Barcode',
    ],

    'Brands' => [
        'brands.access' => 'Hak Akses',
        'brands.create' => 'Buat',
        'brands.edit' => 'Ubah',
        'brands.delete' => 'Hapus',
        'brands.view' => 'Lihat',
    ],

    'Businesses' => [
        'businesses.access' => 'Hak Akses',
        'businesses.create' => 'Buat',
        'businesses.edit' => 'Ubah',
        'businesses.delete' => 'Hapus',
        'businesses.show' => 'Tampilkan',
    ],

    'Categories' => [
        'categories.access' => 'Hak Akses',
        'categories.create' => 'Buat',
        'categories.edit' => 'Ubah',
        'categories.delete' => 'Hapus',
    ],

    'Chart of Accounts' => [
        'chartOfAccounts.access' => 'Hak Akses',
        'chartOfAccounts.create' => 'Buat',
        'chartOfAccounts.edit' => 'Ubah',
        'chartOfAccounts.delete' => 'Hapus',
        'chartOfAccounts.show' => 'Tampilkan',
    ],

    'Currencies' => [
        'currencies.access' => 'Hak Akses',
        'currencies.create' => 'Buat',
        'currencies.edit' => 'Ubah',
        'currencies.delete' => 'Hapus',
    ],

    'Customers' => [
        'customers.access' => 'Hak Akses',
        'customers.create' => 'Buat',
        'customers.edit' => 'Ubah',
        'customers.delete' => 'Hapus',
        'customers.show' => 'Tampilkan',
    ],

    'Expense Categories' => [
        'expenseCategories.access' => 'Hak Akses',
        'expenseCategories.create' => 'Buat',
        'expenseCategories.edit' => 'Ubah',
        'expenseCategories.delete' => 'Hapus',
    ],

    'Expenses' => [
        'expenses.access' => 'Hak Akses',
        'expenses.create' => 'Buat',
        'expenses.edit' => 'Ubah',
        'expenses.delete' => 'Hapus',
    ],

    'Journals' => [
        'journals.access' => 'Hak Akses',
        'journals.create' => 'Buat',
        'journals.edit' => 'Ubah',
        'journals.delete' => 'Hapus',
        'journals.show' => 'Tampilkan',
    ],

    'Locations' => [
        'locations.access' => 'Hak Akses',
        'locations.create' => 'Buat',
        'locations.edit' => 'Ubah',
    ],

    'Sale Locations' => [
        'saleLocations.access' => 'Hak Akses',
        'saleLocations.edit' => 'Ubah',
    ],

    'Payment Methods & Terms' => [
        'paymentMethods.access' => 'Hak Akses Metode Pembayaran',
        'paymentMethods.create' => 'Buat Metode Pembayaran',
        'paymentMethods.edit' => 'Ubah Metode Pembayaran',
        'paymentMethods.delete' => 'Hapus Metode Pembayaran',
        'paymentTerms.access' => 'Hak Akses Syarat Pembayaran',
        'paymentTerms.create' => 'Buat Syarat Pembayaran',
        'paymentTerms.edit' => 'Ubah Syarat Pembayaran',
        'paymentTerms.delete' => 'Hapus Syarat Pembayaran',
    ],

    'Price Points' => [
        'pricePoints.access' => 'Hak Akses',
    ],

    'Products' => [
        'products.access' => 'Hak Akses',
        'products.create' => 'Buat',
        'products.edit' => 'Ubah',
        'products.delete' => 'Hapus',
        'products.show' => 'Tampilkan',
        'products.bundle.access' => 'Hak Akses Bundle',
        'products.bundle.create' => 'Buat Bundle',
        'products.bundle.edit' => 'Ubah Bundle',
        'products.bundle.delete' => 'Hapus Bundle',
    ],

    'Profiles' => [
        'profiles.edit' => 'Ubah Profil',
    ],

    'Quotations' => [
        'quotations.access' => 'Hak Akses',
        'quotations.create' => 'Buat',
        'quotations.edit' => 'Ubah',
        'quotations.sendMails' => 'Kirim Email',
    ],

    'Purchases' => [
        'purchases.access' => 'Hak Akses',
        'purchases.create' => 'Buat',
        'purchases.import' => 'Impor',
        'purchases.edit' => 'Ubah',
        'purchases.delete' => 'Hapus',
        'purchases.show' => 'Tampilkan',
        'purchases.receive' => 'Terima',
        'purchases.approval' => 'Persetujuan',
        'purchases.view' => 'Lihat',
    ],

    'Purchase Receivings' => [
        'purchaseReceivings.access' => 'Hak Akses',
        'purchaseReceivings.approval' => 'Persetujuan',
    ],

    'Purchase Reports' => [
        'purchaseReports.access' => 'Hak Akses',
        'purchaseReports.global.access' => 'Akses Global',
    ],

    'Sale Reports' => [
        'saleReports.access' => 'Hak Akses',
        'saleReports.global.access' => 'Akses Global',
    ],

    'Stock Mutation Reports' => [
        'stockMutationReports.access' => 'Hak Akses',
        'stockMutationReports.global.access' => 'Akses Global',
    ],

    'Inventory Valuation Reports' => [
        'inventoryValuationReports.access' => 'Hak Akses',
    ],

    'Purchase Payments' => [
        'purchasePayments.access' => 'Hak Akses',
        'purchasePayments.create' => 'Buat',
        'purchasePayments.edit' => 'Ubah',
        'purchasePayments.delete' => 'Hapus',
    ],

    'Purchase Returns' => [
        'purchaseReturns.access' => 'Hak Akses',
        'purchaseReturns.create' => 'Buat',
        'purchaseReturns.edit' => 'Ubah',
        'purchaseReturns.delete' => 'Hapus',
        'purchaseReturns.show' => 'Tampilkan',
        'purchaseReturns.viewPrice' => 'Lihat Harga',
        'purchaseReturns.approval' => 'Persetujuan',
        'purchaseReturns.dispatchRequest' => 'Permintaan Pengiriman',
        'purchaseReturns.dispatchApproval' => 'Persetujuan Pengiriman',
        'purchaseReturns.dispatchExecute' => 'Eksekusi Pengiriman',
    ],

    'Purchase Return Payments' => [
        'purchaseReturnPayments.access' => 'Hak Akses',
        'purchaseReturnPayments.create' => 'Buat',
        'purchaseReturnPayments.edit' => 'Ubah',
        'purchaseReturnPayments.delete' => 'Hapus',
        'purchaseReturnPayments.show' => 'Tampilkan',
    ],

    'Purchase Return Settlements' => [
        'purchaseReturnSettlements.access' => 'Hak Akses',
        'purchaseReturnSettlements.submit' => 'Kirim',
        'purchaseReturnSettlements.approve' => 'Setujui',
        'purchaseReturnSettlements.execute' => 'Eksekusi',
        'purchaseReturnSettlements.dispatch' => 'Pengiriman',
        'purchaseReturnSettlements.receive' => 'Terima',
    ],

    'Sale Return Settlements' => [
        'saleReturnSettlements.access' => 'Hak Akses',
        'saleReturnSettlements.submit' => 'Kirim',
        'saleReturnSettlements.approve' => 'Setujui',
        'saleReturnSettlements.dispatch' => 'Pengiriman',
        'saleReturnSettlements.dispatchRequest' => 'Permintaan Pengiriman',
        'saleReturnSettlements.dispatchApproval' => 'Persetujuan Pengiriman',
        'saleReturnSettlements.execute' => 'Eksekusi',
        'saleReturnSettlements.receive' => 'Terima',
    ],

    'Reports & Settings' => [
        'reports.access' => 'Akses Laporan',
        'settings.access' => 'Akses Pengaturan',
        'settings.edit' => 'Ubah Pengaturan',
    ],

    'POS' => [
        'pos.access' => 'Hak Akses',
        'pos.sell' => 'Penjualan',
        'pos.sessions.open' => 'Buka Sesi',
        'pos.sessions.close' => 'Tutup Sesi',
        'pos.sessions.close-admin' => 'Tutup Terminal (Admin)',
        'pos.sessions.approve-variance' => 'Setujui Varians Kas',
        'pos.sessions.view' => 'Lihat Sesi',
        'pos.safeDrops.create' => 'Buat Setoran',
        'pos.safeDrops.approve' => 'Setujui Setoran',
        'pos.overrides.price' => 'Ubah Harga',
        'pos.overrides.discount' => 'Ubah Diskon',
        'pos.void' => 'Batal Transaksi',
        'pos.supervisor.approval' => 'Persetujuan Supervisor',
        'pos.monitor.access' => 'Akses Monitor',
        'pos.reports.access' => 'Akses Laporan',
        'pos.reconciliation.access' => 'Akses Rekonsiliasi',
        'pos.receipts.reprint' => 'Cetak Ulang Kwitansi',
        'pos.terminals.access' => 'Akses Terminal',
        'pos.terminals.edit' => 'Ubah Terminal',
        'pos.settings.edit' => 'Ubah Pengaturan',
        'pos.approval.requests.view' => 'Lihat Permintaan Persetujuan',
        'pos.transactions.view' => 'Lihat Transaksi',
        'pos.transactions.save' => 'Simpan Transaksi',
        'pos.transactions.load' => 'Muat Transaksi',
    ],

    'Stock Transfers' => [
        'stockTransfers.access' => 'Hak Akses',
        'stockTransfers.create' => 'Buat',
        'stockTransfers.edit' => 'Ubah',
        'stockTransfers.delete' => 'Hapus',
        'stockTransfers.show' => 'Tampilkan',
        'stockTransfers.dispatch' => 'Pengiriman',
        'stockTransfers.receive' => 'Terima',
        'stockTransfers.approval' => 'Persetujuan',
    ],

    'Suppliers' => [
        'suppliers.access' => 'Hak Akses',
        'suppliers.create' => 'Buat',
        'suppliers.edit' => 'Ubah',
        'suppliers.delete' => 'Hapus',
        'suppliers.show' => 'Tampilkan',
    ],

    'Taxes' => [
        'taxes.access' => 'Hak Akses',
        'taxes.create' => 'Buat',
        'taxes.edit' => 'Ubah',
        'taxes.delete' => 'Hapus',
    ],

    'Units' => [
        'units.access' => 'Hak Akses',
        'units.create' => 'Buat',
        'units.edit' => 'Ubah',
        'units.delete' => 'Hapus',
    ],

    'Users & Roles' => [
        'users.access' => 'Hak Akses User',
        'users.create' => 'Buat User',
        'users.edit' => 'Ubah User',
        'users.delete' => 'Hapus User',
        'roles.access' => 'Hak Akses Role',
        'roles.create' => 'Buat Role',
        'roles.edit' => 'Ubah Role',
        'roles.delete' => 'Hapus Role',
    ],

    'Sales' => [
        'sales.access' => 'Hak Akses',
        'sales.create' => 'Buat',
        'sales.import' => 'Impor',
        'sales.edit' => 'Ubah',
        'sales.delete' => 'Hapus',
        'sales.dispatch' => 'Pengiriman',
        'sales.show' => 'Tampilkan',
        'sales.approval' => 'Persetujuan',
    ],

    'Sale Payments' => [
        'salePayments.access' => 'Hak Akses',
        'salePayments.create' => 'Buat',
        'salePayments.edit' => 'Ubah',
        'salePayments.delete' => 'Hapus',
        'salePayments.show' => 'Tampilkan',
    ],

    'Sale Returns' => [
        'saleReturns.access' => 'Hak Akses',
        'saleReturns.create' => 'Buat',
        'saleReturns.edit' => 'Ubah',
        'saleReturns.delete' => 'Hapus',
        'saleReturns.show' => 'Tampilkan',
        'saleReturns.approve' => 'Setujui',
        'saleReturns.receive' => 'Terima',
    ],

    'Sale Return Payments' => [
        'saleReturnPayments.access' => 'Hak Akses',
        'saleReturnPayments.create' => 'Buat',
        'saleReturnPayments.edit' => 'Ubah',
        'saleReturnPayments.delete' => 'Hapus',
    ],

    'Global Search' => [
        'globalSalesSearch.access' => 'Cari Penjualan Global',
        'globalPurchaseAndSalesSearch.access' => 'Cari Pembelian & Penjualan Global',
    ],

    'Notifications' => [
        'notifications.access' => 'Tampilkan Notifikasi',
    ],
];
