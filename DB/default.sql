-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 14, 2026 at 07:11 PM
-- Server version: 9.1.0
-- PHP Version: 8.1.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rzf_medis`
--

-- --------------------------------------------------------

--
-- Table structure for table `account`
--

DROP TABLE IF EXISTS `account`;
CREATE TABLE IF NOT EXISTS `account` (
  `accountId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_account_level` int UNSIGNED DEFAULT NULL COMMENT 'From Account Level',
  `photo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '0' COMMENT 'Active Or Inavtive',
  `createdBy` int UNSIGNED DEFAULT NULL,
  `createdDate` datetime DEFAULT NULL,
  `updatedBy` int UNSIGNED DEFAULT NULL,
  `updatedDate` datetime DEFAULT NULL,
  PRIMARY KEY (`accountId`),
  KEY `account_to_level` (`id_account_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `account_level`
--

DROP TABLE IF EXISTS `account_level`;
CREATE TABLE IF NOT EXISTS `account_level` (
  `id_account_level` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `level_name` varchar(255) NOT NULL,
  `level_description` text NOT NULL,
  PRIMARY KEY (`id_account_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `account_level_reference`
--

DROP TABLE IF EXISTS `account_level_reference`;
CREATE TABLE IF NOT EXISTS `account_level_reference` (
  `id_account_level_reference` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_account` int UNSIGNED NOT NULL,
  `id_service_feature` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id_account_level_reference`),
  KEY `reference_to_account` (`id_account`),
  KEY `reference_to_features` (`id_service_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Referensi fitur standar yang dapat di akses oleh user';

-- --------------------------------------------------------

--
-- Table structure for table `account_permission`
--

DROP TABLE IF EXISTS `account_permission`;
CREATE TABLE IF NOT EXISTS `account_permission` (
  `id_account_permission` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `accountId` int UNSIGNED NOT NULL COMMENT 'Dari tabel account',
  `id_service_feature` int UNSIGNED NOT NULL COMMENT 'dari tabel service_feature',
  PRIMARY KEY (`id_account_permission`),
  KEY `permission_to_account` (`accountId`),
  KEY `permission_to_features` (`id_service_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `account_token`
--

DROP TABLE IF EXISTS `account_token`;
CREATE TABLE IF NOT EXISTS `account_token` (
  `id_account_token` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `accountId` int UNSIGNED NOT NULL,
  `account_token` varchar(255) NOT NULL,
  `datetime_creat` datetime NOT NULL,
  `datetime_expired` datetime NOT NULL,
  PRIMARY KEY (`id_account_token`),
  KEY `account_token_to_account` (`accountId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_key`
--

DROP TABLE IF EXISTS `api_key`;
CREATE TABLE IF NOT EXISTS `api_key` (
  `id_api_key` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama API',
  `api_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Penjelasan Singkat',
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'ID Pengguna (Username)',
  `client_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Hasing / Password',
  `expired_duration` int UNSIGNED NOT NULL COMMENT 'Hour / Jam',
  `datetime_creat` datetime NOT NULL COMMENT 'Kapan API Key Dibuat',
  `datetime_update` datetime NOT NULL COMMENT 'Kapan Terakhir Kali API Key Diubah',
  `status` tinyint(1) NOT NULL COMMENT 'Active Or Inactive',
  PRIMARY KEY (`id_api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_token`
--

DROP TABLE IF EXISTS `api_token`;
CREATE TABLE IF NOT EXISTS `api_token` (
  `id_api_token` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_api_key` int UNSIGNED NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `datetime_creat` datetime NOT NULL,
  `datetime_expired` datetime NOT NULL,
  PRIMARY KEY (`id_api_token`),
  KEY `id_api_access` (`id_api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Untuk menampung API token';

-- --------------------------------------------------------

--
-- Table structure for table `rate_limit`
--

DROP TABLE IF EXISTS `rate_limit`;
CREATE TABLE IF NOT EXISTS `rate_limit` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `endpoint` varchar(100) NOT NULL,
  `request_time` int UNSIGNED NOT NULL,
  `hit_count` smallint UNSIGNED NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_limit` (`ip_address`,`endpoint`,`request_time`),
  KEY `idx_cleanup` (`request_time`),
  KEY `idx_endpoint` (`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_feature`
--

DROP TABLE IF EXISTS `service_feature`;
CREATE TABLE IF NOT EXISTS `service_feature` (
  `id_service_feature` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `feature_category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `feature_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `datetime_creat` timestamp NOT NULL,
  PRIMARY KEY (`id_service_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_meta`
--

DROP TABLE IF EXISTS `tb_meta`;
CREATE TABLE IF NOT EXISTS `tb_meta` (
  `meta_id` int NOT NULL AUTO_INCREMENT,
  `robots` varchar(20) DEFAULT NULL,
  `refresh` int DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `description` varchar(250) DEFAULT NULL,
  `keywords` varchar(200) DEFAULT NULL,
  `author` varchar(100) DEFAULT NULL,
  `copyright` varchar(100) DEFAULT NULL,
  `theme_color` varchar(10) DEFAULT NULL,
  `domain_name` varchar(50) DEFAULT NULL,
  `twitter_account` varchar(200) DEFAULT NULL,
  `facebook_account` varchar(200) DEFAULT NULL,
  `instagram_account` varchar(200) DEFAULT NULL,
  `email_account` varchar(100) DEFAULT NULL,
  `og_image` varchar(8) DEFAULT NULL,
  `twitter_image` varchar(8) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_date` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_date` datetime DEFAULT NULL,
  PRIMARY KEY (`meta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_profile`
--

DROP TABLE IF EXISTS `tb_profile`;
CREATE TABLE IF NOT EXISTS `tb_profile` (
  `id` int NOT NULL AUTO_INCREMENT,
  `logoFaskes` varchar(7) DEFAULT NULL,
  `logoCity` varchar(7) DEFAULT NULL,
  `organizationName` varchar(30) DEFAULT NULL,
  `organizationType` varchar(30) DEFAULT NULL,
  `phone` varchar(16) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `website` varchar(50) DEFAULT NULL,
  `address` varchar(50) DEFAULT NULL,
  `cityName` varchar(20) DEFAULT NULL,
  `postalCode` int DEFAULT NULL,
  `province` int DEFAULT NULL,
  `city` int DEFAULT NULL,
  `district` int DEFAULT NULL,
  `village` int DEFAULT NULL,
  `latitude` varchar(50) DEFAULT NULL,
  `longitude` varchar(50) DEFAULT NULL,
  `createdBy` int DEFAULT NULL,
  `createdDate` datetime DEFAULT NULL,
  `updatedBy` int DEFAULT NULL,
  `updatedDate` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_satusehat_cred`
--

DROP TABLE IF EXISTS `tb_satusehat_cred`;
CREATE TABLE IF NOT EXISTS `tb_satusehat_cred` (
  `credentialId` int NOT NULL AUTO_INCREMENT,
  `organizationId` varchar(255) NOT NULL,
  `clientKey` varchar(255) NOT NULL,
  `secretKey` varchar(255) NOT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '0',
  `createdBy` int DEFAULT NULL,
  `createdDate` datetime DEFAULT NULL,
  `updatedBy` int DEFAULT NULL,
  `updatedDate` datetime DEFAULT NULL,
  PRIMARY KEY (`credentialId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_satusehat_token`
--

DROP TABLE IF EXISTS `tb_satusehat_token`;
CREATE TABLE IF NOT EXISTS `tb_satusehat_token` (
  `id` int NOT NULL AUTO_INCREMENT,
  `environment` varchar(20) NOT NULL,
  `token` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account`
--
ALTER TABLE `account`
  ADD CONSTRAINT `account_to_level` FOREIGN KEY (`id_account_level`) REFERENCES `account_level` (`id_account_level`) ON DELETE SET NULL;

--
-- Constraints for table `account_level_reference`
--
ALTER TABLE `account_level_reference`
  ADD CONSTRAINT `reference_to_account` FOREIGN KEY (`id_account`) REFERENCES `account` (`accountId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `reference_to_features` FOREIGN KEY (`id_service_feature`) REFERENCES `service_feature` (`id_service_feature`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `account_permission`
--
ALTER TABLE `account_permission`
  ADD CONSTRAINT `permission_to_account` FOREIGN KEY (`accountId`) REFERENCES `account` (`accountId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `permission_to_features` FOREIGN KEY (`id_service_feature`) REFERENCES `service_feature` (`id_service_feature`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `account_token`
--
ALTER TABLE `account_token`
  ADD CONSTRAINT `account_token_to_account` FOREIGN KEY (`accountId`) REFERENCES `account` (`accountId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `api_token`
--
ALTER TABLE `api_token`
  ADD CONSTRAINT `token_to_api` FOREIGN KEY (`id_api_key`) REFERENCES `api_key` (`id_api_key`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
