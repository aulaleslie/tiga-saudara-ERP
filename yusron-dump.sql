-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2025 at 01:44 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

--
-- Database: `tiga_saudara`
--

-- --------------------------------------------------------

--
-- Table structure for table `adjusted_products`
--

CREATE TABLE `adjusted_products` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `adjustment_id` bigint(20) UNSIGNED NOT NULL,
                                     `product_id` bigint(20) UNSIGNED NOT NULL,
                                     `quantity` int(11) NOT NULL,
                                     `quantity_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `quantity_non_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `serial_numbers` text DEFAULT NULL,
                                     `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
                                     `type` varchar(255) NOT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adjustments`
--

CREATE TABLE `adjustments` (
                               `id` bigint(20) UNSIGNED NOT NULL,
                               `date` date NOT NULL,
                               `reference` varchar(255) NOT NULL,
                               `note` text DEFAULT NULL,
                               `created_at` timestamp NULL DEFAULT NULL,
                               `updated_at` timestamp NULL DEFAULT NULL,
                               `type` varchar(255) NOT NULL DEFAULT 'normal',
                               `status` varchar(255) NOT NULL DEFAULT 'pending',
                               `location_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audits`
--

CREATE TABLE `audits` (
                          `id` bigint(20) UNSIGNED NOT NULL,
                          `user_type` varchar(255) DEFAULT NULL,
                          `user_id` bigint(20) UNSIGNED DEFAULT NULL,
                          `event` varchar(255) NOT NULL,
                          `auditable_type` varchar(255) NOT NULL,
                          `auditable_id` bigint(20) UNSIGNED NOT NULL,
                          `old_values` text DEFAULT NULL,
                          `new_values` text DEFAULT NULL,
                          `url` text DEFAULT NULL,
                          `ip_address` varchar(45) DEFAULT NULL,
                          `user_agent` varchar(1023) DEFAULT NULL,
                          `tags` varchar(255) DEFAULT NULL,
                          `created_at` timestamp NULL DEFAULT NULL,
                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
                          `id` int(10) UNSIGNED NOT NULL,
                          `setting_id` bigint(20) UNSIGNED NOT NULL,
                          `name` varchar(255) NOT NULL,
                          `description` text DEFAULT NULL,
                          `created_by` bigint(20) UNSIGNED NOT NULL,
                          `deleted_at` timestamp NULL DEFAULT NULL,
                          `created_at` timestamp NULL DEFAULT NULL,
                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`id`, `setting_id`, `name`, `description`, `created_by`, `deleted_at`, `created_at`, `updated_at`) VALUES
    (1, 1, 'MERK', NULL, 1, NULL, '2025-10-26 11:18:40', '2025-10-26 11:18:40');

-- --------------------------------------------------------

--
-- Table structure for table `cashier_cash_movements`
--

CREATE TABLE `cashier_cash_movements` (
                                          `id` bigint(20) UNSIGNED NOT NULL,
                                          `user_id` bigint(20) UNSIGNED NOT NULL,
                                          `movement_type` varchar(50) NOT NULL,
                                          `cash_total` decimal(15,2) NOT NULL DEFAULT 0.00,
                                          `expected_total` decimal(15,2) DEFAULT NULL,
                                          `variance` decimal(15,2) DEFAULT NULL,
                                          `denominations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`denominations`)),
                                          `documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`documents`)),
                                          `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
                                          `notes` text DEFAULT NULL,
                                          `recorded_at` timestamp NULL DEFAULT NULL,
                                          `created_at` timestamp NULL DEFAULT NULL,
                                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
                              `id` bigint(20) UNSIGNED NOT NULL,
                              `category_code` varchar(255) NOT NULL,
                              `category_name` varchar(255) NOT NULL,
                              `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
                              `created_by` bigint(20) UNSIGNED NOT NULL,
                              `setting_id` bigint(20) UNSIGNED NOT NULL,
                              `created_at` timestamp NULL DEFAULT NULL,
                              `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `category_code`, `category_name`, `parent_id`, `created_by`, `setting_id`, `created_at`, `updated_at`) VALUES
    (1, 'CA_01', 'STATIONERY', NULL, 1, 1, '2025-10-26 11:06:17', '2025-10-26 11:06:17');

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `setting_id` bigint(20) UNSIGNED NOT NULL,
                                     `name` varchar(255) NOT NULL,
                                     `account_number` varchar(255) NOT NULL,
                                     `category` enum('Akun Piutang','Aktiva Lancar Lainnya','Kas & Bank','Persediaan','Aktiva Tetap','Aktiva Lainnya','Depresiasi & Amortisasi','Akun Hutang','Kartu Kredit','Kewajiban Lancar Lainnya','Kewajiban Jangka Panjang','Ekuitas','Pendapatan','Pendapatan Lainnya','Harga Pokok Penjualan','Beban','Beban Lainnya') NOT NULL,
                                     `parent_account_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `description` text DEFAULT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `setting_id`, `name`, `account_number`, `category`, `parent_account_id`, `tax_id`, `description`, `created_at`, `updated_at`) VALUES
    (1, 1, 'CASH', '1110', 'Kas & Bank', NULL, NULL, NULL, '2025-10-26 11:13:18', '2025-10-26 11:13:18');

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
                              `id` bigint(20) UNSIGNED NOT NULL,
                              `currency_name` varchar(255) NOT NULL,
                              `code` varchar(255) NOT NULL,
                              `symbol` varchar(255) NOT NULL,
                              `thousand_separator` varchar(255) NOT NULL,
                              `decimal_separator` varchar(255) NOT NULL,
                              `exchange_rate` int(11) DEFAULT NULL,
                              `created_at` timestamp NULL DEFAULT NULL,
                              `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `currencies`
--

INSERT INTO `currencies` (`id`, `currency_name`, `code`, `symbol`, `thousand_separator`, `decimal_separator`, `exchange_rate`, `created_at`, `updated_at`) VALUES
    (1, 'RUPIAH', 'IDR', 'RP', ',', '.', NULL, '2025-10-26 11:06:16', '2025-10-26 11:06:16');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
                             `id` bigint(20) UNSIGNED NOT NULL,
                             `customer_name` varchar(255) NOT NULL,
                             `customer_email` varchar(255) NOT NULL,
                             `customer_phone` varchar(255) NOT NULL,
                             `city` varchar(255) NOT NULL,
                             `country` varchar(255) NOT NULL,
                             `address` text NOT NULL,
                             `created_at` timestamp NULL DEFAULT NULL,
                             `updated_at` timestamp NULL DEFAULT NULL,
                             `company_name` varchar(255) DEFAULT NULL,
                             `contact_name` varchar(255) DEFAULT NULL,
                             `npwp` varchar(255) DEFAULT NULL,
                             `billing_address` text DEFAULT NULL,
                             `shipping_address` text DEFAULT NULL,
                             `fax` varchar(255) DEFAULT NULL,
                             `identity` varchar(255) DEFAULT NULL,
                             `identity_number` varchar(255) DEFAULT NULL,
                             `bank_name` varchar(255) DEFAULT NULL,
                             `bank_branch` varchar(255) DEFAULT NULL,
                             `account_number` varchar(255) DEFAULT NULL,
                             `account_holder` varchar(255) DEFAULT NULL,
                             `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                             `additional_info` varchar(255) DEFAULT NULL,
                             `tier` varchar(255) DEFAULT NULL,
                             `payment_term_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `customer_name`, `customer_email`, `customer_phone`, `city`, `country`, `address`, `created_at`, `updated_at`, `company_name`, `contact_name`, `npwp`, `billing_address`, `shipping_address`, `fax`, `identity`, `identity_number`, `bank_name`, `bank_branch`, `account_number`, `account_holder`, `setting_id`, `additional_info`, `tier`, `payment_term_id`) VALUES
                                                                                                                                                                                                                                                                                                                                                                                                   (1, 'USAHA', 'a@mail.com', '081234596789', '', '', '', '2025-10-26 11:21:01', '2025-10-26 11:21:01', NULL, 'PELANGGAN NORMAL', 'NPWP', 'ALAMAT PENAGIHAN', 'ALAMAT PENGIRIMAN', NULL, 'KTP', 'NO ID', NULL, NULL, NULL, NULL, 1, '', NULL, 858232),
                                                                                                                                                                                                                                                                                                                                                                                                   (2, 'USAHA', 'a@mail.com', '081234596789', '', '', '', '2025-10-26 11:21:29', '2025-10-26 11:21:29', NULL, 'PELANGGAN GROSIR', 'NPWP', 'ALAMAT PENAGIHAN', 'ALAMAT PENGIRIMAN', NULL, 'KTP', 'NO ID', NULL, NULL, NULL, NULL, 1, '', 'WHOLESALER', NULL),
                                                                                                                                                                                                                                                                                                                                                                                                   (3, 'USAHA', 'a@mail.com', '081234596789', '', '', '', '2025-10-26 11:22:05', '2025-10-26 11:22:05', NULL, 'PELANGGAN RESELLER', 'NPWP', 'ALAMAT PENAGIHAN', 'ALAMAT PENGIRIMAN', NULL, 'KTP', 'NO ID', NULL, NULL, NULL, NULL, 1, '', 'RESELLER', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_credits`
--

CREATE TABLE `customer_credits` (
                                    `id` bigint(20) UNSIGNED NOT NULL,
                                    `customer_id` bigint(20) UNSIGNED NOT NULL,
                                    `sale_return_id` bigint(20) UNSIGNED NOT NULL,
                                    `amount` decimal(15,2) NOT NULL,
                                    `remaining_amount` decimal(15,2) NOT NULL,
                                    `status` varchar(255) NOT NULL DEFAULT 'open',
                                    `created_at` timestamp NULL DEFAULT NULL,
                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispatches`
--

CREATE TABLE `dispatches` (
                              `id` bigint(20) UNSIGNED NOT NULL,
                              `sale_id` bigint(20) UNSIGNED NOT NULL,
                              `dispatch_date` datetime DEFAULT NULL,
                              `created_at` timestamp NULL DEFAULT NULL,
                              `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispatch_details`
--

CREATE TABLE `dispatch_details` (
                                    `id` bigint(20) UNSIGNED NOT NULL,
                                    `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `dispatch_id` bigint(20) UNSIGNED NOT NULL,
                                    `sale_id` bigint(20) UNSIGNED NOT NULL,
                                    `product_id` bigint(20) UNSIGNED NOT NULL,
                                    `location_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `dispatched_quantity` int(11) NOT NULL,
                                    `serial_numbers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`serial_numbers`)),
                                    `created_at` timestamp NULL DEFAULT NULL,
                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
                            `id` bigint(20) UNSIGNED NOT NULL,
                            `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                            `category_id` bigint(20) UNSIGNED NOT NULL,
                            `date` date NOT NULL,
                            `reference` varchar(255) NOT NULL,
                            `details` text DEFAULT NULL,
                            `amount` int(11) NOT NULL,
                            `created_at` timestamp NULL DEFAULT NULL,
                            `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
                                      `id` bigint(20) UNSIGNED NOT NULL,
                                      `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                                      `category_name` varchar(255) NOT NULL,
                                      `category_description` text DEFAULT NULL,
                                      `created_at` timestamp NULL DEFAULT NULL,
                                      `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_details`
--

CREATE TABLE `expense_details` (
                                   `id` bigint(20) UNSIGNED NOT NULL,
                                   `expense_id` bigint(20) UNSIGNED NOT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                   `amount` decimal(15,2) NOT NULL,
                                   `created_at` timestamp NULL DEFAULT NULL,
                                   `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
                               `id` bigint(20) UNSIGNED NOT NULL,
                               `uuid` varchar(255) NOT NULL,
                               `connection` text NOT NULL,
                               `queue` text NOT NULL,
                               `payload` longtext NOT NULL,
                               `exception` longtext NOT NULL,
                               `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
                        `id` bigint(20) UNSIGNED NOT NULL,
                        `queue` varchar(255) NOT NULL,
                        `payload` longtext NOT NULL,
                        `attempts` tinyint(3) UNSIGNED NOT NULL,
                        `reserved_at` int(10) UNSIGNED DEFAULT NULL,
                        `available_at` int(10) UNSIGNED NOT NULL,
                        `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journals`
--

CREATE TABLE `journals` (
                            `id` bigint(20) UNSIGNED NOT NULL,
                            `transaction_date` date NOT NULL,
                            `description` text DEFAULT NULL,
                            `created_at` timestamp NULL DEFAULT NULL,
                            `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_items`
--

CREATE TABLE `journal_items` (
                                 `id` bigint(20) UNSIGNED NOT NULL,
                                 `journal_id` bigint(20) UNSIGNED NOT NULL,
                                 `chart_of_account_id` bigint(20) UNSIGNED NOT NULL,
                                 `amount` decimal(15,2) NOT NULL,
                                 `type` enum('debit','credit') NOT NULL,
                                 `created_at` timestamp NULL DEFAULT NULL,
                                 `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
                             `id` bigint(20) UNSIGNED NOT NULL,
                             `setting_id` bigint(20) UNSIGNED NOT NULL,
                             `name` varchar(255) NOT NULL,
                             `created_at` timestamp NULL DEFAULT NULL,
                             `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`id`, `setting_id`, `name`, `created_at`, `updated_at`) VALUES
                                                                                     (1, 1, 'SBY', '2025-10-26 11:11:47', '2025-10-26 11:11:47'),
                                                                                     (2, 1, 'JKT', '2025-10-26 11:11:57', '2025-10-26 11:11:57');

-- --------------------------------------------------------

--
-- Table structure for table `media`
--

CREATE TABLE `media` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `model_type` varchar(255) NOT NULL,
                         `model_id` bigint(20) UNSIGNED NOT NULL,
                         `uuid` char(36) DEFAULT NULL,
                         `collection_name` varchar(255) NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `file_name` varchar(255) NOT NULL,
                         `mime_type` varchar(255) DEFAULT NULL,
                         `disk` varchar(255) NOT NULL,
                         `conversions_disk` varchar(255) DEFAULT NULL,
                         `size` bigint(20) UNSIGNED NOT NULL,
                         `manipulations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`manipulations`)),
                         `custom_properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`custom_properties`)),
                         `generated_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`generated_conversions`)),
                         `responsive_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`responsive_images`)),
                         `order_column` int(10) UNSIGNED DEFAULT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
                              `id` int(10) UNSIGNED NOT NULL,
                              `migration` varchar(255) NOT NULL,
                              `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
                                                          (1, '0000_00_00_000000_create_websockets_statistics_entries_table', 1),
                                                          (2, '2014_10_12_000000_create_users_table', 1),
                                                          (3, '2014_10_12_100000_create_password_resets_table', 1),
                                                          (4, '2019_08_19_000000_create_failed_jobs_table', 1),
                                                          (5, '2019_12_14_000001_create_personal_access_tokens_table', 1),
                                                          (6, '2021_07_14_145038_create_categories_table', 1),
                                                          (7, '2021_07_14_145047_create_products_table', 1),
                                                          (8, '2021_07_15_211319_create_media_table', 1),
                                                          (9, '2021_07_16_010005_create_uploads_table', 1),
                                                          (10, '2021_07_16_220524_create_permission_tables', 1),
                                                          (11, '2021_07_22_003941_create_adjustments_table', 1),
                                                          (12, '2021_07_22_004043_create_adjusted_products_table', 1),
                                                          (13, '2021_07_28_192608_create_expense_categories_table', 1),
                                                          (14, '2021_07_28_192616_create_expenses_table', 1),
                                                          (15, '2021_07_29_165419_create_customers_table', 1),
                                                          (16, '2021_07_29_165440_create_suppliers_table', 1),
                                                          (17, '2021_07_31_015923_create_currencies_table', 1),
                                                          (18, '2021_07_31_140531_create_settings_table', 1),
                                                          (19, '2021_07_31_201003_create_sales_table', 1),
                                                          (20, '2021_07_31_212446_create_sale_details_table', 1),
                                                          (21, '2021_08_07_192203_create_sale_payments_table', 1),
                                                          (22, '2021_08_08_021108_create_purchases_table', 1),
                                                          (23, '2021_08_08_021131_create_purchase_payments_table', 1),
                                                          (24, '2021_08_08_021713_create_purchase_details_table', 1),
                                                          (25, '2021_08_08_175345_create_sale_returns_table', 1),
                                                          (26, '2021_08_08_175358_create_sale_return_details_table', 1),
                                                          (27, '2021_08_08_175406_create_sale_return_payments_table', 1),
                                                          (28, '2021_08_08_222603_create_purchase_returns_table', 1),
                                                          (29, '2021_08_08_222612_create_purchase_return_details_table', 1),
                                                          (30, '2021_08_08_222646_create_purchase_return_payments_table', 1),
                                                          (31, '2021_08_16_015031_create_quotations_table', 1),
                                                          (32, '2021_08_16_155013_create_quotation_details_table', 1),
                                                          (33, '2023_07_01_184221_create_units_table', 1),
                                                          (34, '2024_08_04_005934_create_user_setting_table', 1),
                                                          (35, '2024_08_05_021746_add_role_id_to_user_setting_table', 1),
                                                          (36, '2024_08_11_212922_add_brand_table', 1),
                                                          (37, '2024_08_12_034946_add_columns_to_categories_table', 1),
                                                          (38, '2024_08_12_213842_add_product_unit_conversions', 1),
                                                          (39, '2024_08_12_221237_add_unit_conversion_columns_to_products_table', 1),
                                                          (40, '2024_08_12_224144_add_setting_id_to_unit_table', 1),
                                                          (41, '2024_08_14_213433_add_locations_table', 1),
                                                          (42, '2024_08_15_220615_update_products_table_add_brand_stock_managed_and_nullable_category', 1),
                                                          (43, '2024_08_17_130443_add_barcode_and_profit_percentage', 1),
                                                          (44, '2024_08_17_130538_add_barcode', 1),
                                                          (45, '2024_08_17_143435_modify_product_foreign_relations', 1),
                                                          (46, '2024_08_20_104656_add_transactions_table', 1),
                                                          (47, '2024_08_23_233053_add_init_to_transaction_type_enum', 1),
                                                          (48, '2024_08_27_163344_add_type_and_status_to_adjustments_table', 1),
                                                          (49, '2024_08_27_184310_add_location_id_to_adjustments_table', 1),
                                                          (50, '2024_08_28_075105_add_broken_quantity_to_products_table', 1),
                                                          (51, '2024_09_03_172838_add_sale_and_purchase_price_and_tax_to_products_table', 1),
                                                          (52, '2024_09_05_222624_add_suppliers_info', 1),
                                                          (53, '2024_09_08_151743_add_stock_transfer_table', 1),
                                                          (54, '2024_09_08_151758_add_stock_transfer_product_table', 1),
                                                          (55, '2024_09_09_215715_add_setting_info_product_table', 1),
                                                          (56, '2024_09_09_224310_add_info_to_customer_table', 1),
                                                          (57, '2024_09_11_223749_add_additional_info_to_customer_table', 1),
                                                          (58, '2024_09_13_230003_add_taxes_table', 1),
                                                          (59, '2024_09_26_115105_add_serial_number_required', 1),
                                                          (60, '2024_09_26_115759_add_serial_number_table', 1),
                                                          (61, '2024_09_27_132637_add_product_stock_table', 1),
                                                          (62, '2024_10_03_225620_add_product_stock_info', 1),
                                                          (63, '2024_10_04_041346_add_product_tax_info', 1),
                                                          (64, '2024_10_29_120721_move_product_price_info', 1),
                                                          (65, '2024_10_29_174735_update_monetary_fields_to_decimal', 1),
                                                          (66, '2024_10_29_190105_add_setting_id_to_purchases_table', 1),
                                                          (67, '2024_10_29_190200_add_due_date_and_tax_id_to_purchases_table', 1),
                                                          (68, '2024_11_10_172538_create_audits_table', 1),
                                                          (69, '2024_11_27_075302_create_chart_of_accounts_table', 1),
                                                          (70, '2024_11_27_154759_add_payment_terms_table', 1),
                                                          (71, '2024_11_28_182827_add_payment_term_to_purchase_table', 1),
                                                          (72, '2024_11_28_184238_drop_supplier_name_from_purchases_table', 1),
                                                          (73, '2024_11_28_190002_add_tax_id_to_purchase_details_table', 1),
                                                          (74, '2024_11_28_190035_add_is_tax_included_to_purchases_table', 1),
                                                          (75, '2024_12_24_130535_create_received_notes_table', 1),
                                                          (76, '2024_12_24_131049_create_received_note_details_table', 1),
                                                          (77, '2025_01_07_185557_update_sales_table', 1),
                                                          (78, '2025_01_07_185711_update_sales_detail_table', 1),
                                                          (79, '2025_01_15_213903_add_payment_term_on_supplier', 1),
                                                          (80, '2025_01_15_213914_add_payment_term_on_customer', 1),
                                                          (81, '2025_01_22_190525_create_payment_methods_table', 1),
                                                          (82, '2025_01_22_201548_add_setting_id_on_coa', 1),
                                                          (83, '2025_01_24_195335_add_attachment_to_purchase_payment_table', 1),
                                                          (84, '2025_02_04_190016_add_receive_detail_id_to_product_serial_numbers', 1),
                                                          (85, '2025_02_16_181943_add_is_broken_to_product_serial_numbers', 1),
                                                          (86, '2025_02_16_185317_add_serial_numbers_to_adjusted_products', 1),
                                                          (87, '2025_02_17_155756_add_is_taxable_to_adjusted_products', 1),
                                                          (88, '2025_02_18_155755_add_po_id_to_purchase_return_details', 1),
                                                          (89, '2025_02_24_135737_add_tier_prices_to_product_table', 1),
                                                          (90, '2025_02_24_155406_add_tier_to_customers_table', 1),
                                                          (91, '2025_02_28_192255_add_product_bundles_table', 1),
                                                          (92, '2025_03_02_130536_add_product_bundle_items_table', 1),
                                                          (93, '2025_03_16_165122_create_journal_tables', 1),
                                                          (94, '2025_03_16_165139_create_journal_item_tables', 1),
                                                          (95, '2025_03_23_075342_update_sales_table_add_due_date_and_is_tax_included', 1),
                                                          (96, '2025_03_23_075357_create_sale_bundle_items_table', 1),
                                                          (97, '2025_03_25_133915_create_dispatch_table', 1),
                                                          (98, '2025_03_25_133919_create_dispatch_detail_table', 1),
                                                          (99, '2025_03_30_154014_add_dispatch_detail_id_to_product_serial_number_table', 1),
                                                          (100, '2025_04_03_151257_add_location_id_and_serial_numbers_dispatch_detail_table', 1),
                                                          (101, '2025_04_03_152358_remove_location_id_from_dispatch_table', 1),
                                                          (102, '2025_04_04_152113_add_payment_method_relation_to_sale_payment_table', 1),
                                                          (103, '2025_04_04_155829_update_amount_to_decimal_sale_payments_table', 1),
                                                          (104, '2025_04_04_160848_update_amount_to_decimal_from_sales_table', 1),
                                                          (105, '2025_04_07_183514_add_tax_id_to_dispatch_detail', 1),
                                                          (106, '2025_04_10_000000_downscale_sale_amounts', 1),
                                                          (107, '2025_04_11_163906_modify_transaction_table', 1),
                                                          (108, '2025_04_24_184159_add_price_to_product_conversion_table', 1),
                                                          (109, '2025_05_04_144041_add_price_to_product_bundles_table', 1),
                                                          (110, '2025_06_02_021018_add_setting_id_to_expense_tables', 1),
                                                          (111, '2025_06_14_175954_add_tax_columns_to_adjusted_products_table', 1),
                                                          (112, '2025_07_01_153916_create_expense_details_table', 1),
                                                          (113, '2025_07_02_173959_create_tag_tables', 1),
                                                          (114, '2025_07_21_151017_add_serial_number_ids_to_purchase_return_details_table', 1),
                                                          (115, '2025_07_28_181615_add_sale_prefix_column', 1),
                                                          (116, '2025_08_03_181507_add_serial_numbers_and_quantity_details', 1),
                                                          (117, '2025_08_06_174311_add_supplier_reference_no_to_purchase_table', 1),
                                                          (118, '2025_08_06_174543_add_sale_and_purchase_document_prefix_to_settings_table', 1),
                                                          (119, '2025_08_09_140912_add_is_pos_to_location_table', 1),
                                                          (120, '2025_08_19_183038_add_approval_and_return_type_to_purchase_returns', 1),
                                                          (121, '2025_08_19_183155_add_payment_method_id_to_purchase_return_payments', 1),
                                                          (122, '2025_08_19_183414_add_supplier_credits_for_purchase_returns', 1),
                                                          (123, '2025_08_19_183632_create_purchase_return_goods_table', 1),
                                                          (124, '2025_08_19_183857_standardize_money_columns_in_purchase_returns', 1),
                                                          (125, '2025_08_31_174825_create_product_price', 1),
                                                          (126, '2025_09_02_174017_create_import_batches', 1),
                                                          (127, '2025_09_02_174043_create_import_rows', 1),
                                                          (128, '2025_09_05_120000_add_return_flow_to_transfers_table', 1),
                                                          (129, '2025_09_05_120000_backfill_product_price_flags', 1),
                                                          (130, '2025_09_07_000001_add_location_and_setting_to_purchase_returns_table', 1),
                                                          (131, '2025_09_07_000002_add_settlement_tracking_to_purchase_returns_table', 1),
                                                          (132, '2025_09_10_120000_create_product_unit_conversion_prices_table', 1),
                                                          (133, '2025_09_23_070646_create_jobs_table', 1),
                                                          (134, '2025_09_23_100001_backfill_pending_purchase_returns', 1),
                                                          (135, '2025_09_23_120500_add_document_number_to_transfers_table', 1),
                                                          (136, '2025_09_30_120000_adjust_transfer_document_number_unique', 1),
                                                          (137, '2025_10_05_000001_create_cashier_cash_movements_table', 1),
                                                          (138, '2025_10_05_000001_remove_setting_id_from_shared_master_tables', 1),
                                                          (139, '2025_10_05_000001_update_price_columns_on_sale_bundle_items_table', 1),
                                                          (140, '2025_10_05_120000_enhance_sale_returns_with_settlement_structures', 1),
                                                          (141, '2025_10_10_000000_add_pos_flags_to_payment_methods_table', 1),
                                                          (142, '2025_11_05_000001_add_receiving_columns_to_sale_returns_table', 1),
                                                          (143, '2025_11_15_120000_create_setting_sale_locations_table', 1),
                                                          (144, '2025_12_01_000001_move_is_pos_to_setting_sale_locations', 1);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
                                         `permission_id` bigint(20) UNSIGNED NOT NULL,
                                         `model_type` varchar(255) NOT NULL,
                                         `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
                                   `role_id` bigint(20) UNSIGNED NOT NULL,
                                   `model_type` varchar(255) NOT NULL,
                                   `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
    (2, 'App\\Models\\User', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
                                   `email` varchar(255) NOT NULL,
                                   `token` varchar(255) NOT NULL,
                                   `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
                                   `id` bigint(20) UNSIGNED NOT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `coa_id` bigint(20) UNSIGNED NOT NULL,
                                   `is_cash` tinyint(1) NOT NULL DEFAULT 0,
                                   `is_available_in_pos` tinyint(1) NOT NULL DEFAULT 0,
                                   `created_at` timestamp NULL DEFAULT NULL,
                                   `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `name`, `coa_id`, `is_cash`, `is_available_in_pos`, `created_at`, `updated_at`) VALUES
    (1, 'CASH', 1, 1, 1, '2025-10-26 11:13:46', '2025-10-26 11:13:46');

-- --------------------------------------------------------

--
-- Table structure for table `payment_terms`
--

CREATE TABLE `payment_terms` (
                                 `id` bigint(20) UNSIGNED NOT NULL,
                                 `name` varchar(255) NOT NULL,
                                 `longevity` int(11) NOT NULL DEFAULT 0,
                                 `created_at` timestamp NULL DEFAULT NULL,
                                 `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_terms`
--

INSERT INTO `payment_terms` (`id`, `name`, `longevity`, `created_at`, `updated_at`) VALUES
                                                                                        (858231, 'Net 30', 30, NULL, NULL),
                                                                                        (858232, 'Cash on Delivery', 0, NULL, NULL),
                                                                                        (858233, 'Net 15', 15, NULL, NULL),
                                                                                        (858234, 'Net 60', 60, NULL, NULL),
                                                                                        (858235, 'Custom', 0, NULL, NULL),
                                                                                        (873940, 'Term 14 hari', 14, NULL, NULL),
                                                                                        (898556, 'net 21', 21, NULL, NULL),
                                                                                        (1188378, 'NET 7 HARI', 7, NULL, NULL),
                                                                                        (1726493, '45 HR', 45, NULL, NULL),
                                                                                        (2353345, '10 HARI', 10, NULL, NULL),
                                                                                        (2627421, '24 HR', 24, NULL, NULL),
                                                                                        (2917154, '28HR', 28, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
                               `id` bigint(20) UNSIGNED NOT NULL,
                               `name` varchar(255) NOT NULL,
                               `guard_name` varchar(255) NOT NULL,
                               `created_at` timestamp NULL DEFAULT NULL,
                               `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
                                                                                       (1, 'adjustments.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (2, 'adjustments.approval', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (3, 'adjustments.breakage.approval', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (4, 'adjustments.breakage.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (5, 'adjustments.breakage.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (6, 'adjustments.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (7, 'adjustments.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (8, 'adjustments.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (9, 'adjustments.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (10, 'adjustments.reject', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (11, 'barcodes.print', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (12, 'brands.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (13, 'brands.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (14, 'brands.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (15, 'brands.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (16, 'brands.view', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (17, 'businesses.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (18, 'businesses.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (19, 'businesses.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (20, 'businesses.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (21, 'businesses.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (22, 'categories.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (23, 'categories.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (24, 'categories.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (25, 'categories.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (26, 'chartOfAccounts.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (27, 'chartOfAccounts.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (28, 'chartOfAccounts.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (29, 'chartOfAccounts.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (30, 'chartOfAccounts.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (31, 'currencies.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (32, 'currencies.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (33, 'currencies.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (34, 'currencies.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (35, 'customers.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (36, 'customers.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (37, 'customers.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (38, 'customers.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (39, 'customers.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (40, 'expenseCategories.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (41, 'expenseCategories.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (42, 'expenseCategories.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (43, 'expenseCategories.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (44, 'expenses.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (45, 'expenses.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (46, 'expenses.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (47, 'expenses.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (48, 'journals.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (49, 'journals.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (50, 'journals.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (51, 'journals.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (52, 'journals.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (53, 'locations.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (54, 'locations.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (55, 'locations.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (56, 'saleLocations.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (57, 'saleLocations.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (58, 'paymentMethods.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (59, 'paymentMethods.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (60, 'paymentMethods.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (61, 'paymentMethods.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (62, 'paymentTerms.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (63, 'paymentTerms.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (64, 'paymentTerms.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (65, 'paymentTerms.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (66, 'pos.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (67, 'pos.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (68, 'products.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (69, 'products.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (70, 'products.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (71, 'products.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (72, 'products.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (73, 'products.bundle.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (74, 'products.bundle.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (75, 'products.bundle.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (76, 'products.bundle.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (77, 'profiles.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (78, 'purchases.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (79, 'purchases.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (80, 'purchases.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (81, 'purchases.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (82, 'purchases.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (83, 'purchases.receive', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (84, 'purchases.approval', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (85, 'purchases.view', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (86, 'purchaseReports.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (87, 'purchasePayments.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (88, 'purchasePayments.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (89, 'purchasePayments.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (90, 'purchasePayments.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (91, 'purchaseReturns.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (92, 'purchaseReturns.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (93, 'purchaseReturns.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (94, 'purchaseReturns.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (95, 'purchaseReturns.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (96, 'purchaseReturnPayments.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (97, 'purchaseReturnPayments.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (98, 'purchaseReturnPayments.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (99, 'purchaseReturnPayments.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (100, 'purchaseReturnPayments.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (101, 'reports.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (102, 'settings.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (103, 'settings.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (104, 'stockTransfers.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (105, 'stockTransfers.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (106, 'stockTransfers.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (107, 'stockTransfers.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (108, 'stockTransfers.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (109, 'stockTransfers.dispatch', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (110, 'stockTransfers.receive', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (111, 'stockTransfers.approval', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (112, 'suppliers.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (113, 'suppliers.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (114, 'suppliers.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (115, 'suppliers.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (116, 'suppliers.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (117, 'taxes.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (118, 'taxes.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (119, 'taxes.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (120, 'taxes.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (121, 'units.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (122, 'units.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (123, 'units.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (124, 'units.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (125, 'users.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (126, 'users.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (127, 'users.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (128, 'users.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (129, 'roles.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (130, 'roles.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (131, 'roles.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (132, 'roles.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (133, 'salePayments.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (134, 'salePayments.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (135, 'salePayments.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (136, 'salePayments.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (137, 'saleReturnPayments.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (138, 'saleReturnPayments.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (139, 'saleReturnPayments.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (140, 'saleReturnPayments.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (141, 'salePayments.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (142, 'saleReturns.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (143, 'saleReturns.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (144, 'saleReturns.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (145, 'saleReturns.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (146, 'saleReturns.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (147, 'saleReturns.approve', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (148, 'saleReturns.receive', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (149, 'sales.access', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (150, 'sales.create', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (151, 'sales.edit', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (152, 'sales.delete', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (153, 'sales.dispatch', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (154, 'sales.show', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (155, 'sales.approval', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                       (156, 'show_notifications', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
                                          `id` bigint(20) UNSIGNED NOT NULL,
                                          `tokenable_type` varchar(255) NOT NULL,
                                          `tokenable_id` bigint(20) UNSIGNED NOT NULL,
                                          `name` varchar(255) NOT NULL,
                                          `token` varchar(64) NOT NULL,
                                          `abilities` text DEFAULT NULL,
                                          `last_used_at` timestamp NULL DEFAULT NULL,
                                          `expires_at` timestamp NULL DEFAULT NULL,
                                          `created_at` timestamp NULL DEFAULT NULL,
                                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
                            `id` bigint(20) UNSIGNED NOT NULL,
                            `setting_id` bigint(20) UNSIGNED NOT NULL,
                            `category_id` bigint(20) UNSIGNED DEFAULT NULL,
                            `brand_id` int(10) UNSIGNED DEFAULT NULL,
                            `product_name` varchar(255) NOT NULL,
                            `product_code` varchar(255) DEFAULT NULL,
                            `product_barcode_symbology` varchar(255) DEFAULT NULL,
                            `product_quantity` int(11) NOT NULL,
                            `serial_number_required` tinyint(1) NOT NULL DEFAULT 0,
                            `broken_quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
                            `product_cost` int(11) NOT NULL,
                            `product_price` int(11) NOT NULL,
                            `barcode` varchar(255) DEFAULT NULL,
                            `profit_percentage` decimal(5,2) DEFAULT NULL,
                            `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
                            `base_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
                            `product_unit` varchar(255) DEFAULT NULL,
                            `product_stock_alert` int(11) NOT NULL,
                            `is_purchased` tinyint(1) NOT NULL DEFAULT 0,
                            `purchase_price` int(11) DEFAULT NULL,
                            `purchase_tax` int(11) DEFAULT NULL,
                            `is_sold` tinyint(1) NOT NULL DEFAULT 0,
                            `sale_price` decimal(10,2) DEFAULT 0.00,
                            `tier_1_price` decimal(10,2) DEFAULT 0.00,
                            `tier_2_price` decimal(10,2) DEFAULT 0.00,
                            `sale_tax` int(11) DEFAULT NULL,
                            `last_purchase_price` decimal(10,2) DEFAULT NULL,
                            `average_purchase_price` decimal(10,2) DEFAULT NULL,
                            `product_order_tax` int(11) DEFAULT NULL,
                            `product_tax_type` tinyint(4) DEFAULT NULL,
                            `stock_managed` tinyint(1) NOT NULL DEFAULT 0,
                            `product_note` text DEFAULT NULL,
                            `created_at` timestamp NULL DEFAULT NULL,
                            `updated_at` timestamp NULL DEFAULT NULL,
                            `purchase_tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                            `sale_tax_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `setting_id`, `category_id`, `brand_id`, `product_name`, `product_code`, `product_barcode_symbology`, `product_quantity`, `serial_number_required`, `broken_quantity`, `product_cost`, `product_price`, `barcode`, `profit_percentage`, `unit_id`, `base_unit_id`, `product_unit`, `product_stock_alert`, `is_purchased`, `purchase_price`, `purchase_tax`, `is_sold`, `sale_price`, `tier_1_price`, `tier_2_price`, `sale_tax`, `last_purchase_price`, `average_purchase_price`, `product_order_tax`, `product_tax_type`, `stock_managed`, `product_note`, `created_at`, `updated_at`, `purchase_tax_id`, `sale_tax_id`) VALUES
    (1, 1, 1, 1, 'PULPEN', '001', NULL, 40, 0, 20, 0, 0, NULL, 0.00, NULL, 1, NULL, 0, 1, 0, NULL, 1, 0.00, 4000.00, 4500.00, NULL, 0.00, 0.00, 0, 0, 1, NULL, '2025-10-26 11:19:41', '2025-10-26 11:20:07', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_bundles`
--

CREATE TABLE `product_bundles` (
                                   `id` bigint(20) UNSIGNED NOT NULL,
                                   `parent_product_id` bigint(20) UNSIGNED NOT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `description` text DEFAULT NULL,
                                   `price` decimal(15,2) DEFAULT NULL,
                                   `active_from` date DEFAULT NULL,
                                   `active_to` date DEFAULT NULL,
                                   `created_at` timestamp NULL DEFAULT NULL,
                                   `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_bundle_items`
--

CREATE TABLE `product_bundle_items` (
                                        `id` bigint(20) UNSIGNED NOT NULL,
                                        `bundle_id` bigint(20) UNSIGNED NOT NULL,
                                        `product_id` bigint(20) UNSIGNED NOT NULL,
                                        `price` decimal(10,2) DEFAULT NULL,
                                        `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
                                        `created_at` timestamp NULL DEFAULT NULL,
                                        `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_import_batches`
--

CREATE TABLE `product_import_batches` (
                                          `id` bigint(20) UNSIGNED NOT NULL,
                                          `user_id` bigint(20) UNSIGNED DEFAULT NULL,
                                          `location_id` bigint(20) UNSIGNED NOT NULL,
                                          `source_csv_path` varchar(1024) NOT NULL,
                                          `result_csv_path` varchar(1024) DEFAULT NULL,
                                          `file_sha256` varchar(64) DEFAULT NULL,
                                          `status` enum('queued','validating','processing','completed','failed','canceled') NOT NULL DEFAULT 'queued',
                                          `total_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                          `processed_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                          `success_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                          `error_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                          `completed_at` timestamp NULL DEFAULT NULL,
                                          `undo_available_until` timestamp NULL DEFAULT NULL,
                                          `undone_at` timestamp NULL DEFAULT NULL,
                                          `undo_token` varchar(64) DEFAULT NULL,
                                          `created_at` timestamp NULL DEFAULT NULL,
                                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_import_rows`
--

CREATE TABLE `product_import_rows` (
                                       `id` bigint(20) UNSIGNED NOT NULL,
                                       `batch_id` bigint(20) UNSIGNED NOT NULL,
                                       `row_number` int(10) UNSIGNED NOT NULL,
                                       `raw_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_json`)),
                                       `status` enum('skipped','error','imported') DEFAULT NULL,
                                       `error_message` text DEFAULT NULL,
                                       `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `created_txn_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `created_stock_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `created_at` timestamp NULL DEFAULT NULL,
                                       `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_prices`
--

CREATE TABLE `product_prices` (
                                  `id` bigint(20) UNSIGNED NOT NULL,
                                  `product_id` bigint(20) UNSIGNED NOT NULL,
                                  `setting_id` bigint(20) UNSIGNED NOT NULL,
                                  `sale_price` decimal(10,2) DEFAULT NULL,
                                  `tier_1_price` decimal(10,2) DEFAULT NULL,
                                  `tier_2_price` decimal(10,2) DEFAULT NULL,
                                  `last_purchase_price` decimal(10,2) DEFAULT NULL,
                                  `average_purchase_price` decimal(10,2) DEFAULT NULL,
                                  `purchase_tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                  `sale_tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                  `created_at` timestamp NULL DEFAULT NULL,
                                  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_prices`
--

INSERT INTO `product_prices` (`id`, `product_id`, `setting_id`, `sale_price`, `tier_1_price`, `tier_2_price`, `last_purchase_price`, `average_purchase_price`, `purchase_tax_id`, `sale_tax_id`, `created_at`, `updated_at`) VALUES
                                                                                                                                                                                                                                 (1, 1, 1, 5000.00, 4000.00, 4500.00, 3000.00, 3000.00, NULL, NULL, '2025-10-26 11:19:41', '2025-10-26 11:19:41'),
                                                                                                                                                                                                                                 (2, 1, 2, 5000.00, 4000.00, 4500.00, 3000.00, 3000.00, NULL, NULL, '2025-10-26 11:19:41', '2025-10-26 11:19:41');

-- --------------------------------------------------------

--
-- Table structure for table `product_serial_numbers`
--

CREATE TABLE `product_serial_numbers` (
                                          `id` bigint(20) UNSIGNED NOT NULL,
                                          `product_id` bigint(20) UNSIGNED NOT NULL,
                                          `dispatch_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
                                          `is_broken` tinyint(1) NOT NULL DEFAULT 0,
                                          `received_note_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
                                          `location_id` bigint(20) UNSIGNED NOT NULL,
                                          `serial_number` varchar(255) NOT NULL,
                                          `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                          `created_at` timestamp NULL DEFAULT NULL,
                                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_stocks`
--

CREATE TABLE `product_stocks` (
                                  `id` bigint(20) UNSIGNED NOT NULL,
                                  `product_id` bigint(20) UNSIGNED NOT NULL,
                                  `location_id` bigint(20) UNSIGNED NOT NULL,
                                  `quantity` int(11) NOT NULL,
                                  `quantity_non_tax` int(11) NOT NULL,
                                  `quantity_tax` int(11) NOT NULL,
                                  `broken_quantity_non_tax` int(11) NOT NULL,
                                  `broken_quantity_tax` int(11) NOT NULL,
                                  `broken_quantity` int(11) NOT NULL,
                                  `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                  `created_at` timestamp NULL DEFAULT NULL,
                                  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_stocks`
--

INSERT INTO `product_stocks` (`id`, `product_id`, `location_id`, `quantity`, `quantity_non_tax`, `quantity_tax`, `broken_quantity_non_tax`, `broken_quantity_tax`, `broken_quantity`, `tax_id`, `created_at`, `updated_at`) VALUES
    (1, 1, 1, 40, 10, 10, 10, 10, 20, NULL, '2025-10-26 11:20:07', '2025-10-26 11:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `product_unit_conversions`
--

CREATE TABLE `product_unit_conversions` (
                                            `id` bigint(20) UNSIGNED NOT NULL,
                                            `product_id` bigint(20) UNSIGNED NOT NULL,
                                            `unit_id` bigint(20) UNSIGNED NOT NULL,
                                            `base_unit_id` bigint(20) UNSIGNED NOT NULL,
                                            `conversion_factor` decimal(10,2) NOT NULL,
                                            `barcode` varchar(255) DEFAULT NULL,
                                            `created_at` timestamp NULL DEFAULT NULL,
                                            `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_unit_conversion_prices`
--

CREATE TABLE `product_unit_conversion_prices` (
                                                  `id` bigint(20) UNSIGNED NOT NULL,
                                                  `product_unit_conversion_id` bigint(20) UNSIGNED NOT NULL,
                                                  `setting_id` bigint(20) UNSIGNED NOT NULL,
                                                  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
                                                  `created_at` timestamp NULL DEFAULT NULL,
                                                  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
                             `id` bigint(20) UNSIGNED NOT NULL,
                             `date` date NOT NULL,
                             `due_date` date NOT NULL,
                             `reference` varchar(255) NOT NULL,
                             `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
                             `supplier_reference_no` varchar(255) DEFAULT NULL,
                             `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                             `is_tax_included` tinyint(1) NOT NULL DEFAULT 0,
                             `tax_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
                             `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                             `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
                             `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                             `shipping_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                             `total_amount` decimal(15,2) NOT NULL,
                             `paid_amount` decimal(15,2) NOT NULL,
                             `due_amount` decimal(15,2) NOT NULL,
                             `status` varchar(255) NOT NULL,
                             `payment_status` varchar(255) NOT NULL,
                             `payment_method` varchar(255) NOT NULL,
                             `note` text DEFAULT NULL,
                             `payment_term_id` bigint(20) UNSIGNED DEFAULT NULL,
                             `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                             `created_at` timestamp NULL DEFAULT NULL,
                             `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_details`
--

CREATE TABLE `purchase_details` (
                                    `id` bigint(20) UNSIGNED NOT NULL,
                                    `purchase_id` bigint(20) UNSIGNED NOT NULL,
                                    `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `product_name` varchar(255) NOT NULL,
                                    `product_code` varchar(255) NOT NULL,
                                    `quantity` int(11) NOT NULL,
                                    `price` decimal(15,2) NOT NULL,
                                    `unit_price` decimal(15,2) NOT NULL,
                                    `sub_total` decimal(15,2) NOT NULL,
                                    `product_discount_amount` decimal(15,2) NOT NULL,
                                    `product_discount_type` varchar(255) NOT NULL DEFAULT 'fixed',
                                    `product_tax_amount` decimal(15,2) NOT NULL,
                                    `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `created_at` timestamp NULL DEFAULT NULL,
                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments`
--

CREATE TABLE `purchase_payments` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `payment_method_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `purchase_id` bigint(20) UNSIGNED NOT NULL,
                                     `amount` decimal(15,2) NOT NULL,
                                     `date` date NOT NULL,
                                     `reference` varchar(255) NOT NULL,
                                     `payment_method` varchar(255) NOT NULL,
                                     `note` text DEFAULT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payment_credit_applications`
--

CREATE TABLE `purchase_payment_credit_applications` (
                                                        `id` bigint(20) UNSIGNED NOT NULL,
                                                        `purchase_payment_id` bigint(20) UNSIGNED NOT NULL,
                                                        `supplier_credit_id` bigint(20) UNSIGNED NOT NULL,
                                                        `amount` decimal(15,2) NOT NULL,
                                                        `created_at` timestamp NULL DEFAULT NULL,
                                                        `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
                                    `id` bigint(20) UNSIGNED NOT NULL,
                                    `date` date NOT NULL,
                                    `reference` varchar(255) NOT NULL,
                                    `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `location_id` bigint(20) UNSIGNED DEFAULT NULL,
                                    `supplier_name` varchar(255) NOT NULL,
                                    `tax_percentage` int(11) NOT NULL DEFAULT 0,
                                    `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                    `discount_percentage` int(11) NOT NULL DEFAULT 0,
                                    `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                    `shipping_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                    `total_amount` decimal(15,2) NOT NULL,
                                    `paid_amount` decimal(15,2) NOT NULL,
                                    `due_amount` decimal(15,2) NOT NULL,
                                    `approval_status` varchar(255) NOT NULL DEFAULT 'draft',
                                    `return_type` varchar(255) DEFAULT NULL,
                                    `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
                                    `approved_at` timestamp NULL DEFAULT NULL,
                                    `settled_at` timestamp NULL DEFAULT NULL,
                                    `settled_by` bigint(20) UNSIGNED DEFAULT NULL,
                                    `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
                                    `rejected_at` timestamp NULL DEFAULT NULL,
                                    `rejection_reason` text DEFAULT NULL,
                                    `status` varchar(255) NOT NULL,
                                    `payment_status` varchar(255) NOT NULL,
                                    `payment_method` varchar(255) NOT NULL,
                                    `cash_proof_path` varchar(255) DEFAULT NULL,
                                    `note` text DEFAULT NULL,
                                    `created_at` timestamp NULL DEFAULT NULL,
                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_details`
--

CREATE TABLE `purchase_return_details` (
                                           `id` bigint(20) UNSIGNED NOT NULL,
                                           `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
                                           `po_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Reference to purchase order',
                                           `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                           `product_name` varchar(255) NOT NULL,
                                           `product_code` varchar(255) NOT NULL,
                                           `quantity` int(11) NOT NULL,
                                           `price` decimal(15,2) NOT NULL,
                                           `unit_price` decimal(15,2) NOT NULL,
                                           `sub_total` decimal(15,2) NOT NULL,
                                           `product_discount_amount` decimal(15,2) NOT NULL,
                                           `product_discount_type` varchar(255) NOT NULL DEFAULT 'fixed',
                                           `product_tax_amount` decimal(15,2) NOT NULL,
                                           `serial_number_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`serial_number_ids`)),
                                           `created_at` timestamp NULL DEFAULT NULL,
                                           `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_goods`
--

CREATE TABLE `purchase_return_goods` (
                                         `id` bigint(20) UNSIGNED NOT NULL,
                                         `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
                                         `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                         `product_name` varchar(255) NOT NULL,
                                         `product_code` varchar(255) DEFAULT NULL,
                                         `quantity` int(11) NOT NULL,
                                         `unit_value` decimal(15,2) DEFAULT NULL,
                                         `sub_total` decimal(15,2) DEFAULT NULL,
                                         `received_at` timestamp NULL DEFAULT NULL,
                                         `created_at` timestamp NULL DEFAULT NULL,
                                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_payments`
--

CREATE TABLE `purchase_return_payments` (
                                            `id` bigint(20) UNSIGNED NOT NULL,
                                            `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
                                            `payment_method_id` bigint(20) UNSIGNED DEFAULT NULL,
                                            `amount` decimal(15,2) NOT NULL,
                                            `date` date NOT NULL,
                                            `reference` varchar(255) NOT NULL,
                                            `payment_method` varchar(255) NOT NULL,
                                            `note` text DEFAULT NULL,
                                            `created_at` timestamp NULL DEFAULT NULL,
                                            `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
                              `id` bigint(20) UNSIGNED NOT NULL,
                              `date` date NOT NULL,
                              `reference` varchar(255) NOT NULL,
                              `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
                              `customer_name` varchar(255) NOT NULL,
                              `tax_percentage` int(11) NOT NULL DEFAULT 0,
                              `tax_amount` int(11) NOT NULL DEFAULT 0,
                              `discount_percentage` int(11) NOT NULL DEFAULT 0,
                              `discount_amount` int(11) NOT NULL DEFAULT 0,
                              `shipping_amount` int(11) NOT NULL DEFAULT 0,
                              `total_amount` int(11) NOT NULL,
                              `status` varchar(255) NOT NULL,
                              `note` text DEFAULT NULL,
                              `created_at` timestamp NULL DEFAULT NULL,
                              `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotation_details`
--

CREATE TABLE `quotation_details` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `quotation_id` bigint(20) UNSIGNED NOT NULL,
                                     `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `product_name` varchar(255) NOT NULL,
                                     `product_code` varchar(255) NOT NULL,
                                     `quantity` int(11) NOT NULL,
                                     `price` int(11) NOT NULL,
                                     `unit_price` int(11) NOT NULL,
                                     `sub_total` int(11) NOT NULL,
                                     `product_discount_amount` int(11) NOT NULL,
                                     `product_discount_type` varchar(255) NOT NULL DEFAULT 'fixed',
                                     `product_tax_amount` int(11) NOT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `received_notes`
--

CREATE TABLE `received_notes` (
                                  `id` bigint(20) UNSIGNED NOT NULL,
                                  `po_id` bigint(20) UNSIGNED NOT NULL,
                                  `external_delivery_number` varchar(255) NOT NULL,
                                  `internal_invoice_number` varchar(255) DEFAULT NULL,
                                  `date` date NOT NULL,
                                  `created_at` timestamp NULL DEFAULT NULL,
                                  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `received_note_details`
--

CREATE TABLE `received_note_details` (
                                         `id` bigint(20) UNSIGNED NOT NULL,
                                         `received_note_id` bigint(20) UNSIGNED NOT NULL,
                                         `po_detail_id` bigint(20) UNSIGNED NOT NULL,
                                         `quantity_received` int(11) NOT NULL,
                                         `created_at` timestamp NULL DEFAULT NULL,
                                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `guard_name` varchar(255) NOT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
                                                                                 (1, 'Admin', 'web', '2025-10-26 11:06:15', '2025-10-26 11:06:15'),
                                                                                 (2, 'Super Admin', 'web', '2025-10-26 11:06:16', '2025-10-26 11:06:16');

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
                                        `permission_id` bigint(20) UNSIGNED NOT NULL,
                                        `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_has_permissions`
--

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
                                                                    (1, 1),
                                                                    (2, 1),
                                                                    (3, 1),
                                                                    (4, 1),
                                                                    (5, 1),
                                                                    (6, 1),
                                                                    (7, 1),
                                                                    (8, 1),
                                                                    (9, 1),
                                                                    (10, 1),
                                                                    (11, 1),
                                                                    (12, 1),
                                                                    (13, 1),
                                                                    (14, 1),
                                                                    (15, 1),
                                                                    (16, 1),
                                                                    (17, 1),
                                                                    (18, 1),
                                                                    (19, 1),
                                                                    (20, 1),
                                                                    (21, 1),
                                                                    (22, 1),
                                                                    (23, 1),
                                                                    (24, 1),
                                                                    (25, 1),
                                                                    (26, 1),
                                                                    (27, 1),
                                                                    (28, 1),
                                                                    (29, 1),
                                                                    (30, 1),
                                                                    (31, 1),
                                                                    (32, 1),
                                                                    (33, 1),
                                                                    (34, 1),
                                                                    (35, 1),
                                                                    (36, 1),
                                                                    (37, 1),
                                                                    (38, 1),
                                                                    (39, 1),
                                                                    (40, 1),
                                                                    (41, 1),
                                                                    (42, 1),
                                                                    (43, 1),
                                                                    (44, 1),
                                                                    (45, 1),
                                                                    (46, 1),
                                                                    (47, 1),
                                                                    (48, 1),
                                                                    (49, 1),
                                                                    (50, 1),
                                                                    (51, 1),
                                                                    (52, 1),
                                                                    (53, 1),
                                                                    (54, 1),
                                                                    (55, 1),
                                                                    (56, 1),
                                                                    (57, 1),
                                                                    (58, 1),
                                                                    (59, 1),
                                                                    (60, 1),
                                                                    (61, 1),
                                                                    (62, 1),
                                                                    (63, 1),
                                                                    (64, 1),
                                                                    (65, 1),
                                                                    (66, 1),
                                                                    (67, 1),
                                                                    (68, 1),
                                                                    (69, 1),
                                                                    (70, 1),
                                                                    (71, 1),
                                                                    (72, 1),
                                                                    (73, 1),
                                                                    (74, 1),
                                                                    (75, 1),
                                                                    (76, 1),
                                                                    (77, 1),
                                                                    (78, 1),
                                                                    (79, 1),
                                                                    (80, 1),
                                                                    (81, 1),
                                                                    (82, 1),
                                                                    (83, 1),
                                                                    (84, 1),
                                                                    (85, 1),
                                                                    (86, 1),
                                                                    (87, 1),
                                                                    (88, 1),
                                                                    (89, 1),
                                                                    (90, 1),
                                                                    (91, 1),
                                                                    (92, 1),
                                                                    (93, 1),
                                                                    (94, 1),
                                                                    (95, 1),
                                                                    (96, 1),
                                                                    (97, 1),
                                                                    (98, 1),
                                                                    (99, 1),
                                                                    (100, 1),
                                                                    (101, 1),
                                                                    (102, 1),
                                                                    (103, 1),
                                                                    (104, 1),
                                                                    (105, 1),
                                                                    (106, 1),
                                                                    (107, 1),
                                                                    (108, 1),
                                                                    (109, 1),
                                                                    (110, 1),
                                                                    (111, 1),
                                                                    (112, 1),
                                                                    (113, 1),
                                                                    (114, 1),
                                                                    (115, 1),
                                                                    (116, 1),
                                                                    (117, 1),
                                                                    (118, 1),
                                                                    (119, 1),
                                                                    (120, 1),
                                                                    (121, 1),
                                                                    (122, 1),
                                                                    (123, 1),
                                                                    (124, 1),
                                                                    (125, 1),
                                                                    (126, 1),
                                                                    (127, 1),
                                                                    (128, 1),
                                                                    (129, 1),
                                                                    (130, 1),
                                                                    (131, 1),
                                                                    (132, 1),
                                                                    (133, 1),
                                                                    (134, 1),
                                                                    (135, 1),
                                                                    (136, 1),
                                                                    (137, 1),
                                                                    (138, 1),
                                                                    (139, 1),
                                                                    (140, 1),
                                                                    (141, 1),
                                                                    (142, 1),
                                                                    (143, 1),
                                                                    (144, 1),
                                                                    (145, 1),
                                                                    (146, 1),
                                                                    (147, 1),
                                                                    (148, 1),
                                                                    (149, 1),
                                                                    (150, 1),
                                                                    (151, 1),
                                                                    (152, 1),
                                                                    (153, 1),
                                                                    (154, 1),
                                                                    (155, 1),
                                                                    (156, 1);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `date` date NOT NULL,
                         `due_date` date DEFAULT NULL,
                         `is_tax_included` tinyint(1) NOT NULL DEFAULT 0,
                         `reference` varchar(255) NOT NULL,
                         `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
                         `payment_term_id` bigint(20) UNSIGNED DEFAULT NULL,
                         `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                         `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                         `customer_name` varchar(255) NOT NULL,
                         `tax_percentage` int(11) NOT NULL DEFAULT 0,
                         `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                         `discount_percentage` int(11) NOT NULL DEFAULT 0,
                         `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                         `shipping_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                         `total_amount` decimal(15,2) NOT NULL,
                         `paid_amount` decimal(15,2) NOT NULL,
                         `due_amount` decimal(15,2) NOT NULL,
                         `status` varchar(255) NOT NULL,
                         `payment_status` varchar(255) NOT NULL,
                         `payment_method` varchar(255) NOT NULL,
                         `note` text DEFAULT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `date`, `due_date`, `is_tax_included`, `reference`, `customer_id`, `payment_term_id`, `tax_id`, `setting_id`, `customer_name`, `tax_percentage`, `tax_amount`, `discount_percentage`, `discount_amount`, `shipping_amount`, `total_amount`, `paid_amount`, `due_amount`, `status`, `payment_status`, `payment_method`, `note`, `created_at`, `updated_at`) VALUES
    (1, '2025-10-26', '2025-10-26', 0, 'TS-SL-2025-10-00001', 1, 858232, NULL, 1, 'USAHA', 0, 0.00, 0, 0.00, 0.00, 10000.00, 0.00, 10000.00, 'APPROVED', 'UNPAID', '', NULL, '2025-10-26 11:24:24', '2025-10-26 11:24:49');

-- --------------------------------------------------------

--
-- Table structure for table `sale_bundle_items`
--

CREATE TABLE `sale_bundle_items` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `sale_detail_id` bigint(20) UNSIGNED NOT NULL,
                                     `sale_id` bigint(20) UNSIGNED NOT NULL,
                                     `bundle_id` bigint(20) UNSIGNED NOT NULL,
                                     `bundle_item_id` bigint(20) UNSIGNED NOT NULL,
                                     `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `name` varchar(255) NOT NULL,
                                     `price` decimal(15,2) NOT NULL,
                                     `quantity` int(11) NOT NULL,
                                     `sub_total` decimal(15,2) NOT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_details`
--

CREATE TABLE `sale_details` (
                                `id` bigint(20) UNSIGNED NOT NULL,
                                `sale_id` bigint(20) UNSIGNED NOT NULL,
                                `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `product_name` varchar(255) NOT NULL,
                                `product_code` varchar(255) NOT NULL,
                                `quantity` int(11) NOT NULL,
                                `price` decimal(15,2) NOT NULL,
                                `unit_price` decimal(15,2) NOT NULL,
                                `sub_total` decimal(15,2) NOT NULL,
                                `product_discount_amount` decimal(15,2) NOT NULL,
                                `product_discount_type` varchar(255) NOT NULL DEFAULT 'fixed',
                                `product_tax_amount` decimal(15,2) NOT NULL,
                                `created_at` timestamp NULL DEFAULT NULL,
                                `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sale_details`
--

INSERT INTO `sale_details` (`id`, `sale_id`, `product_id`, `tax_id`, `product_name`, `product_code`, `quantity`, `price`, `unit_price`, `sub_total`, `product_discount_amount`, `product_discount_type`, `product_tax_amount`, `created_at`, `updated_at`) VALUES
    (1, 1, 1, NULL, 'PULPEN', '001', 2, 5000.00, 5000.00, 10000.00, 0.00, 'FIXED', 0.00, '2025-10-26 11:24:24', '2025-10-26 11:24:24');

-- --------------------------------------------------------

--
-- Table structure for table `sale_payments`
--

CREATE TABLE `sale_payments` (
                                 `id` bigint(20) UNSIGNED NOT NULL,
                                 `payment_method_id` bigint(20) UNSIGNED DEFAULT NULL,
                                 `sale_id` bigint(20) UNSIGNED NOT NULL,
                                 `amount` decimal(15,2) NOT NULL,
                                 `date` date NOT NULL,
                                 `reference` varchar(255) NOT NULL,
                                 `payment_method` varchar(255) NOT NULL,
                                 `note` text DEFAULT NULL,
                                 `created_at` timestamp NULL DEFAULT NULL,
                                 `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_payment_credit_applications`
--

CREATE TABLE `sale_payment_credit_applications` (
                                                    `id` bigint(20) UNSIGNED NOT NULL,
                                                    `sale_payment_id` bigint(20) UNSIGNED NOT NULL,
                                                    `customer_credit_id` bigint(20) UNSIGNED NOT NULL,
                                                    `amount` decimal(15,2) NOT NULL,
                                                    `created_at` timestamp NULL DEFAULT NULL,
                                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_returns`
--

CREATE TABLE `sale_returns` (
                                `id` bigint(20) UNSIGNED NOT NULL,
                                `date` date NOT NULL,
                                `reference` varchar(255) NOT NULL,
                                `sale_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `sale_reference` varchar(255) DEFAULT NULL,
                                `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `location_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `customer_name` varchar(255) NOT NULL,
                                `tax_percentage` int(11) NOT NULL DEFAULT 0,
                                `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                `discount_percentage` int(11) NOT NULL DEFAULT 0,
                                `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                `shipping_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                                `total_amount` decimal(15,2) NOT NULL,
                                `paid_amount` decimal(15,2) NOT NULL,
                                `due_amount` decimal(15,2) NOT NULL,
                                `approval_status` varchar(255) NOT NULL DEFAULT 'draft',
                                `return_type` varchar(255) DEFAULT NULL,
                                `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
                                `approved_at` timestamp NULL DEFAULT NULL,
                                `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
                                `rejected_at` timestamp NULL DEFAULT NULL,
                                `rejection_reason` text DEFAULT NULL,
                                `settled_at` timestamp NULL DEFAULT NULL,
                                `settled_by` bigint(20) UNSIGNED DEFAULT NULL,
                                `received_by` bigint(20) UNSIGNED DEFAULT NULL,
                                `received_at` timestamp NULL DEFAULT NULL,
                                `status` varchar(255) NOT NULL,
                                `payment_status` varchar(255) NOT NULL,
                                `payment_method` varchar(255) NOT NULL,
                                `cash_proof_path` varchar(255) DEFAULT NULL,
                                `note` text DEFAULT NULL,
                                `created_at` timestamp NULL DEFAULT NULL,
                                `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_return_details`
--

CREATE TABLE `sale_return_details` (
                                       `id` bigint(20) UNSIGNED NOT NULL,
                                       `sale_return_id` bigint(20) UNSIGNED NOT NULL,
                                       `sale_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `dispatch_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `location_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `product_name` varchar(255) NOT NULL,
                                       `product_code` varchar(255) NOT NULL,
                                       `quantity` int(11) NOT NULL,
                                       `price` decimal(15,2) NOT NULL,
                                       `unit_price` decimal(15,2) NOT NULL,
                                       `sub_total` decimal(15,2) NOT NULL,
                                       `product_discount_amount` decimal(15,2) NOT NULL,
                                       `product_discount_type` varchar(255) NOT NULL DEFAULT 'fixed',
                                       `product_tax_amount` decimal(15,2) NOT NULL,
                                       `tax_id` bigint(20) UNSIGNED DEFAULT NULL,
                                       `serial_number_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`serial_number_ids`)),
                                       `created_at` timestamp NULL DEFAULT NULL,
                                       `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_return_goods`
--

CREATE TABLE `sale_return_goods` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `sale_return_id` bigint(20) UNSIGNED NOT NULL,
                                     `product_id` bigint(20) UNSIGNED DEFAULT NULL,
                                     `product_name` varchar(255) NOT NULL,
                                     `product_code` varchar(255) DEFAULT NULL,
                                     `quantity` int(11) NOT NULL,
                                     `unit_value` decimal(15,2) DEFAULT NULL,
                                     `sub_total` decimal(15,2) DEFAULT NULL,
                                     `received_at` timestamp NULL DEFAULT NULL,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_return_payments`
--

CREATE TABLE `sale_return_payments` (
                                        `id` bigint(20) UNSIGNED NOT NULL,
                                        `sale_return_id` bigint(20) UNSIGNED NOT NULL,
                                        `amount` int(11) NOT NULL,
                                        `date` date NOT NULL,
                                        `reference` varchar(255) NOT NULL,
                                        `payment_method` varchar(255) NOT NULL,
                                        `note` text DEFAULT NULL,
                                        `created_at` timestamp NULL DEFAULT NULL,
                                        `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
                            `id` bigint(20) UNSIGNED NOT NULL,
                            `company_name` varchar(255) NOT NULL,
                            `company_email` varchar(255) NOT NULL,
                            `company_phone` varchar(255) NOT NULL,
                            `site_logo` varchar(255) DEFAULT NULL,
                            `default_currency_id` int(11) NOT NULL,
                            `default_currency_position` varchar(255) NOT NULL,
                            `notification_email` varchar(255) NOT NULL,
                            `footer_text` text NOT NULL,
                            `company_address` text NOT NULL,
                            `created_at` timestamp NULL DEFAULT NULL,
                            `updated_at` timestamp NULL DEFAULT NULL,
                            `document_prefix` varchar(255) DEFAULT NULL,
                            `purchase_prefix_document` varchar(255) DEFAULT NULL,
                            `sale_prefix_document` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `company_name`, `company_email`, `company_phone`, `site_logo`, `default_currency_id`, `default_currency_position`, `notification_email`, `footer_text`, `company_address`, `created_at`, `updated_at`, `document_prefix`, `purchase_prefix_document`, `sale_prefix_document`) VALUES
                                                                                                                                                                                                                                                                                                                (1, 'CV TIGA COMPUTER', 'contactus@tiga-computer.com', '012345678901', NULL, 1, 'PREFIX', 'notification@tiga-computer.com', 'CV TIGA COMPUTER © 2021', 'BIMA, NTB', '2025-10-26 11:06:16', '2025-10-26 11:06:16', 'TS', 'PR', 'SL'),
                                                                                                                                                                                                                                                                                                                (2, 'TIGA CAKRA', 'qwerty@mail.com', '12313213', NULL, 1, 'PREFIX', 'qwerty@mail.com', 'TIGA CAKRA © 2025', 'ALAMAT', '2025-10-26 11:10:54', '2025-10-26 11:10:54', 'DC', 'DP', 'DS');

-- --------------------------------------------------------

--
-- Table structure for table `setting_sale_locations`
--

CREATE TABLE `setting_sale_locations` (
                                          `id` bigint(20) UNSIGNED NOT NULL,
                                          `setting_id` bigint(20) UNSIGNED NOT NULL,
                                          `location_id` bigint(20) UNSIGNED NOT NULL,
                                          `is_pos` tinyint(1) DEFAULT 0 COMMENT 'Flag to mark if this location is used for POS',
                                          `created_at` timestamp NULL DEFAULT NULL,
                                          `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `setting_sale_locations`
--

INSERT INTO `setting_sale_locations` (`id`, `setting_id`, `location_id`, `is_pos`, `created_at`, `updated_at`) VALUES
                                                                                                                   (1, 1, 1, 1, '2025-10-26 11:11:48', '2025-10-26 11:12:33'),
                                                                                                                   (2, 1, 2, 0, '2025-10-26 11:11:57', '2025-10-26 11:11:57');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
                             `id` bigint(20) UNSIGNED NOT NULL,
                             `supplier_name` varchar(255) NOT NULL,
                             `supplier_email` varchar(255) NOT NULL,
                             `supplier_phone` varchar(255) NOT NULL,
                             `city` varchar(255) NOT NULL,
                             `country` varchar(255) NOT NULL,
                             `address` text NOT NULL,
                             `created_at` timestamp NULL DEFAULT NULL,
                             `updated_at` timestamp NULL DEFAULT NULL,
                             `contact_name` varchar(255) DEFAULT NULL,
                             `identity` varchar(255) DEFAULT NULL,
                             `identity_number` varchar(255) DEFAULT NULL,
                             `fax` varchar(255) DEFAULT NULL,
                             `npwp` varchar(255) DEFAULT NULL,
                             `billing_address` text DEFAULT NULL,
                             `shipping_address` text DEFAULT NULL,
                             `bank_name` varchar(255) DEFAULT NULL,
                             `bank_branch` varchar(255) DEFAULT NULL,
                             `account_number` varchar(255) DEFAULT NULL,
                             `account_holder` varchar(255) DEFAULT NULL,
                             `setting_id` bigint(20) UNSIGNED NOT NULL,
                             `payment_term_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_credits`
--

CREATE TABLE `supplier_credits` (
                                    `id` bigint(20) UNSIGNED NOT NULL,
                                    `supplier_id` bigint(20) UNSIGNED NOT NULL,
                                    `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
                                    `amount` decimal(15,2) NOT NULL,
                                    `remaining_amount` decimal(15,2) NOT NULL,
                                    `status` varchar(255) NOT NULL DEFAULT 'open',
                                    `created_at` timestamp NULL DEFAULT NULL,
                                    `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `taggables`
--

CREATE TABLE `taggables` (
                             `tag_id` bigint(20) UNSIGNED NOT NULL,
                             `taggable_type` varchar(255) NOT NULL,
                             `taggable_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tags`
--

CREATE TABLE `tags` (
                        `id` bigint(20) UNSIGNED NOT NULL,
                        `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`name`)),
                        `slug` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`slug`)),
                        `type` varchar(255) DEFAULT NULL,
                        `order_column` int(11) DEFAULT NULL,
                        `created_at` timestamp NULL DEFAULT NULL,
                        `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `taxes`
--

CREATE TABLE `taxes` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `value` decimal(8,2) NOT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `taxes`
--

INSERT INTO `taxes` (`id`, `name`, `value`, `created_at`, `updated_at`) VALUES
    (1, 'PPH 11', 11.00, '2025-10-26 11:11:16', '2025-10-26 11:11:16');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
                                `id` bigint(20) UNSIGNED NOT NULL,
                                `product_id` bigint(20) UNSIGNED NOT NULL,
                                `setting_id` bigint(20) UNSIGNED NOT NULL,
                                `quantity` int(11) NOT NULL COMMENT 'Quantity involved in the transaction',
                                `current_quantity` int(11) NOT NULL COMMENT 'Product quantity after the transaction',
                                `broken_quantity` int(11) DEFAULT NULL COMMENT 'Broken quantity if applicable',
                                `location_id` bigint(20) UNSIGNED NOT NULL,
                                `user_id` bigint(20) UNSIGNED DEFAULT NULL,
                                `reason` text DEFAULT NULL COMMENT 'Reason for the transaction',
                                `created_at` timestamp NULL DEFAULT NULL,
                                `updated_at` timestamp NULL DEFAULT NULL,
                                `type` varchar(20) NOT NULL,
                                `previous_quantity` int(11) NOT NULL,
                                `after_quantity` int(11) NOT NULL,
                                `previous_quantity_at_location` int(11) NOT NULL,
                                `after_quantity_at_location` int(11) NOT NULL,
                                `quantity_non_tax` int(11) NOT NULL,
                                `quantity_tax` int(11) NOT NULL,
                                `broken_quantity_non_tax` int(11) NOT NULL,
                                `broken_quantity_tax` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `product_id`, `setting_id`, `quantity`, `current_quantity`, `broken_quantity`, `location_id`, `user_id`, `reason`, `created_at`, `updated_at`, `type`, `previous_quantity`, `after_quantity`, `previous_quantity_at_location`, `after_quantity_at_location`, `quantity_non_tax`, `quantity_tax`, `broken_quantity_non_tax`, `broken_quantity_tax`) VALUES
    (1, 1, 1, 40, 40, 20, 1, 1, 'INITIAL STOCK SETUP', '2025-10-26 11:20:07', '2025-10-26 11:20:07', 'INIT', 0, 40, 0, 40, 10, 10, 10, 10);

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
                             `id` bigint(20) UNSIGNED NOT NULL,
                             `document_number` varchar(255) DEFAULT NULL,
                             `origin_location_id` bigint(20) UNSIGNED NOT NULL,
                             `destination_location_id` bigint(20) UNSIGNED NOT NULL,
                             `created_by` bigint(20) UNSIGNED NOT NULL,
                             `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `dispatched_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `return_dispatched_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `return_dispatched_at` timestamp NULL DEFAULT NULL,
                             `return_received_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `return_received_at` timestamp NULL DEFAULT NULL,
                             `status` enum('PENDING','APPROVED','REJECTED','DISPATCHED','RECEIVED','RETURN_DISPATCHED','RETURN_RECEIVED') NOT NULL DEFAULT 'PENDING',
                             `approved_at` timestamp NULL DEFAULT NULL,
                             `rejected_at` timestamp NULL DEFAULT NULL,
                             `dispatched_at` timestamp NULL DEFAULT NULL,
                             `received_at` timestamp NULL DEFAULT NULL,
                             `received_by` bigint(20) UNSIGNED DEFAULT NULL,
                             `created_at` timestamp NULL DEFAULT NULL,
                             `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_products`
--

CREATE TABLE `transfer_products` (
                                     `id` bigint(20) UNSIGNED NOT NULL,
                                     `transfer_id` bigint(20) UNSIGNED NOT NULL,
                                     `product_id` bigint(20) UNSIGNED NOT NULL,
                                     `quantity` int(11) NOT NULL,
                                     `serial_numbers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`serial_numbers`)),
                                     `quantity_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `quantity_non_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `quantity_broken_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `quantity_broken_non_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `created_at` timestamp NULL DEFAULT NULL,
                                     `updated_at` timestamp NULL DEFAULT NULL,
                                     `dispatched_at` timestamp NULL DEFAULT NULL,
                                     `dispatched_by` bigint(20) UNSIGNED DEFAULT NULL,
                                     `dispatched_quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `dispatched_quantity_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `dispatched_quantity_non_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `dispatched_quantity_broken_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `dispatched_quantity_broken_non_tax` int(10) UNSIGNED NOT NULL DEFAULT 0,
                                     `dispatched_serial_numbers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dispatched_serial_numbers`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `setting_id` bigint(20) UNSIGNED DEFAULT NULL,
                         `name` varchar(255) DEFAULT NULL,
                         `short_name` varchar(255) DEFAULT NULL,
                         `operator` varchar(255) DEFAULT NULL,
                         `operation_value` int(11) DEFAULT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `setting_id`, `name`, `short_name`, `operator`, `operation_value`, `created_at`, `updated_at`) VALUES
    (1, NULL, 'PIECE', 'PC(S)', '*', 1, '2025-10-26 11:06:17', '2025-10-26 11:06:17');

-- --------------------------------------------------------

--
-- Table structure for table `uploads`
--

CREATE TABLE `uploads` (
                           `id` bigint(20) UNSIGNED NOT NULL,
                           `folder` varchar(255) NOT NULL,
                           `filename` varchar(255) NOT NULL,
                           `created_at` timestamp NULL DEFAULT NULL,
                           `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
                         `id` bigint(20) UNSIGNED NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `email` varchar(255) NOT NULL,
                         `email_verified_at` timestamp NULL DEFAULT NULL,
                         `password` varchar(255) NOT NULL,
                         `is_active` tinyint(1) NOT NULL,
                         `remember_token` varchar(100) DEFAULT NULL,
                         `created_at` timestamp NULL DEFAULT NULL,
                         `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `is_active`, `remember_token`, `created_at`, `updated_at`) VALUES
    (1, 'ADMINISTRATOR', 'super.admin@tiga-computer.com', NULL, '$2y$10$Mw9SuN5mrW6b/pN9qcdL/e/kf314pbO0noI0pltpPw7ncjkjbwSsm', 1, NULL, '2025-10-26 11:06:16', '2025-10-26 11:06:16');

-- --------------------------------------------------------

--
-- Table structure for table `user_setting`
--

CREATE TABLE `user_setting` (
                                `id` bigint(20) UNSIGNED NOT NULL,
                                `user_id` bigint(20) UNSIGNED NOT NULL,
                                `setting_id` bigint(20) UNSIGNED NOT NULL,
                                `role_id` bigint(20) UNSIGNED NOT NULL,
                                `created_at` timestamp NULL DEFAULT NULL,
                                `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `websockets_statistics_entries`
--

CREATE TABLE `websockets_statistics_entries` (
                                                 `id` int(10) UNSIGNED NOT NULL,
                                                 `app_id` varchar(255) NOT NULL,
                                                 `peak_connection_count` int(11) NOT NULL,
                                                 `websocket_message_count` int(11) NOT NULL,
                                                 `api_message_count` int(11) NOT NULL,
                                                 `created_at` timestamp NULL DEFAULT NULL,
                                                 `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `adjusted_products`
--
ALTER TABLE `adjusted_products`
    ADD PRIMARY KEY (`id`),
  ADD KEY `adjusted_products_adjustment_id_foreign` (`adjustment_id`);

--
-- Indexes for table `adjustments`
--
ALTER TABLE `adjustments`
    ADD PRIMARY KEY (`id`),
  ADD KEY `adjustments_location_id_foreign` (`location_id`);

--
-- Indexes for table `audits`
--
ALTER TABLE `audits`
    ADD PRIMARY KEY (`id`),
  ADD KEY `audits_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  ADD KEY `audits_user_id_user_type_index` (`user_id`,`user_type`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
    ADD PRIMARY KEY (`id`),
  ADD KEY `brands_setting_id_foreign` (`setting_id`),
  ADD KEY `brands_created_by_foreign` (`created_by`);

--
-- Indexes for table `cashier_cash_movements`
--
ALTER TABLE `cashier_cash_movements`
    ADD PRIMARY KEY (`id`),
  ADD KEY `cashier_cash_movements_user_id_foreign` (`user_id`),
  ADD KEY `cashier_cash_movements_movement_type_index` (`movement_type`),
  ADD KEY `cashier_cash_movements_recorded_at_index` (`recorded_at`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `categories_category_code_unique` (`category_code`),
  ADD KEY `categories_parent_id_foreign` (`parent_id`),
  ADD KEY `categories_created_by_foreign` (`created_by`),
  ADD KEY `categories_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chart_of_accounts_name_unique` (`name`),
  ADD UNIQUE KEY `chart_of_accounts_account_number_unique` (`account_number`),
  ADD KEY `chart_of_accounts_parent_account_id_foreign` (`parent_account_id`),
  ADD KEY `chart_of_accounts_tax_id_foreign` (`tax_id`),
  ADD KEY `chart_of_accounts_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
    ADD PRIMARY KEY (`id`),
  ADD KEY `customers_setting_id_foreign` (`setting_id`),
  ADD KEY `customers_payment_term_id_foreign` (`payment_term_id`);

--
-- Indexes for table `customer_credits`
--
ALTER TABLE `customer_credits`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_credits_sale_return_id_unique` (`sale_return_id`),
  ADD KEY `customer_credits_customer_id_status_index` (`customer_id`,`status`);

--
-- Indexes for table `dispatches`
--
ALTER TABLE `dispatches`
    ADD PRIMARY KEY (`id`),
  ADD KEY `dispatches_sale_id_foreign` (`sale_id`);

--
-- Indexes for table `dispatch_details`
--
ALTER TABLE `dispatch_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `dispatch_details_dispatch_id_foreign` (`dispatch_id`),
  ADD KEY `dispatch_details_sale_id_foreign` (`sale_id`),
  ADD KEY `dispatch_details_product_id_foreign` (`product_id`),
  ADD KEY `dispatch_details_location_id_foreign` (`location_id`),
  ADD KEY `dispatch_details_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
    ADD PRIMARY KEY (`id`),
  ADD KEY `expenses_category_id_foreign` (`category_id`),
  ADD KEY `expenses_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
    ADD PRIMARY KEY (`id`),
  ADD KEY `expense_categories_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `expense_details`
--
ALTER TABLE `expense_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `expense_details_expense_id_foreign` (`expense_id`),
  ADD KEY `expense_details_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
    ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `journals`
--
ALTER TABLE `journals`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_items`
--
ALTER TABLE `journal_items`
    ADD PRIMARY KEY (`id`),
  ADD KEY `journal_items_journal_id_foreign` (`journal_id`),
  ADD KEY `journal_items_chart_of_account_id_foreign` (`chart_of_account_id`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
    ADD PRIMARY KEY (`id`),
  ADD KEY `locations_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `media`
--
ALTER TABLE `media`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `media_uuid_unique` (`uuid`),
  ADD KEY `media_model_type_model_id_index` (`model_type`,`model_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
    ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
    ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
    ADD KEY `password_resets_email_index` (`email`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
    ADD PRIMARY KEY (`id`),
  ADD KEY `payment_methods_coa_id_foreign` (`coa_id`);

--
-- Indexes for table `payment_terms`
--
ALTER TABLE `payment_terms`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `products_product_code_unique` (`product_code`),
  ADD KEY `products_unit_id_foreign` (`unit_id`),
  ADD KEY `products_setting_id_foreign` (`setting_id`),
  ADD KEY `products_category_id_foreign` (`category_id`),
  ADD KEY `products_brand_id_foreign` (`brand_id`),
  ADD KEY `products_base_unit_id_foreign` (`base_unit_id`),
  ADD KEY `products_purchase_tax_id_foreign` (`purchase_tax_id`),
  ADD KEY `products_sale_tax_id_foreign` (`sale_tax_id`);

--
-- Indexes for table `product_bundles`
--
ALTER TABLE `product_bundles`
    ADD PRIMARY KEY (`id`),
  ADD KEY `product_bundles_parent_product_id_foreign` (`parent_product_id`);

--
-- Indexes for table `product_bundle_items`
--
ALTER TABLE `product_bundle_items`
    ADD PRIMARY KEY (`id`),
  ADD KEY `product_bundle_items_bundle_id_foreign` (`bundle_id`),
  ADD KEY `product_bundle_items_product_id_foreign` (`product_id`);

--
-- Indexes for table `product_import_batches`
--
ALTER TABLE `product_import_batches`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_import_batches_undo_token_unique` (`undo_token`),
  ADD KEY `product_import_batches_user_id_foreign` (`user_id`),
  ADD KEY `product_import_batches_location_id_foreign` (`location_id`),
  ADD KEY `product_import_batches_status_index` (`status`),
  ADD KEY `product_import_batches_created_at_index` (`created_at`);

--
-- Indexes for table `product_import_rows`
--
ALTER TABLE `product_import_rows`
    ADD PRIMARY KEY (`id`),
  ADD KEY `product_import_rows_batch_id_row_number_index` (`batch_id`,`row_number`),
  ADD KEY `product_import_rows_status_index` (`status`),
  ADD KEY `product_import_rows_product_id_index` (`product_id`);

--
-- Indexes for table `product_prices`
--
ALTER TABLE `product_prices`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_prices_product_id_setting_id_unique` (`product_id`,`setting_id`),
  ADD KEY `product_prices_purchase_tax_id_foreign` (`purchase_tax_id`),
  ADD KEY `product_prices_sale_tax_id_foreign` (`sale_tax_id`),
  ADD KEY `product_prices_product_id_index` (`product_id`),
  ADD KEY `product_prices_setting_id_index` (`setting_id`);

--
-- Indexes for table `product_serial_numbers`
--
ALTER TABLE `product_serial_numbers`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_serial_numbers_serial_number_unique` (`serial_number`),
  ADD KEY `product_serial_numbers_product_id_foreign` (`product_id`),
  ADD KEY `product_serial_numbers_location_id_foreign` (`location_id`),
  ADD KEY `product_serial_numbers_tax_id_foreign` (`tax_id`),
  ADD KEY `product_serial_numbers_received_note_detail_id_foreign` (`received_note_detail_id`),
  ADD KEY `product_serial_numbers_dispatch_detail_id_foreign` (`dispatch_detail_id`);

--
-- Indexes for table `product_stocks`
--
ALTER TABLE `product_stocks`
    ADD PRIMARY KEY (`id`),
  ADD KEY `product_stocks_product_id_foreign` (`product_id`),
  ADD KEY `product_stocks_location_id_foreign` (`location_id`),
  ADD KEY `product_stocks_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `product_unit_conversions`
--
ALTER TABLE `product_unit_conversions`
    ADD PRIMARY KEY (`id`),
  ADD KEY `product_unit_conversions_product_id_foreign` (`product_id`),
  ADD KEY `product_unit_conversions_unit_id_foreign` (`unit_id`),
  ADD KEY `product_unit_conversions_base_unit_id_foreign` (`base_unit_id`);

--
-- Indexes for table `product_unit_conversion_prices`
--
ALTER TABLE `product_unit_conversion_prices`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `conversion_setting_unique` (`product_unit_conversion_id`,`setting_id`),
  ADD KEY `conv_price_setting_fk` (`setting_id`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchases_supplier_id_foreign` (`supplier_id`),
  ADD KEY `purchases_setting_id_foreign` (`setting_id`),
  ADD KEY `purchases_tax_id_foreign` (`tax_id`),
  ADD KEY `purchases_payment_term_id_foreign` (`payment_term_id`);

--
-- Indexes for table `purchase_details`
--
ALTER TABLE `purchase_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_details_purchase_id_foreign` (`purchase_id`),
  ADD KEY `purchase_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_details_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_payments_purchase_id_foreign` (`purchase_id`),
  ADD KEY `purchase_payments_payment_method_id_foreign` (`payment_method_id`);

--
-- Indexes for table `purchase_payment_credit_applications`
--
ALTER TABLE `purchase_payment_credit_applications`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_purchase_payment_credit` (`purchase_payment_id`,`supplier_credit_id`),
  ADD KEY `purchase_payment_credit_applications_supplier_credit_id_foreign` (`supplier_credit_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_returns_supplier_id_foreign` (`supplier_id`),
  ADD KEY `purchase_returns_approved_by_foreign` (`approved_by`),
  ADD KEY `purchase_returns_rejected_by_foreign` (`rejected_by`),
  ADD KEY `purchase_returns_approval_status_index` (`approval_status`),
  ADD KEY `purchase_returns_return_type_index` (`return_type`),
  ADD KEY `purchase_returns_setting_id_foreign` (`setting_id`),
  ADD KEY `purchase_returns_location_id_foreign` (`location_id`),
  ADD KEY `purchase_returns_settled_by_foreign` (`settled_by`);

--
-- Indexes for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_return_details_purchase_return_id_foreign` (`purchase_return_id`),
  ADD KEY `purchase_return_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_return_details_po_id_foreign` (`po_id`);

--
-- Indexes for table `purchase_return_goods`
--
ALTER TABLE `purchase_return_goods`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_return_goods_purchase_return_id_index` (`purchase_return_id`),
  ADD KEY `purchase_return_goods_product_id_index` (`product_id`),
  ADD KEY `prg_return_received_idx` (`purchase_return_id`,`received_at`);

--
-- Indexes for table `purchase_return_payments`
--
ALTER TABLE `purchase_return_payments`
    ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_return_payments_purchase_return_id_foreign` (`purchase_return_id`),
  ADD KEY `purchase_return_payments_payment_method_id_index` (`payment_method_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
    ADD PRIMARY KEY (`id`),
  ADD KEY `quotations_customer_id_foreign` (`customer_id`);

--
-- Indexes for table `quotation_details`
--
ALTER TABLE `quotation_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `quotation_details_quotation_id_foreign` (`quotation_id`),
  ADD KEY `quotation_details_product_id_foreign` (`product_id`);

--
-- Indexes for table `received_notes`
--
ALTER TABLE `received_notes`
    ADD PRIMARY KEY (`id`),
  ADD KEY `received_notes_po_id_foreign` (`po_id`);

--
-- Indexes for table `received_note_details`
--
ALTER TABLE `received_note_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `received_note_details_received_note_id_foreign` (`received_note_id`),
  ADD KEY `received_note_details_po_detail_id_foreign` (`po_detail_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
    ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sales_customer_id_foreign` (`customer_id`),
  ADD KEY `sales_payment_term_id_foreign` (`payment_term_id`),
  ADD KEY `sales_tax_id_foreign` (`tax_id`),
  ADD KEY `sales_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `sale_bundle_items`
--
ALTER TABLE `sale_bundle_items`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_bundle_items_sale_detail_id_foreign` (`sale_detail_id`),
  ADD KEY `sale_bundle_items_sale_id_foreign` (`sale_id`),
  ADD KEY `sale_bundle_items_product_id_foreign` (`product_id`);

--
-- Indexes for table `sale_details`
--
ALTER TABLE `sale_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_details_sale_id_foreign` (`sale_id`),
  ADD KEY `sale_details_product_id_foreign` (`product_id`),
  ADD KEY `sale_details_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `sale_payments`
--
ALTER TABLE `sale_payments`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_payments_sale_id_foreign` (`sale_id`),
  ADD KEY `sale_payments_payment_method_id_foreign` (`payment_method_id`);

--
-- Indexes for table `sale_payment_credit_applications`
--
ALTER TABLE `sale_payment_credit_applications`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sale_payment_credit` (`sale_payment_id`,`customer_credit_id`),
  ADD KEY `sale_payment_credit_applications_customer_credit_id_foreign` (`customer_credit_id`);

--
-- Indexes for table `sale_returns`
--
ALTER TABLE `sale_returns`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_returns_customer_id_foreign` (`customer_id`),
  ADD KEY `sale_returns_sale_id_foreign` (`sale_id`),
  ADD KEY `sale_returns_setting_id_foreign` (`setting_id`),
  ADD KEY `sale_returns_location_id_foreign` (`location_id`),
  ADD KEY `sale_returns_approval_status_index` (`approval_status`),
  ADD KEY `sale_returns_return_type_index` (`return_type`),
  ADD KEY `sale_returns_approved_by_foreign` (`approved_by`),
  ADD KEY `sale_returns_rejected_by_foreign` (`rejected_by`),
  ADD KEY `sale_returns_settled_by_foreign` (`settled_by`),
  ADD KEY `sale_returns_received_by_foreign` (`received_by`);

--
-- Indexes for table `sale_return_details`
--
ALTER TABLE `sale_return_details`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_return_details_sale_return_id_foreign` (`sale_return_id`),
  ADD KEY `sale_return_details_product_id_foreign` (`product_id`),
  ADD KEY `sale_return_details_sale_detail_id_foreign` (`sale_detail_id`),
  ADD KEY `sale_return_details_dispatch_detail_id_foreign` (`dispatch_detail_id`),
  ADD KEY `sale_return_details_location_id_foreign` (`location_id`),
  ADD KEY `sale_return_details_tax_id_foreign` (`tax_id`);

--
-- Indexes for table `sale_return_goods`
--
ALTER TABLE `sale_return_goods`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_return_goods_sale_return_id_index` (`sale_return_id`),
  ADD KEY `sale_return_goods_product_id_index` (`product_id`),
  ADD KEY `srg_return_received_idx` (`sale_return_id`,`received_at`);

--
-- Indexes for table `sale_return_payments`
--
ALTER TABLE `sale_return_payments`
    ADD PRIMARY KEY (`id`),
  ADD KEY `sale_return_payments_sale_return_id_foreign` (`sale_return_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `setting_sale_locations`
--
ALTER TABLE `setting_sale_locations`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_sale_locations_location_id_unique` (`location_id`),
  ADD KEY `setting_sale_locations_setting_id_index` (`setting_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
    ADD PRIMARY KEY (`id`),
  ADD KEY `suppliers_setting_id_foreign` (`setting_id`),
  ADD KEY `suppliers_payment_term_id_foreign` (`payment_term_id`);

--
-- Indexes for table `supplier_credits`
--
ALTER TABLE `supplier_credits`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_credits_purchase_return_id_unique` (`purchase_return_id`),
  ADD KEY `supplier_credits_supplier_id_status_index` (`supplier_id`,`status`);

--
-- Indexes for table `taggables`
--
ALTER TABLE `taggables`
    ADD UNIQUE KEY `taggables_tag_id_taggable_id_taggable_type_unique` (`tag_id`,`taggable_id`,`taggable_type`),
    ADD KEY `taggables_taggable_type_taggable_id_index` (`taggable_type`,`taggable_id`);

--
-- Indexes for table `tags`
--
ALTER TABLE `tags`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `taxes`
--
ALTER TABLE `taxes`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
    ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_product_id_foreign` (`product_id`),
  ADD KEY `transactions_setting_id_foreign` (`setting_id`),
  ADD KEY `transactions_location_id_foreign` (`location_id`),
  ADD KEY `transactions_user_id_foreign` (`user_id`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transfers_origin_document_number_unique` (`origin_location_id`,`document_number`),
  ADD KEY `transfers_received_by_index` (`received_by`),
  ADD KEY `transfers_return_dispatched_by_index` (`return_dispatched_by`),
  ADD KEY `transfers_return_received_by_index` (`return_received_by`);

--
-- Indexes for table `transfer_products`
--
ALTER TABLE `transfer_products`
    ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_products_transfer_id_foreign` (`transfer_id`),
  ADD KEY `transfer_products_product_id_foreign` (`product_id`),
  ADD KEY `transfer_products_dispatched_by_index` (`dispatched_by`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
    ADD PRIMARY KEY (`id`),
  ADD KEY `units_setting_id_foreign` (`setting_id`);

--
-- Indexes for table `uploads`
--
ALTER TABLE `uploads`
    ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- Indexes for table `user_setting`
--
ALTER TABLE `user_setting`
    ADD PRIMARY KEY (`id`),
  ADD KEY `user_setting_user_id_foreign` (`user_id`),
  ADD KEY `user_setting_setting_id_foreign` (`setting_id`),
  ADD KEY `user_setting_role_id_foreign` (`role_id`);

--
-- Indexes for table `websockets_statistics_entries`
--
ALTER TABLE `websockets_statistics_entries`
    ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adjusted_products`
--
ALTER TABLE `adjusted_products`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adjustments`
--
ALTER TABLE `adjustments`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audits`
--
ALTER TABLE `audits`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
    MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cashier_cash_movements`
--
ALTER TABLE `cashier_cash_movements`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `customer_credits`
--
ALTER TABLE `customer_credits`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dispatches`
--
ALTER TABLE `dispatches`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dispatch_details`
--
ALTER TABLE `dispatch_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_details`
--
ALTER TABLE `expense_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journals`
--
ALTER TABLE `journals`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_items`
--
ALTER TABLE `journal_items`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `media`
--
ALTER TABLE `media`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
    MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment_terms`
--
ALTER TABLE `payment_terms`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2917155;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=157;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_bundles`
--
ALTER TABLE `product_bundles`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_bundle_items`
--
ALTER TABLE `product_bundle_items`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_import_batches`
--
ALTER TABLE `product_import_batches`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_import_rows`
--
ALTER TABLE `product_import_rows`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_prices`
--
ALTER TABLE `product_prices`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product_serial_numbers`
--
ALTER TABLE `product_serial_numbers`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_stocks`
--
ALTER TABLE `product_stocks`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_unit_conversions`
--
ALTER TABLE `product_unit_conversions`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_unit_conversion_prices`
--
ALTER TABLE `product_unit_conversion_prices`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_details`
--
ALTER TABLE `purchase_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payment_credit_applications`
--
ALTER TABLE `purchase_payment_credit_applications`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_goods`
--
ALTER TABLE `purchase_return_goods`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_payments`
--
ALTER TABLE `purchase_return_payments`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation_details`
--
ALTER TABLE `quotation_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `received_notes`
--
ALTER TABLE `received_notes`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `received_note_details`
--
ALTER TABLE `received_note_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sale_bundle_items`
--
ALTER TABLE `sale_bundle_items`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_details`
--
ALTER TABLE `sale_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sale_payments`
--
ALTER TABLE `sale_payments`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_payment_credit_applications`
--
ALTER TABLE `sale_payment_credit_applications`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_returns`
--
ALTER TABLE `sale_returns`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_return_details`
--
ALTER TABLE `sale_return_details`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_return_goods`
--
ALTER TABLE `sale_return_goods`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_return_payments`
--
ALTER TABLE `sale_return_payments`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `setting_sale_locations`
--
ALTER TABLE `setting_sale_locations`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_credits`
--
ALTER TABLE `supplier_credits`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tags`
--
ALTER TABLE `tags`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `taxes`
--
ALTER TABLE `taxes`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfer_products`
--
ALTER TABLE `transfer_products`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `uploads`
--
ALTER TABLE `uploads`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_setting`
--
ALTER TABLE `user_setting`
    MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `websockets_statistics_entries`
--
ALTER TABLE `websockets_statistics_entries`
    MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `adjusted_products`
--
ALTER TABLE `adjusted_products`
    ADD CONSTRAINT `adjusted_products_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `adjustments`
--
ALTER TABLE `adjustments`
    ADD CONSTRAINT `adjustments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `brands`
--
ALTER TABLE `brands`
    ADD CONSTRAINT `brands_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `brands_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cashier_cash_movements`
--
ALTER TABLE `cashier_cash_movements`
    ADD CONSTRAINT `cashier_cash_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
    ADD CONSTRAINT `categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `categories_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
    ADD CONSTRAINT `chart_of_accounts_parent_account_id_foreign` FOREIGN KEY (`parent_account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `chart_of_accounts_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chart_of_accounts_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
    ADD CONSTRAINT `customers_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `customers_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_credits`
--
ALTER TABLE `customer_credits`
    ADD CONSTRAINT `customer_credits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_credits_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dispatches`
--
ALTER TABLE `dispatches`
    ADD CONSTRAINT `dispatches_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dispatch_details`
--
ALTER TABLE `dispatch_details`
    ADD CONSTRAINT `dispatch_details_dispatch_id_foreign` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dispatch_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `dispatch_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dispatch_details_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dispatch_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
    ADD CONSTRAINT `expenses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `expenses_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expense_categories`
--
ALTER TABLE `expense_categories`
    ADD CONSTRAINT `expense_categories_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expense_details`
--
ALTER TABLE `expense_details`
    ADD CONSTRAINT `expense_details_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expense_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_items`
--
ALTER TABLE `journal_items`
    ADD CONSTRAINT `journal_items_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_items_journal_id_foreign` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `locations`
--
ALTER TABLE `locations`
    ADD CONSTRAINT `locations_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
    ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
    ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_methods`
--
ALTER TABLE `payment_methods`
    ADD CONSTRAINT `payment_methods_coa_id_foreign` FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
    ADD CONSTRAINT `products_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_purchase_tax_id_foreign` FOREIGN KEY (`purchase_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_sale_tax_id_foreign` FOREIGN KEY (`sale_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `products_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_bundles`
--
ALTER TABLE `product_bundles`
    ADD CONSTRAINT `product_bundles_parent_product_id_foreign` FOREIGN KEY (`parent_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_bundle_items`
--
ALTER TABLE `product_bundle_items`
    ADD CONSTRAINT `product_bundle_items_bundle_id_foreign` FOREIGN KEY (`bundle_id`) REFERENCES `product_bundles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_bundle_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_import_batches`
--
ALTER TABLE `product_import_batches`
    ADD CONSTRAINT `product_import_batches_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_import_batches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_import_rows`
--
ALTER TABLE `product_import_rows`
    ADD CONSTRAINT `product_import_rows_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `product_import_batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_prices`
--
ALTER TABLE `product_prices`
    ADD CONSTRAINT `product_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_prices_purchase_tax_id_foreign` FOREIGN KEY (`purchase_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_prices_sale_tax_id_foreign` FOREIGN KEY (`sale_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_prices_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_serial_numbers`
--
ALTER TABLE `product_serial_numbers`
    ADD CONSTRAINT `product_serial_numbers_dispatch_detail_id_foreign` FOREIGN KEY (`dispatch_detail_id`) REFERENCES `dispatch_details` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_serial_numbers_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_serial_numbers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_serial_numbers_received_note_detail_id_foreign` FOREIGN KEY (`received_note_detail_id`) REFERENCES `received_note_details` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_serial_numbers_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_stocks`
--
ALTER TABLE `product_stocks`
    ADD CONSTRAINT `product_stocks_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_stocks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_stocks_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_unit_conversions`
--
ALTER TABLE `product_unit_conversions`
    ADD CONSTRAINT `product_unit_conversions_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_unit_conversions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_unit_conversions_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_unit_conversion_prices`
--
ALTER TABLE `product_unit_conversion_prices`
    ADD CONSTRAINT `conv_price_conversion_fk` FOREIGN KEY (`product_unit_conversion_id`) REFERENCES `product_unit_conversions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conv_price_setting_fk` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
    ADD CONSTRAINT `purchases_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchases_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchases_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchases_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_details`
--
ALTER TABLE `purchase_details`
    ADD CONSTRAINT `purchase_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_details_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
    ADD CONSTRAINT `purchase_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_payments_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_payment_credit_applications`
--
ALTER TABLE `purchase_payment_credit_applications`
    ADD CONSTRAINT `purchase_payment_credit_applications_purchase_payment_id_foreign` FOREIGN KEY (`purchase_payment_id`) REFERENCES `purchase_payments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_payment_credit_applications_supplier_credit_id_foreign` FOREIGN KEY (`supplier_credit_id`) REFERENCES `supplier_credits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
    ADD CONSTRAINT `purchase_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
    ADD CONSTRAINT `purchase_return_details_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_return_details_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_return_goods`
--
ALTER TABLE `purchase_return_goods`
    ADD CONSTRAINT `purchase_return_goods_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_return_goods_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_return_payments`
--
ALTER TABLE `purchase_return_payments`
    ADD CONSTRAINT `purchase_return_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_return_payments_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quotations`
--
ALTER TABLE `quotations`
    ADD CONSTRAINT `quotations_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `quotation_details`
--
ALTER TABLE `quotation_details`
    ADD CONSTRAINT `quotation_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `quotation_details_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `received_notes`
--
ALTER TABLE `received_notes`
    ADD CONSTRAINT `received_notes_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `received_note_details`
--
ALTER TABLE `received_note_details`
    ADD CONSTRAINT `received_note_details_po_detail_id_foreign` FOREIGN KEY (`po_detail_id`) REFERENCES `purchase_details` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `received_note_details_received_note_id_foreign` FOREIGN KEY (`received_note_id`) REFERENCES `received_notes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
    ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
    ADD CONSTRAINT `sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_bundle_items`
--
ALTER TABLE `sale_bundle_items`
    ADD CONSTRAINT `sale_bundle_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_bundle_items_sale_detail_id_foreign` FOREIGN KEY (`sale_detail_id`) REFERENCES `sale_details` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_bundle_items_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_details`
--
ALTER TABLE `sale_details`
    ADD CONSTRAINT `sale_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_details_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_payments`
--
ALTER TABLE `sale_payments`
    ADD CONSTRAINT `sale_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_payments_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_payment_credit_applications`
--
ALTER TABLE `sale_payment_credit_applications`
    ADD CONSTRAINT `sale_payment_credit_applications_customer_credit_id_foreign` FOREIGN KEY (`customer_credit_id`) REFERENCES `customer_credits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_payment_credit_applications_sale_payment_id_foreign` FOREIGN KEY (`sale_payment_id`) REFERENCES `sale_payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_returns`
--
ALTER TABLE `sale_returns`
    ADD CONSTRAINT `sale_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_returns_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_return_details`
--
ALTER TABLE `sale_return_details`
    ADD CONSTRAINT `sale_return_details_dispatch_detail_id_foreign` FOREIGN KEY (`dispatch_detail_id`) REFERENCES `dispatch_details` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_return_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_return_details_sale_detail_id_foreign` FOREIGN KEY (`sale_detail_id`) REFERENCES `sale_details` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_return_details_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_return_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_return_goods`
--
ALTER TABLE `sale_return_goods`
    ADD CONSTRAINT `sale_return_goods_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sale_return_goods_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_return_payments`
--
ALTER TABLE `sale_return_payments`
    ADD CONSTRAINT `sale_return_payments_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `setting_sale_locations`
--
ALTER TABLE `setting_sale_locations`
    ADD CONSTRAINT `setting_sale_locations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `setting_sale_locations_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
    ADD CONSTRAINT `suppliers_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `suppliers_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_credits`
--
ALTER TABLE `supplier_credits`
    ADD CONSTRAINT `supplier_credits_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_credits_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `taggables`
--
ALTER TABLE `taggables`
    ADD CONSTRAINT `taggables_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
    ADD CONSTRAINT `transactions_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transfers`
--
ALTER TABLE `transfers`
    ADD CONSTRAINT `transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_return_dispatched_by_foreign` FOREIGN KEY (`return_dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_return_received_by_foreign` FOREIGN KEY (`return_received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transfer_products`
--
ALTER TABLE `transfer_products`
    ADD CONSTRAINT `transfer_products_dispatched_by_foreign` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfer_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `transfer_products_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `units`
--
ALTER TABLE `units`
    ADD CONSTRAINT `units_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_setting`
--
ALTER TABLE `user_setting`
    ADD CONSTRAINT `user_setting_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_setting_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_setting_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;
