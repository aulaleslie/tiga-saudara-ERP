-- MySQL dump 10.13  Distrib 9.2.0, for Linux (x86_64)
--
-- Host: localhost    Database: tiga_saudara
-- ------------------------------------------------------
-- Server version	9.2.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `tiga_saudara`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `tiga_saudara` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `tiga_saudara`;

--
-- Table structure for table `adjusted_products`
--

DROP TABLE IF EXISTS `adjusted_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjusted_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL,
  `quantity_tax` int unsigned NOT NULL DEFAULT '0',
  `quantity_non_tax` int unsigned NOT NULL DEFAULT '0',
  `serial_numbers` text COLLATE utf8mb4_unicode_ci,
  `is_taxable` tinyint(1) NOT NULL DEFAULT '0',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `adjusted_products_adjustment_id_foreign` (`adjustment_id`),
  CONSTRAINT `adjusted_products_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adjusted_products`
--

LOCK TABLES `adjusted_products` WRITE;
/*!40000 ALTER TABLE `adjusted_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `adjusted_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adjustments`
--

DROP TABLE IF EXISTS `adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `location_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `adjustments_location_id_foreign` (`location_id`),
  CONSTRAINT `adjustments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adjustments`
--

LOCK TABLES `adjustments` WRITE;
/*!40000 ALTER TABLE `adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audits`
--

DROP TABLE IF EXISTS `audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_id` bigint unsigned NOT NULL,
  `old_values` text COLLATE utf8mb4_unicode_ci,
  `new_values` text COLLATE utf8mb4_unicode_ci,
  `url` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(1023) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audits_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audits_user_id_user_type_index` (`user_id`,`user_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audits`
--

LOCK TABLES `audits` WRITE;
/*!40000 ALTER TABLE `audits` DISABLE KEYS */;
INSERT INTO `audits` VALUES (1,'App\\Models\\User',1,'created','Modules\\Product\\Entities\\Category',2,'[]','{\"category_code\":\"CA_02\",\"category_name\":\"GROCERY\",\"parent_id\":null,\"created_by\":1,\"setting_id\":1,\"id\":2}','http://localhost:8000/product-categories','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',NULL,'2025-12-01 13:19:46','2025-12-01 13:19:46');
/*!40000 ALTER TABLE `audits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brands` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brands_setting_name_unique` (`setting_id`,`name`),
  KEY `brands_created_by_foreign` (`created_by`),
  CONSTRAINT `brands_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `brands_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brands`
--

LOCK TABLES `brands` WRITE;
/*!40000 ALTER TABLE `brands` DISABLE KEYS */;
INSERT INTO `brands` VALUES (1,1,'SIDU',NULL,1,NULL,'2025-12-01 13:19:33','2025-12-01 13:19:33');
/*!40000 ALTER TABLE `brands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_cash_movements`
--

DROP TABLE IF EXISTS `cashier_cash_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashier_cash_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `movement_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expected_total` decimal(15,2) DEFAULT NULL,
  `variance` decimal(15,2) DEFAULT NULL,
  `denominations` json DEFAULT NULL,
  `documents` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `recorded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cashier_cash_movements_user_id_foreign` (`user_id`),
  KEY `cashier_cash_movements_movement_type_index` (`movement_type`),
  KEY `cashier_cash_movements_recorded_at_index` (`recorded_at`),
  CONSTRAINT `cashier_cash_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_cash_movements`
--

LOCK TABLES `cashier_cash_movements` WRITE;
/*!40000 ALTER TABLE `cashier_cash_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `cashier_cash_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_category_code_unique` (`category_code`),
  UNIQUE KEY `categories_setting_category_name_unique` (`setting_id`,`category_name`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  KEY `categories_created_by_foreign` (`created_by`),
  CONSTRAINT `categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categories_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'CA_01','STATIONERY',NULL,1,1,'2025-12-01 13:15:27','2025-12-01 13:15:27'),(2,'CA_02','GROCERY',NULL,1,1,'2025-12-01 13:19:46','2025-12-01 13:19:46');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_of_accounts`
--

DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('Akun Piutang','Aktiva Lancar Lainnya','Kas & Bank','Persediaan','Aktiva Tetap','Aktiva Lainnya','Depresiasi & Amortisasi','Akun Hutang','Kartu Kredit','Kewajiban Lancar Lainnya','Kewajiban Jangka Panjang','Ekuitas','Pendapatan','Pendapatan Lainnya','Harga Pokok Penjualan','Beban','Beban Lainnya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_account_id` bigint unsigned DEFAULT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chart_of_accounts_name_unique` (`name`),
  UNIQUE KEY `chart_of_accounts_account_number_unique` (`account_number`),
  KEY `chart_of_accounts_parent_account_id_foreign` (`parent_account_id`),
  KEY `chart_of_accounts_tax_id_foreign` (`tax_id`),
  KEY `chart_of_accounts_setting_id_foreign` (`setting_id`),
  CONSTRAINT `chart_of_accounts_parent_account_id_foreign` FOREIGN KEY (`parent_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `chart_of_accounts_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chart_of_accounts_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_of_accounts`
--

LOCK TABLES `chart_of_accounts` WRITE;
/*!40000 ALTER TABLE `chart_of_accounts` DISABLE KEYS */;
INSERT INTO `chart_of_accounts` VALUES (1,1,'KAS','1-10001','Kas & Bank',NULL,NULL,'AKUN KAS UNTUK TRANSAKSI TUNAI','2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `chart_of_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `currency_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thousand_separator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimal_separator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange_rate` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES (1,'RUPIAH','IDR','RP',',','.',NULL,'2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_credits`
--

DROP TABLE IF EXISTS `customer_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_credits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `sale_return_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `remaining_amount` decimal(15,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_credits_sale_return_id_unique` (`sale_return_id`),
  KEY `customer_credits_customer_id_status_index` (`customer_id`,`status`),
  CONSTRAINT `customer_credits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_credits_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_credits`
--

LOCK TABLES `customer_credits` WRITE;
/*!40000 ALTER TABLE `customer_credits` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_credits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `npwp` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address` text COLLATE utf8mb4_unicode_ci,
  `shipping_address` text COLLATE utf8mb4_unicode_ci,
  `fax` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_branch` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_holder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setting_id` bigint unsigned DEFAULT NULL,
  `additional_info` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_term_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_setting_phone_unique` (`setting_id`,`customer_phone`),
  UNIQUE KEY `customers_setting_email_unique` (`setting_id`,`customer_email`),
  UNIQUE KEY `customers_setting_identity_unique` (`setting_id`,`identity_number`),
  UNIQUE KEY `customers_setting_npwp_unique` (`setting_id`,`npwp`),
  KEY `customers_payment_term_id_foreign` (`payment_term_id`),
  CONSTRAINT `customers_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'','','081249003893','','','','2025-12-01 13:29:46','2025-12-01 13:29:46',NULL,'WALK IN',NULL,'JALAN SWATANTRA V','JALAN SWATANTRA V',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'',NULL,858232);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_details`
--

DROP TABLE IF EXISTS `dispatch_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tax_id` bigint unsigned DEFAULT NULL,
  `dispatch_id` bigint unsigned NOT NULL,
  `sale_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `dispatched_quantity` int NOT NULL,
  `serial_numbers` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dispatch_details_dispatch_id_foreign` (`dispatch_id`),
  KEY `dispatch_details_sale_id_foreign` (`sale_id`),
  KEY `dispatch_details_product_id_foreign` (`product_id`),
  KEY `dispatch_details_location_id_foreign` (`location_id`),
  KEY `dispatch_details_tax_id_foreign` (`tax_id`),
  KEY `idx_dispatch_details_serial_numbers` ((cast(`serial_numbers` as char(255) charset utf8mb4))),
  CONSTRAINT `dispatch_details_dispatch_id_foreign` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dispatch_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_details_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_details`
--

LOCK TABLES `dispatch_details` WRITE;
/*!40000 ALTER TABLE `dispatch_details` DISABLE KEYS */;
INSERT INTO `dispatch_details` VALUES (1,NULL,1,1,1,1,1,NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21'),(2,NULL,1,1,2,2,1,NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `dispatch_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatches`
--

DROP TABLE IF EXISTS `dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint unsigned NOT NULL,
  `dispatch_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dispatches_sale_id_foreign` (`sale_id`),
  CONSTRAINT `dispatches_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatches`
--

LOCK TABLES `dispatches` WRITE;
/*!40000 ALTER TABLE `dispatches` DISABLE KEYS */;
INSERT INTO `dispatches` VALUES (1,1,'2025-12-01 00:00:00','2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `dispatches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned DEFAULT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_categories_setting_id_foreign` (`setting_id`),
  CONSTRAINT `expense_categories_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_details`
--

DROP TABLE IF EXISTS `expense_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expense_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_details_expense_id_foreign` (`expense_id`),
  KEY `expense_details_tax_id_foreign` (`tax_id`),
  CONSTRAINT `expense_details_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_details`
--

LOCK TABLES `expense_details` WRITE;
/*!40000 ALTER TABLE `expense_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned DEFAULT NULL,
  `category_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `amount` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expenses_category_id_foreign` (`category_id`),
  KEY `expenses_setting_id_foreign` (`setting_id`),
  CONSTRAINT `expenses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `expenses_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `global_purchase_and_sales_searches`
--

DROP TABLE IF EXISTS `global_purchase_and_sales_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `global_purchase_and_sales_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `search_query` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_types` json DEFAULT NULL,
  `filters_applied` json DEFAULT NULL,
  `results_count` int NOT NULL DEFAULT '0',
  `response_time_ms` int NOT NULL DEFAULT '0',
  `tenant_context` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `global_purchase_and_sales_searches_user_id_index` (`user_id`),
  KEY `global_purchase_and_sales_searches_setting_id_index` (`setting_id`),
  KEY `global_purchase_and_sales_searches_search_type_index` (`search_type`),
  KEY `global_purchase_and_sales_searches_created_at_index` (`created_at`),
  KEY `global_purchase_and_sales_searches_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `global_purchase_and_sales_searches_setting_id_created_at_index` (`setting_id`,`created_at`),
  KEY `global_purchase_and_sales_searches_search_type_created_at_index` (`search_type`,`created_at`),
  CONSTRAINT `global_purchase_and_sales_searches_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `global_purchase_and_sales_searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `global_purchase_and_sales_searches`
--

LOCK TABLES `global_purchase_and_sales_searches` WRITE;
/*!40000 ALTER TABLE `global_purchase_and_sales_searches` DISABLE KEYS */;
/*!40000 ALTER TABLE `global_purchase_and_sales_searches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `global_sales_searches`
--

DROP TABLE IF EXISTS `global_sales_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `global_sales_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `search_query` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `filters_applied` json DEFAULT NULL,
  `results_count` int NOT NULL DEFAULT '0',
  `response_time_ms` int NOT NULL DEFAULT '0',
  `search_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'serial',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `global_sales_searches_user_id_index` (`user_id`),
  KEY `global_sales_searches_setting_id_index` (`setting_id`),
  KEY `global_sales_searches_created_at_index` (`created_at`),
  KEY `global_sales_searches_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `global_sales_searches_setting_id_created_at_index` (`setting_id`,`created_at`),
  CONSTRAINT `global_sales_searches_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `global_sales_searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `global_sales_searches`
--

LOCK TABLES `global_sales_searches` WRITE;
/*!40000 ALTER TABLE `global_sales_searches` DISABLE KEYS */;
/*!40000 ALTER TABLE `global_sales_searches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
INSERT INTO `jobs` VALUES (1,'default','{\"uuid\":\"2c03840d-8bf1-4e88-9d7a-4efd7d4519d7\",\"displayName\":\"App\\\\Events\\\\PrintJobEvent\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"data\":{\"commandName\":\"Illuminate\\\\Broadcasting\\\\BroadcastEvent\",\"command\":\"O:38:\\\"Illuminate\\\\Broadcasting\\\\BroadcastEvent\\\":14:{s:5:\\\"event\\\";O:24:\\\"App\\\\Events\\\\PrintJobEvent\\\":3:{s:11:\\\"htmlContent\\\";s:8220:\\\"<!DOCTYPE html>\\r\\n<html>\\r\\n<head>\\r\\n    <meta charset=\\\"utf-8\\\">\\r\\n    <meta http-equiv=\\\"X-UA-Compatible\\\" content=\\\"IE=edge\\\">\\r\\n    <title><\\/title>\\r\\n    <meta name=\\\"description\\\" content=\\\"\\\">\\r\\n    <meta name=\\\"viewport\\\" content=\\\"width=device-width, initial-scale=1\\\">\\r\\n    <style>\\r\\n        * {\\r\\n            font-size: 12px;\\r\\n            line-height: 18px;\\r\\n            font-family: \'Ubuntu\', sans-serif;\\r\\n        }\\r\\n        h2 {\\r\\n            font-size: 16px;\\r\\n        }\\r\\n        td,\\r\\n        th,\\r\\n        tr,\\r\\n        table {\\r\\n            border-collapse: collapse;\\r\\n        }\\r\\n        tr {border-bottom: 1px dashed #ddd;}\\r\\n        td,th {padding: 7px 0;width: 50%;}\\r\\n\\r\\n        table {width: 100%;}\\r\\n        tfoot tr th:first-child {text-align: left;}\\r\\n\\r\\n        .centered {\\r\\n            text-align: center;\\r\\n            align-content: center;\\r\\n        }\\r\\n        small{font-size:11px;}\\r\\n\\r\\n        @media print {\\r\\n            * {\\r\\n                font-size:12px;\\r\\n                line-height: 20px;\\r\\n            }\\r\\n            td,th {padding: 5px 0;}\\r\\n            .hidden-print {\\r\\n                display: none !important;\\r\\n            }\\r\\n            tbody::after {\\r\\n                content: \'\';\\r\\n                display: block;\\r\\n                page-break-after: always;\\r\\n                page-break-inside: auto;\\r\\n                page-break-before: avoid;\\r\\n            }\\r\\n        }\\r\\n    <\\/style>\\r\\n<\\/head>\\r\\n<body>\\r\\n\\r\\n<div style=\\\"max-width:400px;margin:0 auto\\\">\\r\\n    <div id=\\\"receipt-data\\\">\\r\\n        <div class=\\\"centered\\\">\\r\\n            <h2 style=\\\"margin-bottom: 5px\\\">CV TIGA COMPUTER<\\/h2>\\r\\n\\r\\n            <p style=\\\"font-size: 11px;line-height: 15px;margin-top: 0\\\">\\r\\n                contactus@tiga-computer.com, 012345678901\\r\\n                <br>BIMA, NTB\\r\\n            <\\/p>\\r\\n        <\\/div>\\r\\n        \\r\\n        <p>\\r\\n            Date: 01 Dec, 2025<br>\\r\\n            Reference: PR-2025-12-00001<br>\\r\\n            Name: \\r\\n        <\\/p>\\r\\n\\r\\n                                    <table class=\\\"table-data\\\" style=\\\"margin-bottom: 10px;\\\">\\r\\n                    <tbody>\\r\\n                    <tr>\\r\\n                        <th colspan=\\\"3\\\" style=\\\"text-align:left;\\\">CV TIGA COMPUTER<\\/th>\\r\\n                    <\\/tr>\\r\\n                                            <tr>\\r\\n                            <td colspan=\\\"2\\\">\\r\\n                                KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET\\r\\n                                (1 x 55,000.00RP)\\r\\n                            <\\/td>\\r\\n                            <td style=\\\"text-align:right;vertical-align:bottom\\\">55,000.00RP<\\/td>\\r\\n                        <\\/tr>\\r\\n                                            <tr>\\r\\n                            <td colspan=\\\"2\\\">\\r\\n                                INDOMIE AYAM BAWANG\\r\\n                                (1 x 3,500.00RP)\\r\\n                            <\\/td>\\r\\n                            <td style=\\\"text-align:right;vertical-align:bottom\\\">3,500.00RP<\\/td>\\r\\n                        <\\/tr>\\r\\n                    \\r\\n                                            <tr>\\r\\n                            <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Tax<\\/th>\\r\\n                            <th style=\\\"text-align:right\\\">0.00RP<\\/th>\\r\\n                        <\\/tr>\\r\\n                                                                <tr>\\r\\n                            <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Discount<\\/th>\\r\\n                            <th style=\\\"text-align:right\\\">0.00RP<\\/th>\\r\\n                        <\\/tr>\\r\\n                                                                <tr>\\r\\n                            <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Shipping<\\/th>\\r\\n                            <th style=\\\"text-align:right\\\">0.00RP<\\/th>\\r\\n                        <\\/tr>\\r\\n                                        <tr>\\r\\n                        <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Subtotal<\\/th>\\r\\n                        <th style=\\\"text-align:right\\\">58,500.00RP<\\/th>\\r\\n                    <\\/tr>\\r\\n                    <\\/tbody>\\r\\n                <\\/table>\\r\\n            \\r\\n            <table>\\r\\n                <tbody>\\r\\n                <tr>\\r\\n                    <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Grand Total<\\/th>\\r\\n                    <th style=\\\"text-align:right\\\">58,500.00RP<\\/th>\\r\\n                <\\/tr>\\r\\n                <tr style=\\\"background-color:#ddd;\\\">\\r\\n                    <td class=\\\"centered\\\" style=\\\"padding: 5px;\\\">\\r\\n                        Paid By: CASH\\r\\n                    <\\/td>\\r\\n                    <td class=\\\"centered\\\" style=\\\"padding: 5px;\\\">\\r\\n                        Amount: 60,000.00RP\\r\\n                    <\\/td>\\r\\n                <\\/tr>\\r\\n                                    <tr>\\r\\n                        <th colspan=\\\"2\\\" style=\\\"text-align:left\\\">Change<\\/th>\\r\\n                        <th style=\\\"text-align:right\\\">1,500.00RP<\\/th>\\r\\n                    <\\/tr>\\r\\n                                <tr style=\\\"border-bottom: 0;\\\">\\r\\n                    <td class=\\\"centered\\\" colspan=\\\"3\\\">\\r\\n                        <div style=\\\"margin-top: 10px;\\\">\\r\\n                            <?xml version=\\\"1.0\\\" standalone=\\\"no\\\"?>\\n<!DOCTYPE svg PUBLIC \\\"-\\/\\/W3C\\/\\/DTD SVG 1.1\\/\\/EN\\\" \\\"http:\\/\\/www.w3.org\\/Graphics\\/SVG\\/1.1\\/DTD\\/svg11.dtd\\\">\\n<svg width=\\\"211\\\" height=\\\"25\\\" version=\\\"1.1\\\" xmlns=\\\"http:\\/\\/www.w3.org\\/2000\\/svg\\\">\\n\\t<g id=\\\"bars\\\" fill=\\\"black\\\" stroke=\\\"none\\\">\\n\\t\\t<rect x=\\\"0\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"3\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"6\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"11\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"15\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"19\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"22\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"27\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"29\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"33\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"36\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"39\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"44\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"46\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"50\\\" y=\\\"0\\\" width=\\\"4\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"55\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"59\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"62\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"66\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"71\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"73\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"77\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"79\\\" y=\\\"0\\\" width=\\\"4\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"84\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"88\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"91\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"94\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"99\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"102\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"107\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"110\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"114\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"119\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"121\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"124\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"127\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"132\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"134\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"138\\\" y=\\\"0\\\" width=\\\"4\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"143\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"146\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"150\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"154\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"157\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"161\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"165\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"167\\\" y=\\\"0\\\" width=\\\"4\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"172\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"176\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"179\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"184\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"187\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"191\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"195\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"198\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"203\\\" y=\\\"0\\\" width=\\\"3\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"207\\\" y=\\\"0\\\" width=\\\"1\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"209\\\" y=\\\"0\\\" width=\\\"2\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"211\\\" y=\\\"0\\\" width=\\\"0\\\" height=\\\"25\\\" \\/>\\n\\t\\t<rect x=\\\"211\\\" y=\\\"0\\\" width=\\\"0\\\" height=\\\"25\\\" \\/>\\n\\t<\\/g>\\n<\\/svg>\\n\\r\\n                        <\\/div>\\r\\n                    <\\/td>\\r\\n                <\\/tr>\\r\\n                <\\/tbody>\\r\\n            <\\/table>\\r\\n            <\\/div>\\r\\n<\\/div>\\r\\n\\r\\n<\\/body>\\r\\n<\\/html>\\r\\n\\\";s:6:\\\"userId\\\";i:1;s:4:\\\"type\\\";s:8:\\\"pos-sale\\\";}s:5:\\\"tries\\\";N;s:7:\\\"timeout\\\";N;s:7:\\\"backoff\\\";N;s:13:\\\"maxExceptions\\\";N;s:10:\\\"connection\\\";N;s:5:\\\"queue\\\";N;s:15:\\\"chainConnection\\\";N;s:10:\\\"chainQueue\\\";N;s:19:\\\"chainCatchCallbacks\\\";N;s:5:\\\"delay\\\";N;s:11:\\\"afterCommit\\\";N;s:10:\\\"middleware\\\";a:0:{}s:7:\\\"chained\\\";a:0:{}}\"}}',0,NULL,1764574641,1764574641);
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_items`
--

DROP TABLE IF EXISTS `journal_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `journal_id` bigint unsigned NOT NULL,
  `chart_of_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `type` enum('debit','credit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `journal_items_journal_id_foreign` (`journal_id`),
  KEY `journal_items_chart_of_account_id_foreign` (`chart_of_account_id`),
  CONSTRAINT `journal_items_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_items_journal_id_foreign` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_items`
--

LOCK TABLES `journal_items` WRITE;
/*!40000 ALTER TABLE `journal_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journals`
--

DROP TABLE IF EXISTS `journals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journals`
--

LOCK TABLES `journals` WRITE;
/*!40000 ALTER TABLE `journals` DISABLE KEYS */;
/*!40000 ALTER TABLE `journals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pos_cash_threshold` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `locations_setting_name_unique` (`setting_id`,`name`),
  CONSTRAINT `locations_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `locations`
--

LOCK TABLES `locations` WRITE;
/*!40000 ALTER TABLE `locations` DISABLE KEYS */;
INSERT INTO `locations` VALUES (1,1,'DISPLAY',0.00,'2025-12-01 13:17:49','2025-12-01 13:17:49'),(2,2,'GUDANG VIRTUAL TOP IT DI TIGA COMPUTER',0.00,'2025-12-01 13:18:09','2025-12-01 13:18:09');
/*!40000 ALTER TABLE `locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `media`
--

DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `manipulations` json NOT NULL,
  `custom_properties` json NOT NULL,
  `generated_conversions` json NOT NULL,
  `responsive_images` json NOT NULL,
  `order_column` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `media`
--

LOCK TABLES `media` WRITE;
/*!40000 ALTER TABLE `media` DISABLE KEYS */;
/*!40000 ALTER TABLE `media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=161 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0000_00_00_000000_create_websockets_statistics_entries_table',1),(2,'2014_10_12_000000_create_users_table',1),(3,'2014_10_12_100000_create_password_resets_table',1),(4,'2019_08_19_000000_create_failed_jobs_table',1),(5,'2019_12_14_000001_create_personal_access_tokens_table',1),(6,'2021_07_14_145038_create_categories_table',1),(7,'2021_07_14_145047_create_products_table',1),(8,'2021_07_15_211319_create_media_table',1),(9,'2021_07_16_010005_create_uploads_table',1),(10,'2021_07_16_220524_create_permission_tables',1),(11,'2021_07_22_003941_create_adjustments_table',1),(12,'2021_07_22_004043_create_adjusted_products_table',1),(13,'2021_07_28_192608_create_expense_categories_table',1),(14,'2021_07_28_192616_create_expenses_table',1),(15,'2021_07_29_165419_create_customers_table',1),(16,'2021_07_29_165440_create_suppliers_table',1),(17,'2021_07_31_015923_create_currencies_table',1),(18,'2021_07_31_140531_create_settings_table',1),(19,'2021_07_31_201003_create_sales_table',1),(20,'2021_07_31_212446_create_sale_details_table',1),(21,'2021_08_07_192203_create_sale_payments_table',1),(22,'2021_08_08_021108_create_purchases_table',1),(23,'2021_08_08_021131_create_purchase_payments_table',1),(24,'2021_08_08_021713_create_purchase_details_table',1),(25,'2021_08_08_175345_create_sale_returns_table',1),(26,'2021_08_08_175358_create_sale_return_details_table',1),(27,'2021_08_08_175406_create_sale_return_payments_table',1),(28,'2021_08_08_222603_create_purchase_returns_table',1),(29,'2021_08_08_222612_create_purchase_return_details_table',1),(30,'2021_08_08_222646_create_purchase_return_payments_table',1),(31,'2021_08_16_015031_create_quotations_table',1),(32,'2021_08_16_155013_create_quotation_details_table',1),(33,'2023_07_01_184221_create_units_table',1),(34,'2024_08_04_005934_create_user_setting_table',1),(35,'2024_08_05_021746_add_role_id_to_user_setting_table',1),(36,'2024_08_11_212922_add_brand_table',1),(37,'2024_08_12_034946_add_columns_to_categories_table',1),(38,'2024_08_12_213842_add_product_unit_conversions',1),(39,'2024_08_12_221237_add_unit_conversion_columns_to_products_table',1),(40,'2024_08_12_224144_add_setting_id_to_unit_table',1),(41,'2024_08_14_213433_add_locations_table',1),(42,'2024_08_15_220615_update_products_table_add_brand_stock_managed_and_nullable_category',1),(43,'2024_08_17_130443_add_barcode_and_profit_percentage',1),(44,'2024_08_17_130538_add_barcode',1),(45,'2024_08_17_143435_modify_product_foreign_relations',1),(46,'2024_08_20_104656_add_transactions_table',1),(47,'2024_08_23_233053_add_init_to_transaction_type_enum',1),(48,'2024_08_27_163344_add_type_and_status_to_adjustments_table',1),(49,'2024_08_27_184310_add_location_id_to_adjustments_table',1),(50,'2024_08_28_075105_add_broken_quantity_to_products_table',1),(51,'2024_09_03_172838_add_sale_and_purchase_price_and_tax_to_products_table',1),(52,'2024_09_05_222624_add_suppliers_info',1),(53,'2024_09_08_151743_add_stock_transfer_table',1),(54,'2024_09_08_151758_add_stock_transfer_product_table',1),(55,'2024_09_09_215715_add_setting_info_product_table',1),(56,'2024_09_09_224310_add_info_to_customer_table',1),(57,'2024_09_11_223749_add_additional_info_to_customer_table',1),(58,'2024_09_13_230003_add_taxes_table',1),(59,'2024_09_26_115105_add_serial_number_required',1),(60,'2024_09_26_115759_add_serial_number_table',1),(61,'2024_09_27_132637_add_product_stock_table',1),(62,'2024_10_03_225620_add_product_stock_info',1),(63,'2024_10_04_041346_add_product_tax_info',1),(64,'2024_10_29_120721_move_product_price_info',1),(65,'2024_10_29_174735_update_monetary_fields_to_decimal',1),(66,'2024_10_29_190105_add_setting_id_to_purchases_table',1),(67,'2024_10_29_190200_add_due_date_and_tax_id_to_purchases_table',1),(68,'2024_11_10_172538_create_audits_table',1),(69,'2024_11_27_075302_create_chart_of_accounts_table',1),(70,'2024_11_27_154759_add_payment_terms_table',1),(71,'2024_11_28_182827_add_payment_term_to_purchase_table',1),(72,'2024_11_28_184238_drop_supplier_name_from_purchases_table',1),(73,'2024_11_28_190002_add_tax_id_to_purchase_details_table',1),(74,'2024_11_28_190035_add_is_tax_included_to_purchases_table',1),(75,'2024_12_24_130535_create_received_notes_table',1),(76,'2024_12_24_131049_create_received_note_details_table',1),(77,'2025_01_07_185557_update_sales_table',1),(78,'2025_01_07_185711_update_sales_detail_table',1),(79,'2025_01_15_213903_add_payment_term_on_supplier',1),(80,'2025_01_15_213914_add_payment_term_on_customer',1),(81,'2025_01_22_190525_create_payment_methods_table',1),(82,'2025_01_22_201548_add_setting_id_on_coa',1),(83,'2025_01_24_195335_add_attachment_to_purchase_payment_table',1),(84,'2025_02_04_190016_add_receive_detail_id_to_product_serial_numbers',1),(85,'2025_02_16_181943_add_is_broken_to_product_serial_numbers',1),(86,'2025_02_16_185317_add_serial_numbers_to_adjusted_products',1),(87,'2025_02_17_155756_add_is_taxable_to_adjusted_products',1),(88,'2025_02_18_155755_add_po_id_to_purchase_return_details',1),(89,'2025_02_24_135737_add_tier_prices_to_product_table',1),(90,'2025_02_24_155406_add_tier_to_customers_table',1),(91,'2025_02_28_192255_add_product_bundles_table',1),(92,'2025_03_02_130536_add_product_bundle_items_table',1),(93,'2025_03_16_165122_create_journal_tables',1),(94,'2025_03_16_165139_create_journal_item_tables',1),(95,'2025_03_23_075342_update_sales_table_add_due_date_and_is_tax_included',1),(96,'2025_03_23_075357_create_sale_bundle_items_table',1),(97,'2025_03_25_133915_create_dispatch_table',1),(98,'2025_03_25_133919_create_dispatch_detail_table',1),(99,'2025_03_30_154014_add_dispatch_detail_id_to_product_serial_number_table',1),(100,'2025_04_03_151257_add_location_id_and_serial_numbers_dispatch_detail_table',1),(101,'2025_04_03_152358_remove_location_id_from_dispatch_table',1),(102,'2025_04_04_152113_add_payment_method_relation_to_sale_payment_table',1),(103,'2025_04_04_155829_update_amount_to_decimal_sale_payments_table',1),(104,'2025_04_04_160848_update_amount_to_decimal_from_sales_table',1),(105,'2025_04_07_183514_add_tax_id_to_dispatch_detail',1),(106,'2025_04_10_000000_downscale_sale_amounts',1),(107,'2025_04_11_163906_modify_transaction_table',1),(108,'2025_04_24_184159_add_price_to_product_conversion_table',1),(109,'2025_05_04_144041_add_price_to_product_bundles_table',1),(110,'2025_06_02_021018_add_setting_id_to_expense_tables',1),(111,'2025_06_14_175954_add_tax_columns_to_adjusted_products_table',1),(112,'2025_07_01_153916_create_expense_details_table',1),(113,'2025_07_02_173959_create_tag_tables',1),(114,'2025_07_21_151017_add_serial_number_ids_to_purchase_return_details_table',1),(115,'2025_07_28_181615_add_sale_prefix_column',1),(116,'2025_08_03_181507_add_serial_numbers_and_quantity_details',1),(117,'2025_08_06_174311_add_supplier_reference_no_to_purchase_table',1),(118,'2025_08_06_174543_add_sale_and_purchase_document_prefix_to_settings_table',1),(119,'2025_08_09_140912_add_is_pos_to_location_table',1),(120,'2025_08_19_183038_add_approval_and_return_type_to_purchase_returns',1),(121,'2025_08_19_183155_add_payment_method_id_to_purchase_return_payments',1),(122,'2025_08_19_183414_add_supplier_credits_for_purchase_returns',1),(123,'2025_08_19_183632_create_purchase_return_goods_table',1),(124,'2025_08_19_183857_standardize_money_columns_in_purchase_returns',1),(125,'2025_08_31_174825_create_product_price',1),(126,'2025_09_02_174017_create_import_batches',1),(127,'2025_09_02_174043_create_import_rows',1),(128,'2025_09_05_120000_add_return_flow_to_transfers_table',1),(129,'2025_09_05_120000_backfill_product_price_flags',1),(130,'2025_09_07_000001_add_location_and_setting_to_purchase_returns_table',1),(131,'2025_09_07_000002_add_settlement_tracking_to_purchase_returns_table',1),(132,'2025_09_10_000500_add_supplier_purchase_number_to_purchases_table',1),(133,'2025_09_10_120000_create_product_unit_conversion_prices_table',1),(134,'2025_09_23_070646_create_jobs_table',1),(135,'2025_09_23_100001_backfill_pending_purchase_returns',1),(136,'2025_09_23_120500_add_document_number_to_transfers_table',1),(137,'2025_09_30_120000_adjust_transfer_document_number_unique',1),(138,'2025_10_05_000001_create_cashier_cash_movements_table',1),(139,'2025_10_05_000001_remove_setting_id_from_shared_master_tables',1),(140,'2025_10_05_000001_update_price_columns_on_sale_bundle_items_table',1),(141,'2025_10_05_120000_enhance_sale_returns_with_settlement_structures',1),(142,'2025_10_10_000000_add_pos_flags_to_payment_methods_table',1),(143,'2025_11_05_000001_add_receiving_columns_to_sale_returns_table',1),(144,'2025_11_08_120000_create_sales_order_serial_tracking_table',1),(145,'2025_11_08_120001_add_serial_search_indexes',1),(146,'2025_11_08_120002_add_serial_number_ids_to_sale_details',1),(147,'2025_11_08_120003_create_global_sales_searches_table',1),(148,'2025_11_08_181647_create_global_sales_search_permission',1),(149,'2025_11_09_001508_create_global_purchase_and_sales_searches_table',1),(150,'2025_11_09_001528_add_search_indexes_for_global_search',1),(151,'2025_11_09_002049_create_global_purchase_and_sales_search_permission',1),(152,'2025_11_15_120000_create_setting_sale_locations_table',1),(153,'2025_12_01_000001_move_is_pos_to_setting_sale_locations',1),(154,'2025_12_01_083359_add_pos_document_prefix_to_settings_table',1),(155,'2025_12_01_130006_add_unique_constraints_across_entities',1),(156,'2025_12_20_000000_create_pos_sessions_table',1),(157,'2025_12_20_000100_add_pos_session_columns',1),(158,'2026_01_01_000001_add_position_to_setting_sale_locations',1),(159,'2026_03_10_000002_add_pos_monitoring_thresholds',1),(160,'2026_07_04_000001_create_pos_receipts_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (2,'App\\Models\\User',1);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `coa_id` bigint unsigned NOT NULL,
  `is_cash` tinyint(1) NOT NULL DEFAULT '0',
  `is_available_in_pos` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_methods_name_unique` (`name`),
  KEY `payment_methods_coa_id_foreign` (`coa_id`),
  CONSTRAINT `payment_methods_coa_id_foreign` FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_methods`
--

LOCK TABLES `payment_methods` WRITE;
/*!40000 ALTER TABLE `payment_methods` DISABLE KEYS */;
INSERT INTO `payment_methods` VALUES (1,'CASH',1,1,1,'2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `payment_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_terms`
--

DROP TABLE IF EXISTS `payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_terms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `longevity` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2917155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_terms`
--

LOCK TABLES `payment_terms` WRITE;
/*!40000 ALTER TABLE `payment_terms` DISABLE KEYS */;
INSERT INTO `payment_terms` VALUES (858231,'Net 30',30,NULL,NULL),(858232,'Cash on Delivery',0,NULL,NULL),(858233,'Net 15',15,NULL,NULL),(858234,'Net 60',60,NULL,NULL),(858235,'Custom',0,NULL,NULL),(873940,'Term 14 hari',14,NULL,NULL),(898556,'net 21',21,NULL,NULL),(1188378,'NET 7 HARI',7,NULL,NULL),(1726493,'45 HR',45,NULL,NULL),(2353345,'10 HARI',10,NULL,NULL),(2627421,'24 HR',24,NULL,NULL),(2917154,'28HR',28,NULL,NULL);
/*!40000 ALTER TABLE `payment_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'globalSalesSearch.access','web','2025-12-01 13:15:21','2025-12-01 13:15:21'),(3,'adjustments.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(4,'adjustments.approval','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(5,'adjustments.breakage.approval','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(6,'adjustments.breakage.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(7,'adjustments.breakage.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(8,'adjustments.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(9,'adjustments.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(10,'adjustments.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(11,'adjustments.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(12,'adjustments.reject','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(13,'barcodes.print','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(14,'brands.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(15,'brands.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(16,'brands.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(17,'brands.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(18,'brands.view','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(19,'businesses.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(20,'businesses.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(21,'businesses.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(22,'businesses.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(23,'businesses.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(24,'categories.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(25,'categories.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(26,'categories.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(27,'categories.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(28,'chartOfAccounts.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(29,'chartOfAccounts.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(30,'chartOfAccounts.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(31,'chartOfAccounts.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(32,'chartOfAccounts.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(33,'currencies.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(34,'currencies.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(35,'currencies.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(36,'currencies.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(37,'customers.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(38,'customers.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(39,'customers.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(40,'customers.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(41,'customers.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(42,'expenseCategories.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(43,'expenseCategories.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(44,'expenseCategories.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(45,'expenseCategories.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(46,'expenses.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(47,'expenses.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(48,'expenses.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(49,'expenses.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(50,'journals.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(51,'journals.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(52,'journals.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(53,'journals.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(54,'journals.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(55,'locations.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(56,'locations.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(57,'locations.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(58,'saleLocations.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(59,'saleLocations.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(60,'paymentMethods.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(61,'paymentMethods.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(62,'paymentMethods.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(63,'paymentMethods.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(64,'paymentTerms.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(65,'paymentTerms.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(66,'paymentTerms.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(67,'paymentTerms.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(68,'pos.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(69,'pos.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(70,'products.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(71,'products.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(72,'products.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(73,'products.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(74,'products.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(75,'products.bundle.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(76,'products.bundle.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(77,'products.bundle.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(78,'products.bundle.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(79,'profiles.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(80,'purchases.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(81,'purchases.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(82,'purchases.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(83,'purchases.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(84,'purchases.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(85,'purchases.receive','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(86,'purchases.approval','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(87,'purchases.view','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(88,'purchaseReceivings.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(89,'purchaseReports.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(90,'purchasePayments.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(91,'purchasePayments.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(92,'purchasePayments.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(93,'purchasePayments.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(94,'purchaseReturns.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(95,'purchaseReturns.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(96,'purchaseReturns.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(97,'purchaseReturns.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(98,'purchaseReturns.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(99,'purchaseReturnPayments.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(100,'purchaseReturnPayments.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(101,'purchaseReturnPayments.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(102,'purchaseReturnPayments.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(103,'purchaseReturnPayments.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(104,'reports.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(105,'settings.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(106,'settings.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(107,'stockTransfers.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(108,'stockTransfers.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(109,'stockTransfers.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(110,'stockTransfers.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(111,'stockTransfers.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(112,'stockTransfers.dispatch','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(113,'stockTransfers.receive','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(114,'stockTransfers.approval','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(115,'suppliers.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(116,'suppliers.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(117,'suppliers.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(118,'suppliers.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(119,'suppliers.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(120,'taxes.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(121,'taxes.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(122,'taxes.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(123,'taxes.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(124,'units.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(125,'units.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(126,'units.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(127,'units.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(128,'users.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(129,'users.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(130,'users.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(131,'users.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(132,'roles.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(133,'roles.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(134,'roles.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(135,'roles.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(136,'salePayments.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(137,'salePayments.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(138,'salePayments.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(139,'salePayments.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(140,'saleReturnPayments.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(141,'saleReturnPayments.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(142,'saleReturnPayments.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(143,'saleReturnPayments.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(144,'salePayments.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(145,'saleReturns.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(146,'saleReturns.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(147,'saleReturns.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(148,'saleReturns.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(149,'saleReturns.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(150,'saleReturns.approve','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(151,'saleReturns.receive','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(152,'sales.access','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(153,'sales.create','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(154,'sales.edit','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(155,'sales.delete','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(156,'sales.dispatch','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(157,'sales.show','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(158,'sales.approval','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(159,'show_notifications','web','2025-12-01 13:15:26','2025-12-01 13:15:26');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_receipts`
--

DROP TABLE IF EXISTS `pos_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `due_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `change_due` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unpaid',
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_breakdown` json DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `pos_session_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_receipts_receipt_number_unique` (`receipt_number`),
  KEY `pos_receipts_customer_id_foreign` (`customer_id`),
  KEY `pos_receipts_pos_session_id_foreign` (`pos_session_id`),
  CONSTRAINT `pos_receipts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_receipts_pos_session_id_foreign` FOREIGN KEY (`pos_session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_receipts`
--

LOCK TABLES `pos_receipts` WRITE;
/*!40000 ALTER TABLE `pos_receipts` DISABLE KEYS */;
INSERT INTO `pos_receipts` VALUES (1,'PR-2025-12-00001',1,'',58500.00,60000.00,0.00,1500.00,'PAID','CASH','[{\"amount\": 60000, \"method_id\": 1, \"method_name\": \"CASH\"}]',NULL,1,'2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `pos_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_sessions`
--

DROP TABLE IF EXISTS `pos_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `device_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cash_float` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expected_cash` decimal(15,2) DEFAULT NULL,
  `actual_cash` decimal(15,2) DEFAULT NULL,
  `discrepancy` decimal(15,2) DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT NULL,
  `paused_at` timestamp NULL DEFAULT NULL,
  `resumed_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pos_sessions_user_id_foreign` (`user_id`),
  KEY `pos_sessions_location_id_foreign` (`location_id`),
  KEY `pos_sessions_status_index` (`status`),
  KEY `pos_sessions_started_at_index` (`started_at`),
  CONSTRAINT `pos_sessions_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_sessions`
--

LOCK TABLES `pos_sessions` WRITE;
/*!40000 ALTER TABLE `pos_sessions` DISABLE KEYS */;
INSERT INTO `pos_sessions` VALUES (1,1,1,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',1000000.00,1000000.00,NULL,NULL,'active','2025-12-01 13:35:24',NULL,NULL,NULL,'2025-12-01 13:35:24','2025-12-01 13:35:24');
/*!40000 ALTER TABLE `pos_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_bundle_items`
--

DROP TABLE IF EXISTS `product_bundle_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_bundle_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_bundle_items_bundle_id_foreign` (`bundle_id`),
  KEY `product_bundle_items_product_id_foreign` (`product_id`),
  CONSTRAINT `product_bundle_items_bundle_id_foreign` FOREIGN KEY (`bundle_id`) REFERENCES `product_bundles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_bundle_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_bundle_items`
--

LOCK TABLES `product_bundle_items` WRITE;
/*!40000 ALTER TABLE `product_bundle_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_bundle_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_bundles`
--

DROP TABLE IF EXISTS `product_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_bundles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_product_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price` decimal(15,2) DEFAULT NULL,
  `active_from` date DEFAULT NULL,
  `active_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_bundles_parent_product_id_foreign` (`parent_product_id`),
  CONSTRAINT `product_bundles_parent_product_id_foreign` FOREIGN KEY (`parent_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_bundles`
--

LOCK TABLES `product_bundles` WRITE;
/*!40000 ALTER TABLE `product_bundles` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_bundles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_import_batches`
--

DROP TABLE IF EXISTS `product_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_import_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned NOT NULL,
  `source_csv_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_csv_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_sha256` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('queued','validating','processing','completed','failed','canceled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `processed_rows` int unsigned NOT NULL DEFAULT '0',
  `success_rows` int unsigned NOT NULL DEFAULT '0',
  `error_rows` int unsigned NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `undo_available_until` timestamp NULL DEFAULT NULL,
  `undone_at` timestamp NULL DEFAULT NULL,
  `undo_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_import_batches_undo_token_unique` (`undo_token`),
  KEY `product_import_batches_user_id_foreign` (`user_id`),
  KEY `product_import_batches_location_id_foreign` (`location_id`),
  KEY `product_import_batches_status_index` (`status`),
  KEY `product_import_batches_created_at_index` (`created_at`),
  CONSTRAINT `product_import_batches_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_import_batches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_import_batches`
--

LOCK TABLES `product_import_batches` WRITE;
/*!40000 ALTER TABLE `product_import_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_import_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_import_rows`
--

DROP TABLE IF EXISTS `product_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `row_number` int unsigned NOT NULL,
  `raw_json` json NOT NULL,
  `status` enum('skipped','error','imported') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `product_id` bigint unsigned DEFAULT NULL,
  `created_txn_id` bigint unsigned DEFAULT NULL,
  `created_stock_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_import_rows_batch_id_row_number_index` (`batch_id`,`row_number`),
  KEY `product_import_rows_status_index` (`status`),
  KEY `product_import_rows_product_id_index` (`product_id`),
  CONSTRAINT `product_import_rows_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `product_import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_import_rows`
--

LOCK TABLES `product_import_rows` WRITE;
/*!40000 ALTER TABLE `product_import_rows` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_import_rows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_prices`
--

DROP TABLE IF EXISTS `product_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `tier_1_price` decimal(10,2) DEFAULT NULL,
  `tier_2_price` decimal(10,2) DEFAULT NULL,
  `last_purchase_price` decimal(10,2) DEFAULT NULL,
  `average_purchase_price` decimal(10,2) DEFAULT NULL,
  `purchase_tax_id` bigint unsigned DEFAULT NULL,
  `sale_tax_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_prices_product_id_setting_id_unique` (`product_id`,`setting_id`),
  KEY `product_prices_purchase_tax_id_foreign` (`purchase_tax_id`),
  KEY `product_prices_sale_tax_id_foreign` (`sale_tax_id`),
  KEY `product_prices_product_id_index` (`product_id`),
  KEY `product_prices_setting_id_index` (`setting_id`),
  CONSTRAINT `product_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_prices_purchase_tax_id_foreign` FOREIGN KEY (`purchase_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_prices_sale_tax_id_foreign` FOREIGN KEY (`sale_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_prices_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_prices`
--

LOCK TABLES `product_prices` WRITE;
/*!40000 ALTER TABLE `product_prices` DISABLE KEYS */;
INSERT INTO `product_prices` VALUES (1,1,1,55000.00,54000.00,53000.00,50000.00,50000.00,NULL,NULL,'2025-12-01 13:26:14','2025-12-01 13:26:14'),(2,1,2,55000.00,54000.00,53000.00,50000.00,50000.00,NULL,NULL,'2025-12-01 13:26:14','2025-12-01 13:26:14'),(3,2,1,3500.00,3400.00,3300.00,3000.00,3000.00,NULL,NULL,'2025-12-01 13:28:15','2025-12-01 13:28:15'),(4,2,2,3500.00,3400.00,3300.00,3000.00,3000.00,NULL,NULL,'2025-12-01 13:28:15','2025-12-01 13:28:15');
/*!40000 ALTER TABLE `product_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_serial_numbers`
--

DROP TABLE IF EXISTS `product_serial_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_serial_numbers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `dispatch_detail_id` bigint unsigned DEFAULT NULL,
  `is_broken` tinyint(1) NOT NULL DEFAULT '0',
  `received_note_detail_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned NOT NULL,
  `serial_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_serial_numbers_serial_number_unique` (`serial_number`),
  KEY `product_serial_numbers_product_id_foreign` (`product_id`),
  KEY `product_serial_numbers_tax_id_foreign` (`tax_id`),
  KEY `product_serial_numbers_received_note_detail_id_foreign` (`received_note_detail_id`),
  KEY `product_serial_numbers_dispatch_detail_id_foreign` (`dispatch_detail_id`),
  KEY `product_serial_numbers_serial_number_index` (`serial_number`),
  KEY `product_serial_numbers_location_id_index` (`location_id`),
  CONSTRAINT `product_serial_numbers_dispatch_detail_id_foreign` FOREIGN KEY (`dispatch_detail_id`) REFERENCES `dispatch_details` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_serial_numbers_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_serial_numbers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_serial_numbers_received_note_detail_id_foreign` FOREIGN KEY (`received_note_detail_id`) REFERENCES `received_note_details` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_serial_numbers_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_serial_numbers`
--

LOCK TABLES `product_serial_numbers` WRITE;
/*!40000 ALTER TABLE `product_serial_numbers` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_serial_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_stocks`
--

DROP TABLE IF EXISTS `product_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL,
  `quantity_non_tax` int NOT NULL,
  `quantity_tax` int NOT NULL,
  `broken_quantity_non_tax` int NOT NULL,
  `broken_quantity_tax` int NOT NULL,
  `broken_quantity` int NOT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_stocks_product_id_foreign` (`product_id`),
  KEY `product_stocks_location_id_foreign` (`location_id`),
  KEY `product_stocks_tax_id_foreign` (`tax_id`),
  CONSTRAINT `product_stocks_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_stocks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_stocks_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_stocks`
--

LOCK TABLES `product_stocks` WRITE;
/*!40000 ALTER TABLE `product_stocks` DISABLE KEYS */;
INSERT INTO `product_stocks` VALUES (1,1,1,99,99,0,0,0,0,NULL,'2025-12-01 13:32:02','2025-12-01 13:37:21'),(2,2,2,999,999,0,0,0,0,NULL,'2025-12-01 13:33:25','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `product_stocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_unit_conversion_prices`
--

DROP TABLE IF EXISTS `product_unit_conversion_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_unit_conversion_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_unit_conversion_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversion_setting_unique` (`product_unit_conversion_id`,`setting_id`),
  KEY `conv_price_setting_fk` (`setting_id`),
  CONSTRAINT `conv_price_conversion_fk` FOREIGN KEY (`product_unit_conversion_id`) REFERENCES `product_unit_conversions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conv_price_setting_fk` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_unit_conversion_prices`
--

LOCK TABLES `product_unit_conversion_prices` WRITE;
/*!40000 ALTER TABLE `product_unit_conversion_prices` DISABLE KEYS */;
INSERT INTO `product_unit_conversion_prices` VALUES (1,1,1,270000.00,'2025-12-01 13:26:14','2025-12-01 13:26:14'),(2,1,2,270000.00,'2025-12-01 13:26:14','2025-12-01 13:26:14'),(3,2,1,136000.00,'2025-12-01 13:28:15','2025-12-01 13:28:15'),(4,2,2,136000.00,'2025-12-01 13:28:15','2025-12-01 13:28:15');
/*!40000 ALTER TABLE `product_unit_conversion_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_unit_conversions`
--

DROP TABLE IF EXISTS `product_unit_conversions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_unit_conversions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `base_unit_id` bigint unsigned NOT NULL,
  `conversion_factor` decimal(10,2) NOT NULL,
  `barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_unit_conversions_product_id_foreign` (`product_id`),
  KEY `product_unit_conversions_unit_id_foreign` (`unit_id`),
  KEY `product_unit_conversions_base_unit_id_foreign` (`base_unit_id`),
  CONSTRAINT `product_unit_conversions_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_unit_conversions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_unit_conversions_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_unit_conversions`
--

LOCK TABLES `product_unit_conversions` WRITE;
/*!40000 ALTER TABLE `product_unit_conversions` DISABLE KEYS */;
INSERT INTO `product_unit_conversions` VALUES (1,1,2,3,5.00,NULL,'2025-12-01 13:26:14','2025-12-01 13:26:14'),(2,2,2,1,40.00,NULL,'2025-12-01 13:28:15','2025-12-01 13:28:15');
/*!40000 ALTER TABLE `product_unit_conversions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned DEFAULT NULL,
  `brand_id` int unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_barcode_symbology` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_quantity` int NOT NULL,
  `serial_number_required` tinyint(1) NOT NULL DEFAULT '0',
  `broken_quantity` int unsigned NOT NULL DEFAULT '0',
  `product_cost` int NOT NULL,
  `product_price` int NOT NULL,
  `barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profit_percentage` decimal(5,2) DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `base_unit_id` bigint unsigned DEFAULT NULL,
  `product_unit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_stock_alert` int NOT NULL,
  `is_purchased` tinyint(1) NOT NULL DEFAULT '0',
  `purchase_price` int DEFAULT NULL,
  `purchase_tax` int DEFAULT NULL,
  `is_sold` tinyint(1) NOT NULL DEFAULT '0',
  `sale_price` decimal(10,2) DEFAULT '0.00',
  `tier_1_price` decimal(10,2) DEFAULT '0.00',
  `tier_2_price` decimal(10,2) DEFAULT '0.00',
  `sale_tax` int DEFAULT NULL,
  `last_purchase_price` decimal(10,2) DEFAULT NULL,
  `average_purchase_price` decimal(10,2) DEFAULT NULL,
  `product_order_tax` int DEFAULT NULL,
  `product_tax_type` tinyint DEFAULT NULL,
  `stock_managed` tinyint(1) NOT NULL DEFAULT '0',
  `product_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `purchase_tax_id` bigint unsigned DEFAULT NULL,
  `sale_tax_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_product_code_unique` (`product_code`),
  KEY `products_unit_id_foreign` (`unit_id`),
  KEY `products_setting_id_foreign` (`setting_id`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_brand_id_foreign` (`brand_id`),
  KEY `products_base_unit_id_foreign` (`base_unit_id`),
  KEY `products_purchase_tax_id_foreign` (`purchase_tax_id`),
  KEY `products_sale_tax_id_foreign` (`sale_tax_id`),
  CONSTRAINT `products_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_purchase_tax_id_foreign` FOREIGN KEY (`purchase_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_sale_tax_id_foreign` FOREIGN KEY (`sale_tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `products_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,1,1,'KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET','SKU-000001',NULL,99,0,0,0,0,NULL,0.00,NULL,3,NULL,0,1,0,NULL,1,0.00,54000.00,53000.00,NULL,50000.00,50000.00,0,0,1,NULL,'2025-12-01 13:26:14','2025-12-01 13:37:21',NULL,NULL),(2,1,2,NULL,'INDOMIE AYAM BAWANG','SKU-000002',NULL,999,0,0,0,0,NULL,0.00,NULL,1,NULL,0,1,0,NULL,1,0.00,3400.00,3300.00,NULL,3000.00,3000.00,0,0,1,NULL,'2025-12-01 13:28:15','2025-12-01 13:37:21',NULL,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_details`
--

DROP TABLE IF EXISTS `purchase_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `sub_total` decimal(15,2) NOT NULL,
  `product_discount_amount` decimal(15,2) NOT NULL,
  `product_discount_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `product_tax_amount` decimal(15,2) NOT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_details_purchase_id_foreign` (`purchase_id`),
  KEY `purchase_details_product_id_foreign` (`product_id`),
  KEY `purchase_details_tax_id_foreign` (`tax_id`),
  CONSTRAINT `purchase_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_details_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_details`
--

LOCK TABLES `purchase_details` WRITE;
/*!40000 ALTER TABLE `purchase_details` DISABLE KEYS */;
INSERT INTO `purchase_details` VALUES (1,1,1,'KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET','SKU-000001',100,50000.00,50000.00,5000000.00,0.00,'FIXED',0.00,NULL,'2025-12-01 13:31:14','2025-12-01 13:31:14'),(2,2,2,'INDOMIE AYAM BAWANG','SKU-000002',1000,3000.00,3000.00,3000000.00,0.00,'FIXED',0.00,NULL,'2025-12-01 13:32:59','2025-12-01 13:32:59');
/*!40000 ALTER TABLE `purchase_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_payment_credit_applications`
--

DROP TABLE IF EXISTS `purchase_payment_credit_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_payment_credit_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_payment_id` bigint unsigned NOT NULL,
  `supplier_credit_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_purchase_payment_credit` (`purchase_payment_id`,`supplier_credit_id`),
  KEY `purchase_payment_credit_applications_supplier_credit_id_foreign` (`supplier_credit_id`),
  CONSTRAINT `purchase_payment_credit_applications_purchase_payment_id_foreign` FOREIGN KEY (`purchase_payment_id`) REFERENCES `purchase_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_payment_credit_applications_supplier_credit_id_foreign` FOREIGN KEY (`supplier_credit_id`) REFERENCES `supplier_credits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_payment_credit_applications`
--

LOCK TABLES `purchase_payment_credit_applications` WRITE;
/*!40000 ALTER TABLE `purchase_payment_credit_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_payment_credit_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_payments`
--

DROP TABLE IF EXISTS `purchase_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_method_id` bigint unsigned DEFAULT NULL,
  `purchase_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_payments_purchase_id_foreign` (`purchase_id`),
  KEY `purchase_payments_payment_method_id_foreign` (`payment_method_id`),
  CONSTRAINT `purchase_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_payments_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_payments`
--

LOCK TABLES `purchase_payments` WRITE;
/*!40000 ALTER TABLE `purchase_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_return_details`
--

DROP TABLE IF EXISTS `purchase_return_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_return_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_id` bigint unsigned NOT NULL,
  `po_id` bigint unsigned DEFAULT NULL COMMENT 'Reference to purchase order',
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `sub_total` decimal(15,2) NOT NULL,
  `product_discount_amount` decimal(15,2) NOT NULL,
  `product_discount_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `product_tax_amount` decimal(15,2) NOT NULL,
  `serial_number_ids` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_return_details_purchase_return_id_foreign` (`purchase_return_id`),
  KEY `purchase_return_details_product_id_foreign` (`product_id`),
  KEY `purchase_return_details_po_id_foreign` (`po_id`),
  CONSTRAINT `purchase_return_details_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_return_details_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_return_details`
--

LOCK TABLES `purchase_return_details` WRITE;
/*!40000 ALTER TABLE `purchase_return_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_return_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_return_goods`
--

DROP TABLE IF EXISTS `purchase_return_goods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_return_goods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit_value` decimal(15,2) DEFAULT NULL,
  `sub_total` decimal(15,2) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_return_goods_purchase_return_id_index` (`purchase_return_id`),
  KEY `purchase_return_goods_product_id_index` (`product_id`),
  KEY `prg_return_received_idx` (`purchase_return_id`,`received_at`),
  CONSTRAINT `purchase_return_goods_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_return_goods_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_return_goods`
--

LOCK TABLES `purchase_return_goods` WRITE;
/*!40000 ALTER TABLE `purchase_return_goods` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_return_goods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_return_payments`
--

DROP TABLE IF EXISTS `purchase_return_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_return_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_id` bigint unsigned NOT NULL,
  `payment_method_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_return_payments_purchase_return_id_foreign` (`purchase_return_id`),
  KEY `purchase_return_payments_payment_method_id_index` (`payment_method_id`),
  CONSTRAINT `purchase_return_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_return_payments_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_return_payments`
--

LOCK TABLES `purchase_return_payments` WRITE;
/*!40000 ALTER TABLE `purchase_return_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_return_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_returns`
--

DROP TABLE IF EXISTS `purchase_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` bigint unsigned DEFAULT NULL,
  `setting_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `supplier_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_percentage` int NOT NULL DEFAULT '0',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` int NOT NULL DEFAULT '0',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `approval_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `return_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `settled_by` bigint unsigned DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_proof_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_returns_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_returns_approved_by_foreign` (`approved_by`),
  KEY `purchase_returns_rejected_by_foreign` (`rejected_by`),
  KEY `purchase_returns_approval_status_index` (`approval_status`),
  KEY `purchase_returns_return_type_index` (`return_type`),
  KEY `purchase_returns_setting_id_foreign` (`setting_id`),
  KEY `purchase_returns_location_id_foreign` (`location_id`),
  KEY `purchase_returns_settled_by_foreign` (`settled_by`),
  CONSTRAINT `purchase_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_returns`
--

LOCK TABLES `purchase_returns` WRITE;
/*!40000 ALTER TABLE `purchase_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `due_date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` bigint unsigned DEFAULT NULL,
  `supplier_reference_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_purchase_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `is_tax_included` tinyint(1) NOT NULL DEFAULT '0',
  `tax_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `payment_term_id` bigint unsigned DEFAULT NULL,
  `setting_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchases_setting_reference_unique` (`setting_id`,`reference`),
  KEY `purchases_supplier_id_foreign` (`supplier_id`),
  KEY `purchases_tax_id_foreign` (`tax_id`),
  KEY `purchases_payment_term_id_foreign` (`payment_term_id`),
  CONSTRAINT `purchases_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchases`
--

LOCK TABLES `purchases` WRITE;
/*!40000 ALTER TABLE `purchases` DISABLE KEYS */;
INSERT INTO `purchases` VALUES (1,'2025-12-01','2026-01-30','TS-PR-2025-12-00001',1,NULL,NULL,NULL,0,0.00,0.00,0.00,0.00,0.00,5000000.00,0.00,5000000.00,'RECEIVED','UNPAID','',NULL,858234,1,'2025-12-01 13:31:14','2025-12-01 13:32:02'),(2,'2025-12-01','2026-01-30','TI-BL-2025-12-00002',1,NULL,NULL,NULL,0,0.00,0.00,0.00,0.00,0.00,3000000.00,0.00,3000000.00,'RECEIVED','UNPAID','',NULL,858234,2,'2025-12-01 13:32:59','2025-12-01 13:33:25');
/*!40000 ALTER TABLE `purchases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotation_details`
--

DROP TABLE IF EXISTS `quotation_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` int NOT NULL,
  `unit_price` int NOT NULL,
  `sub_total` int NOT NULL,
  `product_discount_amount` int NOT NULL,
  `product_discount_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `product_tax_amount` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotation_details_quotation_id_foreign` (`quotation_id`),
  KEY `quotation_details_product_id_foreign` (`product_id`),
  CONSTRAINT `quotation_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotation_details_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotation_details`
--

LOCK TABLES `quotation_details` WRITE;
/*!40000 ALTER TABLE `quotation_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotation_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotations`
--

DROP TABLE IF EXISTS `quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_percentage` int NOT NULL DEFAULT '0',
  `tax_amount` int NOT NULL DEFAULT '0',
  `discount_percentage` int NOT NULL DEFAULT '0',
  `discount_amount` int NOT NULL DEFAULT '0',
  `shipping_amount` int NOT NULL DEFAULT '0',
  `total_amount` int NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quotations_reference_unique` (`reference`),
  KEY `quotations_customer_id_foreign` (`customer_id`),
  CONSTRAINT `quotations_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotations`
--

LOCK TABLES `quotations` WRITE;
/*!40000 ALTER TABLE `quotations` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `received_note_details`
--

DROP TABLE IF EXISTS `received_note_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `received_note_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `received_note_id` bigint unsigned NOT NULL,
  `po_detail_id` bigint unsigned NOT NULL,
  `quantity_received` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `received_note_details_received_note_id_foreign` (`received_note_id`),
  KEY `received_note_details_po_detail_id_foreign` (`po_detail_id`),
  CONSTRAINT `received_note_details_po_detail_id_foreign` FOREIGN KEY (`po_detail_id`) REFERENCES `purchase_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `received_note_details_received_note_id_foreign` FOREIGN KEY (`received_note_id`) REFERENCES `received_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `received_note_details`
--

LOCK TABLES `received_note_details` WRITE;
/*!40000 ALTER TABLE `received_note_details` DISABLE KEYS */;
INSERT INTO `received_note_details` VALUES (1,1,1,100,'2025-12-01 13:32:02','2025-12-01 13:32:02'),(2,2,2,1000,'2025-12-01 13:33:25','2025-12-01 13:33:25');
/*!40000 ALTER TABLE `received_note_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `received_notes`
--

DROP TABLE IF EXISTS `received_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `received_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint unsigned NOT NULL,
  `external_delivery_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `internal_invoice_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `received_notes_po_external_unique` (`po_id`,`external_delivery_number`),
  CONSTRAINT `received_notes_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `received_notes`
--

LOCK TABLES `received_notes` WRITE;
/*!40000 ALTER TABLE `received_notes` DISABLE KEYS */;
INSERT INTO `received_notes` VALUES (1,1,'',NULL,'2025-12-01','2025-12-01 13:32:02','2025-12-01 13:32:02'),(2,2,'',NULL,'2025-12-01','2025-12-01 13:33:25','2025-12-01 13:33:25');
/*!40000 ALTER TABLE `received_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES (1,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),(18,1),(19,1),(20,1),(21,1),(22,1),(23,1),(24,1),(25,1),(26,1),(27,1),(28,1),(29,1),(30,1),(31,1),(32,1),(33,1),(34,1),(35,1),(36,1),(37,1),(38,1),(39,1),(40,1),(41,1),(42,1),(43,1),(44,1),(45,1),(46,1),(47,1),(48,1),(49,1),(50,1),(51,1),(52,1),(53,1),(54,1),(55,1),(56,1),(57,1),(58,1),(59,1),(60,1),(61,1),(62,1),(63,1),(64,1),(65,1),(66,1),(67,1),(68,1),(69,1),(70,1),(71,1),(72,1),(73,1),(74,1),(75,1),(76,1),(77,1),(78,1),(79,1),(80,1),(81,1),(82,1),(83,1),(84,1),(85,1),(86,1),(87,1),(88,1),(89,1),(90,1),(91,1),(92,1),(93,1),(94,1),(95,1),(96,1),(97,1),(98,1),(99,1),(100,1),(101,1),(102,1),(103,1),(104,1),(105,1),(106,1),(107,1),(108,1),(109,1),(110,1),(111,1),(112,1),(113,1),(114,1),(115,1),(116,1),(117,1),(118,1),(119,1),(120,1),(121,1),(122,1),(123,1),(124,1),(125,1),(126,1),(127,1),(128,1),(129,1),(130,1),(131,1),(132,1),(133,1),(134,1),(135,1),(136,1),(137,1),(138,1),(139,1),(140,1),(141,1),(142,1),(143,1),(144,1),(145,1),(146,1),(147,1),(148,1),(149,1),(150,1),(151,1),(152,1),(153,1),(154,1),(155,1),(156,1),(157,1),(158,1),(159,1);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin','web','2025-12-01 13:15:26','2025-12-01 13:15:26'),(2,'Super Admin','web','2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_bundle_items`
--

DROP TABLE IF EXISTS `sale_bundle_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_bundle_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_detail_id` bigint unsigned NOT NULL,
  `sale_id` bigint unsigned NOT NULL,
  `bundle_id` bigint unsigned NOT NULL,
  `bundle_item_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `quantity` int NOT NULL,
  `sub_total` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_bundle_items_sale_detail_id_foreign` (`sale_detail_id`),
  KEY `sale_bundle_items_sale_id_foreign` (`sale_id`),
  KEY `sale_bundle_items_product_id_foreign` (`product_id`),
  CONSTRAINT `sale_bundle_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_bundle_items_sale_detail_id_foreign` FOREIGN KEY (`sale_detail_id`) REFERENCES `sale_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_bundle_items_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_bundle_items`
--

LOCK TABLES `sale_bundle_items` WRITE;
/*!40000 ALTER TABLE `sale_bundle_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_bundle_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_details`
--

DROP TABLE IF EXISTS `sale_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `sub_total` decimal(15,2) NOT NULL,
  `product_discount_amount` decimal(15,2) NOT NULL,
  `product_discount_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `product_tax_amount` decimal(15,2) NOT NULL,
  `serial_number_ids` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_details_tax_id_foreign` (`tax_id`),
  KEY `sale_details_sale_id_index` (`sale_id`),
  KEY `sale_details_product_id_index` (`product_id`),
  CONSTRAINT `sale_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_details_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_details`
--

LOCK TABLES `sale_details` WRITE;
/*!40000 ALTER TABLE `sale_details` DISABLE KEYS */;
INSERT INTO `sale_details` VALUES (1,1,1,NULL,'KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET','SKU-000001',1,55000.00,55000.00,55000.00,0.00,'FIXED',0.00,NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21'),(2,1,2,NULL,'INDOMIE AYAM BAWANG','SKU-000002',1,3500.00,3500.00,3500.00,0.00,'FIXED',0.00,NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `sale_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_payment_credit_applications`
--

DROP TABLE IF EXISTS `sale_payment_credit_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_payment_credit_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_payment_id` bigint unsigned NOT NULL,
  `customer_credit_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sale_payment_credit` (`sale_payment_id`,`customer_credit_id`),
  KEY `sale_payment_credit_applications_customer_credit_id_foreign` (`customer_credit_id`),
  CONSTRAINT `sale_payment_credit_applications_customer_credit_id_foreign` FOREIGN KEY (`customer_credit_id`) REFERENCES `customer_credits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_payment_credit_applications_sale_payment_id_foreign` FOREIGN KEY (`sale_payment_id`) REFERENCES `sale_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_payment_credit_applications`
--

LOCK TABLES `sale_payment_credit_applications` WRITE;
/*!40000 ALTER TABLE `sale_payment_credit_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_payment_credit_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_payments`
--

DROP TABLE IF EXISTS `sale_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_method_id` bigint unsigned DEFAULT NULL,
  `sale_id` bigint unsigned NOT NULL,
  `pos_session_id` bigint unsigned DEFAULT NULL,
  `pos_receipt_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_payments_sale_id_foreign` (`sale_id`),
  KEY `sale_payments_payment_method_id_foreign` (`payment_method_id`),
  KEY `sale_payments_pos_session_id_foreign` (`pos_session_id`),
  KEY `sale_payments_pos_receipt_id_foreign` (`pos_receipt_id`),
  CONSTRAINT `sale_payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_payments_pos_receipt_id_foreign` FOREIGN KEY (`pos_receipt_id`) REFERENCES `pos_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_payments_pos_session_id_foreign` FOREIGN KEY (`pos_session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_payments_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_payments`
--

LOCK TABLES `sale_payments` WRITE;
/*!40000 ALTER TABLE `sale_payments` DISABLE KEYS */;
INSERT INTO `sale_payments` VALUES (1,1,1,1,1,58500.00,'2025-12-01','INV/TS-SL-2025-12-00001','CASH',NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `sale_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_return_details`
--

DROP TABLE IF EXISTS `sale_return_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_return_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_return_id` bigint unsigned NOT NULL,
  `sale_detail_id` bigint unsigned DEFAULT NULL,
  `dispatch_detail_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `sub_total` decimal(15,2) NOT NULL,
  `product_discount_amount` decimal(15,2) NOT NULL,
  `product_discount_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `product_tax_amount` decimal(15,2) NOT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `serial_number_ids` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_return_details_sale_return_id_foreign` (`sale_return_id`),
  KEY `sale_return_details_product_id_foreign` (`product_id`),
  KEY `sale_return_details_sale_detail_id_foreign` (`sale_detail_id`),
  KEY `sale_return_details_dispatch_detail_id_foreign` (`dispatch_detail_id`),
  KEY `sale_return_details_location_id_foreign` (`location_id`),
  KEY `sale_return_details_tax_id_foreign` (`tax_id`),
  CONSTRAINT `sale_return_details_dispatch_detail_id_foreign` FOREIGN KEY (`dispatch_detail_id`) REFERENCES `dispatch_details` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_return_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_return_details_sale_detail_id_foreign` FOREIGN KEY (`sale_detail_id`) REFERENCES `sale_details` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_return_details_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_return_details_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_return_details`
--

LOCK TABLES `sale_return_details` WRITE;
/*!40000 ALTER TABLE `sale_return_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_return_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_return_goods`
--

DROP TABLE IF EXISTS `sale_return_goods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_return_goods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_return_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit_value` decimal(15,2) DEFAULT NULL,
  `sub_total` decimal(15,2) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_return_goods_sale_return_id_index` (`sale_return_id`),
  KEY `sale_return_goods_product_id_index` (`product_id`),
  KEY `srg_return_received_idx` (`sale_return_id`,`received_at`),
  CONSTRAINT `sale_return_goods_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_return_goods_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_return_goods`
--

LOCK TABLES `sale_return_goods` WRITE;
/*!40000 ALTER TABLE `sale_return_goods` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_return_goods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_return_payments`
--

DROP TABLE IF EXISTS `sale_return_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_return_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_return_id` bigint unsigned NOT NULL,
  `amount` int NOT NULL,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_return_payments_sale_return_id_foreign` (`sale_return_id`),
  CONSTRAINT `sale_return_payments_sale_return_id_foreign` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_return_payments`
--

LOCK TABLES `sale_return_payments` WRITE;
/*!40000 ALTER TABLE `sale_return_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_return_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_returns`
--

DROP TABLE IF EXISTS `sale_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sale_id` bigint unsigned DEFAULT NULL,
  `sale_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `setting_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_percentage` int NOT NULL DEFAULT '0',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` int NOT NULL DEFAULT '0',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `approval_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `return_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `settled_at` timestamp NULL DEFAULT NULL,
  `settled_by` bigint unsigned DEFAULT NULL,
  `received_by` bigint unsigned DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_proof_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sale_returns_setting_reference_unique` (`setting_id`,`reference`),
  KEY `sale_returns_customer_id_foreign` (`customer_id`),
  KEY `sale_returns_sale_id_foreign` (`sale_id`),
  KEY `sale_returns_location_id_foreign` (`location_id`),
  KEY `sale_returns_approval_status_index` (`approval_status`),
  KEY `sale_returns_return_type_index` (`return_type`),
  KEY `sale_returns_approved_by_foreign` (`approved_by`),
  KEY `sale_returns_rejected_by_foreign` (`rejected_by`),
  KEY `sale_returns_settled_by_foreign` (`settled_by`),
  KEY `sale_returns_received_by_foreign` (`received_by`),
  CONSTRAINT `sale_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_returns_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_returns`
--

LOCK TABLES `sale_returns` WRITE;
/*!40000 ALTER TABLE `sale_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `is_tax_included` tinyint(1) NOT NULL DEFAULT '0',
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `payment_term_id` bigint unsigned DEFAULT NULL,
  `tax_id` bigint unsigned DEFAULT NULL,
  `setting_id` bigint unsigned DEFAULT NULL,
  `pos_session_id` bigint unsigned DEFAULT NULL,
  `pos_receipt_id` bigint unsigned DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_percentage` int NOT NULL DEFAULT '0',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` int NOT NULL DEFAULT '0',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_setting_reference_unique` (`setting_id`,`reference`),
  KEY `sales_customer_id_foreign` (`customer_id`),
  KEY `sales_payment_term_id_foreign` (`payment_term_id`),
  KEY `sales_tax_id_foreign` (`tax_id`),
  KEY `sales_reference_status_created_at_index` (`reference`,`status`,`created_at`),
  KEY `sales_status_index` (`status`),
  KEY `sales_created_at_index` (`created_at`),
  KEY `sales_setting_id_index` (`setting_id`),
  KEY `sales_setting_id_reference_index` (`setting_id`,`reference`),
  KEY `sales_setting_id_customer_id_index` (`setting_id`,`customer_id`),
  KEY `sales_setting_id_created_at_index` (`setting_id`,`created_at`),
  KEY `sales_pos_session_id_foreign` (`pos_session_id`),
  KEY `sales_pos_receipt_id_foreign` (`pos_receipt_id`),
  CONSTRAINT `sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_pos_receipt_id_foreign` FOREIGN KEY (`pos_receipt_id`) REFERENCES `pos_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_pos_session_id_foreign` FOREIGN KEY (`pos_session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'2025-12-01',NULL,0,'TS-SL-2025-12-00001',1,NULL,NULL,1,1,1,'',0,0.00,0,0.00,0.00,58500.00,58500.00,0.00,'COMPLETED','PAID','CASH',NULL,'2025-12-01 13:37:21','2025-12-01 13:37:21');
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_order_serial_tracking`
--

DROP TABLE IF EXISTS `sales_order_serial_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_order_serial_tracking` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint unsigned NOT NULL,
  `product_serial_number_id` bigint unsigned NOT NULL,
  `quantity_allocated` int NOT NULL DEFAULT '1',
  `dispatch_date` datetime DEFAULT NULL,
  `return_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sale_serial` (`sale_id`,`product_serial_number_id`),
  KEY `sales_order_serial_tracking_sale_id_index` (`sale_id`),
  KEY `sales_order_serial_tracking_product_serial_number_id_index` (`product_serial_number_id`),
  KEY `sales_order_serial_tracking_created_at_index` (`created_at`),
  CONSTRAINT `sales_order_serial_tracking_product_serial_number_id_foreign` FOREIGN KEY (`product_serial_number_id`) REFERENCES `product_serial_numbers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_order_serial_tracking_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_order_serial_tracking`
--

LOCK TABLES `sales_order_serial_tracking` WRITE;
/*!40000 ALTER TABLE `sales_order_serial_tracking` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_order_serial_tracking` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting_sale_locations`
--

DROP TABLE IF EXISTS `setting_sale_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting_sale_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `is_pos` tinyint(1) DEFAULT '0' COMMENT 'Flag to mark if this location is used for POS',
  `position` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_sale_locations_location_id_unique` (`location_id`),
  KEY `setting_sale_locations_setting_id_index` (`setting_id`),
  CONSTRAINT `setting_sale_locations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `setting_sale_locations_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting_sale_locations`
--

LOCK TABLES `setting_sale_locations` WRITE;
/*!40000 ALTER TABLE `setting_sale_locations` DISABLE KEYS */;
INSERT INTO `setting_sale_locations` VALUES (1,1,1,1,1,'2025-12-01 13:17:49','2025-12-01 13:18:30'),(2,1,2,1,2,'2025-12-01 13:18:09','2025-12-01 13:18:43');
/*!40000 ALTER TABLE `setting_sale_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_currency_id` int NOT NULL,
  `default_currency_position` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `footer_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `document_prefix` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_prefix_document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sale_prefix_document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pos_idle_threshold_minutes` int unsigned NOT NULL DEFAULT '30',
  `pos_default_cash_threshold` decimal(15,2) NOT NULL DEFAULT '0.00',
  `pos_document_prefix` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_company_name_unique` (`company_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'CV TIGA COMPUTER','contactus@tiga-computer.com','012345678901',NULL,1,'PREFIX','notification@tiga-computer.com','CV TIGA COMPUTER © 2021','BIMA, NTB','2025-12-01 13:15:27','2025-12-01 13:15:27','TS','PR','SL',30,0.00,NULL),(2,'TOP IT','topit@mail.com','081249003893',NULL,1,'PREFIX','topit@mail.com','TOP IT © 2025','JALAN SWATANTRA V','2025-12-01 13:17:16','2025-12-01 13:17:16','TI','BL','JL',30,0.00,'KS');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_credits`
--

DROP TABLE IF EXISTS `supplier_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_credits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `purchase_return_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `remaining_amount` decimal(15,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_credits_purchase_return_id_unique` (`purchase_return_id`),
  KEY `supplier_credits_supplier_id_status_index` (`supplier_id`,`status`),
  CONSTRAINT `supplier_credits_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_credits_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_credits`
--

LOCK TABLES `supplier_credits` WRITE;
/*!40000 ALTER TABLE `supplier_credits` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_credits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `npwp` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address` text COLLATE utf8mb4_unicode_ci,
  `shipping_address` text COLLATE utf8mb4_unicode_ci,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_branch` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_holder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `payment_term_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_setting_name_unique` (`setting_id`,`supplier_name`),
  UNIQUE KEY `suppliers_setting_phone_unique` (`setting_id`,`supplier_phone`),
  UNIQUE KEY `suppliers_setting_email_unique` (`setting_id`,`supplier_email`),
  UNIQUE KEY `suppliers_setting_identity_unique` (`setting_id`,`identity_number`),
  KEY `suppliers_payment_term_id_foreign` (`payment_term_id`),
  CONSTRAINT `suppliers_payment_term_id_foreign` FOREIGN KEY (`payment_term_id`) REFERENCES `payment_terms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `suppliers_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'PT SIDU TJAHAJA ASIA','','','','','','2025-12-01 13:28:54','2025-12-01 13:28:54','SALES SIDU','','',NULL,'','','','','','','',1,858234);
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `taggables`
--

DROP TABLE IF EXISTS `taggables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `taggables` (
  `tag_id` bigint unsigned NOT NULL,
  `taggable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `taggable_id` bigint unsigned NOT NULL,
  UNIQUE KEY `taggables_tag_id_taggable_id_taggable_type_unique` (`tag_id`,`taggable_id`,`taggable_type`),
  KEY `taggables_taggable_type_taggable_id_index` (`taggable_type`,`taggable_id`),
  CONSTRAINT `taggables_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `taggables`
--

LOCK TABLES `taggables` WRITE;
/*!40000 ALTER TABLE `taggables` DISABLE KEYS */;
/*!40000 ALTER TABLE `taggables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` json NOT NULL,
  `slug` json NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_column` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `taxes`
--

DROP TABLE IF EXISTS `taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `taxes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(8,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `taxes_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `taxes`
--

LOCK TABLES `taxes` WRITE;
/*!40000 ALTER TABLE `taxes` DISABLE KEYS */;
INSERT INTO `taxes` VALUES (1,'PPN 11%',11.00,'2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `taxes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL COMMENT 'Quantity involved in the transaction',
  `current_quantity` int NOT NULL COMMENT 'Product quantity after the transaction',
  `broken_quantity` int DEFAULT NULL COMMENT 'Broken quantity if applicable',
  `location_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Reason for the transaction',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of transaction',
  `previous_quantity` int NOT NULL,
  `after_quantity` int NOT NULL,
  `previous_quantity_at_location` int NOT NULL,
  `after_quantity_at_location` int NOT NULL,
  `quantity_non_tax` int NOT NULL,
  `quantity_tax` int NOT NULL,
  `broken_quantity_non_tax` int NOT NULL,
  `broken_quantity_tax` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transactions_product_id_foreign` (`product_id`),
  KEY `transactions_setting_id_foreign` (`setting_id`),
  KEY `transactions_location_id_foreign` (`location_id`),
  KEY `transactions_user_id_foreign` (`user_id`),
  CONSTRAINT `transactions_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,1,1,100,100,0,1,1,'RECEIVED FROM PURCHASE ORDER #TS-PR-2025-12-00001','2025-12-01 13:32:02','2025-12-01 13:32:02','BUY',0,100,0,100,100,0,0,0),(2,2,2,1000,1000,0,2,1,'RECEIVED FROM PURCHASE ORDER #TI-BL-2025-12-00002','2025-12-01 13:33:25','2025-12-01 13:33:25','BUY',0,1000,0,1000,1000,0,0,0);
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfer_products`
--

DROP TABLE IF EXISTS `transfer_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfer_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL,
  `serial_numbers` json DEFAULT NULL,
  `quantity_tax` int unsigned NOT NULL DEFAULT '0',
  `quantity_non_tax` int unsigned NOT NULL DEFAULT '0',
  `quantity_broken_tax` int unsigned NOT NULL DEFAULT '0',
  `quantity_broken_non_tax` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `dispatched_by` bigint unsigned DEFAULT NULL,
  `dispatched_quantity` int unsigned NOT NULL DEFAULT '0',
  `dispatched_quantity_tax` int unsigned NOT NULL DEFAULT '0',
  `dispatched_quantity_non_tax` int unsigned NOT NULL DEFAULT '0',
  `dispatched_quantity_broken_tax` int unsigned NOT NULL DEFAULT '0',
  `dispatched_quantity_broken_non_tax` int unsigned NOT NULL DEFAULT '0',
  `dispatched_serial_numbers` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transfer_products_transfer_id_foreign` (`transfer_id`),
  KEY `transfer_products_product_id_foreign` (`product_id`),
  KEY `transfer_products_dispatched_by_index` (`dispatched_by`),
  CONSTRAINT `transfer_products_dispatched_by_foreign` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfer_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `transfer_products_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfer_products`
--

LOCK TABLES `transfer_products` WRITE;
/*!40000 ALTER TABLE `transfer_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `transfer_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfers`
--

DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origin_location_id` bigint unsigned NOT NULL,
  `destination_location_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `dispatched_by` bigint unsigned DEFAULT NULL,
  `return_dispatched_by` bigint unsigned DEFAULT NULL,
  `return_dispatched_at` timestamp NULL DEFAULT NULL,
  `return_received_by` bigint unsigned DEFAULT NULL,
  `return_received_at` timestamp NULL DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','DISPATCHED','RECEIVED','RETURN_DISPATCHED','RETURN_RECEIVED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `received_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfers_origin_document_number_unique` (`origin_location_id`,`document_number`),
  KEY `transfers_received_by_index` (`received_by`),
  KEY `transfers_return_dispatched_by_index` (`return_dispatched_by`),
  KEY `transfers_return_received_by_index` (`return_received_by`),
  CONSTRAINT `transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_return_dispatched_by_foreign` FOREIGN KEY (`return_dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_return_received_by_foreign` FOREIGN KEY (`return_received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfers`
--

LOCK TABLES `transfers` WRITE;
/*!40000 ALTER TABLE `transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `units`
--

DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `units` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation_value` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `units_setting_name_unique` (`setting_id`,`name`),
  UNIQUE KEY `units_setting_short_name_unique` (`setting_id`,`short_name`),
  CONSTRAINT `units_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `units`
--

LOCK TABLES `units` WRITE;
/*!40000 ALTER TABLE `units` DISABLE KEYS */;
INSERT INTO `units` VALUES (1,NULL,'PIECE','PC(S)','*',1,'2025-12-01 13:15:27','2025-12-01 13:15:27'),(2,1,'BOX','BOX(ES)',NULL,NULL,'2025-12-01 13:19:09','2025-12-01 13:19:09'),(3,1,'RIM','RIM(S)',NULL,NULL,'2025-12-01 13:19:19','2025-12-01 13:19:19');
/*!40000 ALTER TABLE `units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `uploads`
--

DROP TABLE IF EXISTS `uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `uploads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `folder` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `uploads`
--

LOCK TABLES `uploads` WRITE;
/*!40000 ALTER TABLE `uploads` DISABLE KEYS */;
/*!40000 ALTER TABLE `uploads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_setting`
--

DROP TABLE IF EXISTS `user_setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_setting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `setting_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_setting_user_id_foreign` (`user_id`),
  KEY `user_setting_setting_id_foreign` (`setting_id`),
  KEY `user_setting_role_id_foreign` (`role_id`),
  CONSTRAINT `user_setting_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_setting_setting_id_foreign` FOREIGN KEY (`setting_id`) REFERENCES `settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_setting_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_setting`
--

LOCK TABLES `user_setting` WRITE;
/*!40000 ALTER TABLE `user_setting` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'ADMINISTRATOR','super.admin@tiga-computer.com',NULL,'$2y$10$NTeBNOlXBp0LVidnf29dGuZnMEyx.mR.qKeAJIcGSQUUx5EsXb6Di',1,NULL,'2025-12-01 13:15:27','2025-12-01 13:15:27');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `websockets_statistics_entries`
--

DROP TABLE IF EXISTS `websockets_statistics_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `websockets_statistics_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peak_connection_count` int NOT NULL,
  `websocket_message_count` int NOT NULL,
  `api_message_count` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `websockets_statistics_entries`
--

LOCK TABLES `websockets_statistics_entries` WRITE;
/*!40000 ALTER TABLE `websockets_statistics_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `websockets_statistics_entries` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-01  7:38:16
