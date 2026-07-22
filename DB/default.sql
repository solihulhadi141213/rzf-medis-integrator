-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 22, 2026 at 06:34 PM
-- Server version: 9.1.0
-- PHP Version: 7.4.33

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
  `createdBy` int UNSIGNED DEFAULT NULL COMMENT 'accountId creator',
  `createdDate` datetime DEFAULT NULL,
  `updatedBy` int UNSIGNED DEFAULT NULL COMMENT 'accountId updater',
  `updatedDate` datetime DEFAULT NULL,
  PRIMARY KEY (`accountId`),
  KEY `account_to_level` (`id_account_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tabel akun user';

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
  `id_service_feature` int UNSIGNED NOT NULL COMMENT 'Dari tabel service_feature',
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
-- Table structure for table `allergen`
--

DROP TABLE IF EXISTS `allergen`;
CREATE TABLE IF NOT EXISTS `allergen` (
  `AllergenId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` enum('Food','Medication','Environment','Biologic') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kategori Alergen',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama Alergen',
  `code_alergen` varchar(255) DEFAULT NULL,
  `display_alergen` varchar(255) DEFAULT NULL,
  `system_alergen` text COMMENT 'http://snomed.info/sct',
  `author_id` int UNSIGNED DEFAULT NULL COMMENT 'ID akses pembuat',
  `author_name` varchar(255) NOT NULL COMMENT 'Nama pembuat',
  `datetime_creat` timestamp NOT NULL,
  `status` tinyint(1) NOT NULL,
  PRIMARY KEY (`AllergenId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Referensi zat alergen';

-- --------------------------------------------------------

--
-- Table structure for table `allergy`
--

DROP TABLE IF EXISTS `allergy`;
CREATE TABLE IF NOT EXISTS `allergy` (
  `allergyId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `patientId` int UNSIGNED NOT NULL COMMENT 'Dari tabel patient',
  `encounterId` int UNSIGNED NOT NULL COMMENT 'Dari tabel encounter',
  `medicalPersonelId` int UNSIGNED NOT NULL COMMENT 'Tenaga medis yang menyatakan diagnosa ',
  `satuSehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'ID Allergy Intolerance dari SATUSEHAT',
  `allergenCategory` enum('Food','Medication','Environment','Biologic') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kategori Alergen',
  `allergenName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama zat penyebab alergi',
  `allergenCode` varchar(50) NOT NULL COMMENT 'Kode Alergen dari SNOMED',
  `allergenDisplay` varchar(255) NOT NULL COMMENT 'Nama alergen berdasarkan SNOMED',
  `allergenSystem` varchar(255) NOT NULL DEFAULT 'http://snomed.info/sct' COMMENT 'System yang digunakan http://snomed.info/sct',
  `clinicalStatus` enum('active','inactive','resolved') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Status Klinis',
  `verificationStatus` enum('unconfirmed','presumed','confirmed','refuted','entered-in-error') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Status proses verifikasi',
  `allergyDescription` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci COMMENT 'Keterangan, reaksi yang dialamai pasien',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  PRIMARY KEY (`allergyId`),
  KEY `id_pasien` (`patientId`),
  KEY `id_kunjungan` (`encounterId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Riwayat alergi pasien';

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
-- Table structure for table `body_site`
--

DROP TABLE IF EXISTS `body_site`;
CREATE TABLE IF NOT EXISTS `body_site` (
  `id_body_site` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `body_site_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama Body Site Dalam istilah Lokal',
  `body_site_display` varchar(255) DEFAULT NULL COMMENT 'Nama Body Site Berdasarkan standar referensi yang digunakan',
  `body_site_code` varchar(255) DEFAULT NULL COMMENT 'Kode Body Site Berdasarkan standar referensi yang digunakan',
  `body_site_system` text COMMENT 'System Sumber',
  `datetime_creat` datetime NOT NULL COMMENT 'Keterangan waktu pembuatan data',
  `datetime_update` datetime NOT NULL COMMENT 'Keterangan waktu kapan diubah',
  `author_id` int UNSIGNED DEFAULT NULL COMMENT 'Account ID autohor',
  `author_name` varchar(255) DEFAULT NULL COMMENT 'Nama Author',
  PRIMARY KEY (`id_body_site`),
  KEY `body_site_to_account` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Daftar Referensi Lokasi Tubuh';

-- --------------------------------------------------------

--
-- Table structure for table `diagnosis`
--

DROP TABLE IF EXISTS `diagnosis`;
CREATE TABLE IF NOT EXISTS `diagnosis` (
  `diagnosisId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounterId` int UNSIGNED NOT NULL COMMENT 'dari tabel encounter',
  `patientId` int UNSIGNED NOT NULL COMMENT 'dari tabel patient',
  `idCondition` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Resource Condition Satusehat',
  `medicalPersonelId` int UNSIGNED DEFAULT NULL COMMENT 'ID Dokter Yang Menyatakan',
  `category` enum('Admission','Provisional','Primary','Secondary','Working','Differential','Final') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Masuk, Awal, Utama, ',
  `icdVersion` enum('ICD9','ICD10','ICD11') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Versi yang digunakan',
  `icdCode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kode ICD',
  `icdDescription` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Deskripsi Diagnosis',
  `diagnosisText` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci COMMENT 'text bebas dari pernyataan dokter',
  `caseStatus` enum('Baru','Lama','Kambuh','Kronis') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Status kasus',
  `certaintyStatus` enum('Provisional','Final') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Status kepastian : Sementara, Tetap (selesai)',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`diagnosisId`),
  KEY `id_kunjungan` (`encounterId`),
  KEY `id_pasien` (`patientId`),
  KEY `dokter_id` (`medicalPersonelId`),
  KEY `id_condition` (`idCondition`),
  KEY `diagnosis_akses` (`creatBy`),
  KEY `diagnosis_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data diagnosis pasien';

-- --------------------------------------------------------

--
-- Table structure for table `encounter`
--

DROP TABLE IF EXISTS `encounter`;
CREATE TABLE IF NOT EXISTS `encounter` (
  `encounterId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `EncounterCode` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kode Lokal Kunjungan',
  `satuSehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'ID Encounter Dari SATUSEHAT',
  `registrationDatetime` datetime DEFAULT NULL COMMENT 'Tanggal & Jam Pendaftaran (timezone UTC)',
  `patientId` int UNSIGNED NOT NULL COMMENT 'Dari tabel patient',
  `reasonForVisit` varchar(100) NOT NULL DEFAULT 'Berobat' COMMENT 'Latar belakang kunjungan : Berobat, Kontrol, MCU, Imunisasi, Konsultasi',
  `chiefComplaint` text COMMENT 'Keluhan Utama misalnya : Batuk Pilek, Demam 2 hari, Sakit kepala, dll',
  `priority` enum('R','UR','EM','EL') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Prioritas pelayanan (http://terminology.hl7.org/CodeSystem/v3-ActPriority)',
  `destination` enum('AMB','IMP','EMER','OBSENC','VR','HH') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Tujuan kunjungan (http://terminology.hl7.org/CodeSystem/v3-ActCode)',
  `polyclinicId` int UNSIGNED DEFAULT NULL COMMENT 'ID Poliklinik dari tabel polyclinic',
  `inpatientClassId` int UNSIGNED DEFAULT NULL COMMENT 'Kelas Rawat Inap dari tabel inpatient_class',
  `inpatientRoomId` int UNSIGNED DEFAULT NULL COMMENT 'Ruang Rawat Inap Dari Tabel inpatient_room',
  `inpatientBedId` int UNSIGNED DEFAULT NULL COMMENT 'Kode Tempat Tidur Dari tabel inpatient_bed',
  `assurance` tinyint(1) DEFAULT NULL COMMENT '0: Umum | 1: Asuransi',
  `assuranceName` varchar(100) DEFAULT NULL COMMENT 'Nama paket asuransi (Contoh : BPJS PBI, Alianz, Prudential)',
  `assuranceNumber` varchar(50) DEFAULT NULL COMMENT 'Nomor Asuransi',
  `emergencyContactName` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nama kerabat untuk kontak darurat',
  `emergencyContactPhone` varchar(20) DEFAULT NULL COMMENT 'Nomor kontak kerabat untuk kontak darurat',
  `status` enum('planned','arrived','triaged','in-progress','onleave','finished','cancelled','entered-in-error','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'planned' COMMENT 'Status kunjungan pasien',
  `creatAt` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateAt` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `creatBy` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timezone UTC',
  `updateBy` datetime DEFAULT NULL COMMENT 'Timezone UTCTimezone UTCTimezone UTC',
  PRIMARY KEY (`encounterId`),
  UNIQUE KEY `EncounterCode` (`EncounterCode`),
  KEY `encounter_to_patient` (`patientId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `encounter_performer`
--

DROP TABLE IF EXISTS `encounter_performer`;
CREATE TABLE IF NOT EXISTS `encounter_performer` (
  `encounterPerformerId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounterId` int UNSIGNED NOT NULL COMMENT 'ID Kunjungan dari tabel encounter',
  `performerType` enum('ATND','CON','REF','ADM','DIS') NOT NULL COMMENT 'Tipe performer, tipe peran serta tenaga kesehatan',
  `medicalPersonelId` int UNSIGNED NOT NULL COMMENT 'Tenaga kesehatan dari tabel medical_personel ',
  PRIMARY KEY (`encounterPerformerId`),
  KEY `performer_to_encounter` (`encounterId`),
  KEY `performer_to_medical_personel` (`medicalPersonelId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tenaga kesehatan yang terlibat dalam pelayanan kunjungan pasien';

-- --------------------------------------------------------

--
-- Table structure for table `encounter_status`
--

DROP TABLE IF EXISTS `encounter_status`;
CREATE TABLE IF NOT EXISTS `encounter_status` (
  `encounterStatusId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounterId` int UNSIGNED NOT NULL COMMENT 'Dari tabel encounter',
  `encounterStatus` enum('planned','arrived','triaged','in-progress','onleave','finished','cancelled','entered-in-error','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Status kunjungan pasien',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateBy` int UNSIGNED NOT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`encounterStatusId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Riwayat perubahan status pelayanan kunjungan';

-- --------------------------------------------------------

--
-- Table structure for table `general_consent`
--

DROP TABLE IF EXISTS `general_consent`;
CREATE TABLE IF NOT EXISTS `general_consent` (
  `id_general_consent` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_consent` varchar(255) NOT NULL COMMENT 'SATUSEHAT',
  `id_kunjungan` int UNSIGNED NOT NULL,
  `id_pasien` int UNSIGNED NOT NULL,
  `metode_consent` enum('Manual','Digital') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Manual (Cetak), Digital (Signature)',
  `petugas_edukasi_id` int UNSIGNED DEFAULT NULL COMMENT 'ID Akses Petugas',
  `petugas_edukasi_nama` varchar(255) NOT NULL COMMENT 'Nama Petugas (text)',
  `petugas_edukasi_nik` varchar(255) DEFAULT NULL COMMENT 'Dari tabel akses',
  `petugas_edukasi_ttd` longtext COMMENT 'bas64',
  `penandatangan_tipe` enum('Pasien','Keluarga','Penanggung Jawab') NOT NULL,
  `penandatangan_nama` varchar(255) NOT NULL,
  `penandatangan_nik` varchar(255) DEFAULT NULL COMMENT 'Jika pasien maka dari tabel ''Pasien''',
  `penandatangan_ttd` longtext,
  `policy_rule` enum('opt-in','opt-out') NOT NULL,
  `pernyataan_pasien` json NOT NULL,
  `status` tinyint(1) NOT NULL,
  `datetime_creat` datetime NOT NULL,
  `datetime_update` datetime NOT NULL,
  PRIMARY KEY (`id_general_consent`),
  KEY `id_kunjungan` (`id_kunjungan`),
  KEY `id_pasien` (`id_pasien`),
  KEY `petugas_edukasi_id` (`petugas_edukasi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `icd`
--

DROP TABLE IF EXISTS `icd`;
CREATE TABLE IF NOT EXISTS `icd` (
  `id_icd` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `long_des` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `short_des` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `icd` enum('ICD9','ICD10','ICD11') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`id_icd`),
  UNIQUE KEY `kode_2` (`kode`),
  KEY `kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inpatient_bed`
--

DROP TABLE IF EXISTS `inpatient_bed`;
CREATE TABLE IF NOT EXISTS `inpatient_bed` (
  `inpatientBedId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `inpatientClassId` int UNSIGNED NOT NULL COMMENT 'Dari tabel inpatient_class',
  `inpatientRoomId` int UNSIGNED NOT NULL COMMENT 'Dari tabel inpatient_room',
  `inpatientBedCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kode lokal tempat tidur',
  `satuSehatCode` varchar(50) NOT NULL COMMENT 'ID Location dari satusehat',
  `genderPolicy` enum('Male','Female','Unisex') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'Unisex' COMMENT 'kebijakan penggunaan tempat tidur berdasarkan jenis kelamin pasien',
  `status` tinyint(1) DEFAULT NULL COMMENT '0 : Inactive | 1 : Active',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateAt` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`inpatientBedId`),
  KEY `tt_to_kelas` (`inpatientClassId`),
  KEY `tt_to_ruang_rawat` (`inpatientRoomId`),
  KEY `bed_to_account_1` (`creatBy`),
  KEY `bed_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tempat Tidur';

-- --------------------------------------------------------

--
-- Table structure for table `inpatient_class`
--

DROP TABLE IF EXISTS `inpatient_class`;
CREATE TABLE IF NOT EXISTS `inpatient_class` (
  `inpatientClassId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `inpatientClassCode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kode Kelas Secara lokal',
  `satuSehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'ID Location dari satusehat platform',
  `inpatientClassName` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama kelas',
  `status` tinyint(1) NOT NULL COMMENT '0 : Inactive | 1 : Active',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updatedAt` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`inpatientClassId`),
  KEY `inpatient_class_to_account_1` (`creatBy`),
  KEY `inpatient_class_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Kelas Inap';

-- --------------------------------------------------------

--
-- Table structure for table `inpatient_room`
--

DROP TABLE IF EXISTS `inpatient_room`;
CREATE TABLE IF NOT EXISTS `inpatient_room` (
  `inpatientRoomId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `inpatientClassId` int UNSIGNED NOT NULL COMMENT 'dari tabel inpatient_class',
  `inpatientRoomCode` varchar(20) NOT NULL COMMENT 'Kode ruangan secara lokal',
  `satuSehatCode` varchar(50) DEFAULT NULL COMMENT 'ID Location dari satusehat',
  `inpatientRoomName` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama ruangan',
  `status` tinyint(1) NOT NULL COMMENT '0 : Inactive | 1 : Active',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC ',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`inpatientRoomId`),
  KEY `ruang_to_kelas` (`inpatientClassId`),
  KEY `inpatient_room_to_account_1` (`creatBy`),
  KEY `inpatient_room_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Ruang Inap';

-- --------------------------------------------------------

--
-- Table structure for table `medical_personel`
--

DROP TABLE IF EXISTS `medical_personel`;
CREATE TABLE IF NOT EXISTS `medical_personel` (
  `medicalPersonelId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicalPersonelCode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Local code frome company',
  `id_practitioner` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'id_practitioner from satusehat patform',
  `medicalPersonelCategory` enum('Dokter Umum','Dokter Spesialis','Perawat','Bidan','Rekam Medis','Administrasi','Apoteker','Analis Laboratorium','Radiografer','Terapis','Gizi','Penata Anestesi','Elektromedis','Sanitarian','Epidemiolog') NOT NULL COMMENT 'Kategori tenaga medis',
  `nik` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nomor KTP',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama lengkap & gelar',
  `gender` enum('Male','Female') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Jenis kelamin (male OR Female)',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Alamat email',
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kontak/telepon',
  `citizenshipStatus` enum('WNI','WNA') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'WNI' COMMENT 'Status warga negara',
  `provinceId` int UNSIGNED DEFAULT NULL COMMENT 'ID Provinsi dari region_province',
  `cityId` int UNSIGNED DEFAULT NULL COMMENT 'ID Kabupaten/Kota dari region_city',
  `districtId` int UNSIGNED DEFAULT NULL COMMENT 'ID Kecamatan dari region_district',
  `villageId` bigint UNSIGNED DEFAULT NULL COMMENT 'ID Desa/Kecamatan dari region_village',
  `postalCode` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kode Pos',
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci COMMENT 'Alamat selengkapnya, seperti nama jalan, gang, nomor rumah dll',
  `photo` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Photo, Filename + Ext',
  `status` tinyint(1) DEFAULT NULL COMMENT '0: Dihapus | 1: Terdaftar',
  `createdBy` int UNSIGNED DEFAULT NULL COMMENT 'ID Account yang melakukan insert',
  `createdDate` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `updatedBy` int UNSIGNED DEFAULT NULL COMMENT 'ID Account yang melakukan Update',
  `updatedDate` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `accountId` int UNSIGNED DEFAULT NULL COMMENT 'Akun Akses Tenaga Medis',
  PRIMARY KEY (`medicalPersonelId`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `photo` (`photo`),
  KEY `personel_to_province` (`provinceId`),
  KEY `personel_to_city` (`cityId`),
  KEY `personel_to_district` (`districtId`),
  KEY `personel_to_vilage` (`villageId`),
  KEY `personel_to_createdBy` (`createdBy`),
  KEY `personel_to_updateBy` (`updatedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data Master Tenaga Kesehatan';

-- --------------------------------------------------------

--
-- Table structure for table `patient`
--

DROP TABLE IF EXISTS `patient`;
CREATE TABLE IF NOT EXISTS `patient` (
  `patientId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `noMedicalRecord` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nomor RM lokal sesuai faskes',
  `satuSehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'IHS Pasien Dari SATUSEHAT',
  `isInfant` tinyint(1) DEFAULT '0' COMMENT '1 : Bayi | 0: Bukan',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama lengkap pasien sesuai KTP, Jika bayi bisa diisi dengan nama ibu',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Alamat email',
  `phone` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nomor Kontak Pasien',
  `gender` enum('Male','Female') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Male Or Female',
  `birthPlace` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Tempat lahir',
  `birthDate` date DEFAULT NULL COMMENT 'Tanggal Lahir',
  `nik` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nomor KTP',
  `religion` enum('Islam','Kristen Protestan','Kristen Katolik','Hindu','Buddha','Konghucu','Lainnya') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Agama yang dianut',
  `martialStatus` enum('Single','Married','Widowed','Divorced') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Status pernikahan',
  `lastEducation` enum('Tidak Sekolah','SD','SMP','SMA','D1','D2','D3','D4','S1','S2','S3') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Pendidikan Terakhir',
  `occupation` enum('Tidak Bekerja','Wirausaha','Karyawan Swasta','ASN','TNI','POLRI') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Pekerjaan /Profesi',
  `language` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Bahasa yang digunakan sehari-hari',
  `ethnic` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Etnis, Suku bangas',
  `citizenshipStatus` enum('WNI','WNA') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'WNI' COMMENT 'Status warga negara',
  `provinceId` int UNSIGNED DEFAULT NULL COMMENT 'ID Provinsi From region_province',
  `cityId` int UNSIGNED DEFAULT NULL COMMENT 'ID City form region_city',
  `districtId` int UNSIGNED DEFAULT NULL COMMENT 'ID District form region_district',
  `villageId` bigint UNSIGNED DEFAULT NULL COMMENT 'Vilage ID Form region_village',
  `rt` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'RT',
  `rw` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'RW',
  `postalCode` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kode Pos',
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci COMMENT 'Alamat lengkap, Jalan, Nomor dll',
  `medicalRecordStatus` enum('Terdaftar','Meninggal','Retensi') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'Terdaftar' COMMENT 'Status data RM pasien',
  `oldMedicalRecord` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'No RM lama',
  `motherMedicalRecord` varchar(20) DEFAULT NULL COMMENT 'No RM Ibu (jika bayi)',
  `kkNumber` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nomor Kartu keluarga',
  `kkName` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Nama kepala keluarga',
  `photo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Foto-Filename+Ext',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  PRIMARY KEY (`patientId`),
  UNIQUE KEY `noMedicalRecord` (`noMedicalRecord`),
  KEY `patient_to_region_province` (`provinceId`),
  KEY `patient_to_region_city` (`cityId`),
  KEY `patient_to_region_district` (`districtId`),
  KEY `patient_to_region_vilage` (`villageId`),
  KEY `patient_to_account_1` (`creatBy`),
  KEY `patient_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data master pasien';

-- --------------------------------------------------------

--
-- Table structure for table `polyclinic`
--

DROP TABLE IF EXISTS `polyclinic`;
CREATE TABLE IF NOT EXISTS `polyclinic` (
  `polyclinicId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `satuSehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'OrgID from satusehat',
  `polyclinicCode` varchar(20) NOT NULL COMMENT 'Kode poliklinik (lokal)',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama poliklinik',
  `status` tinyint(1) DEFAULT NULL COMMENT '0: Inactive | 1: Active',
  `createdBy` int UNSIGNED DEFAULT NULL COMMENT 'Account ID From Account table',
  `createdDate` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Timezone UTC',
  `updatedBy` int UNSIGNED DEFAULT NULL COMMENT 'Account ID From Account table',
  `updatedDate` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  PRIMARY KEY (`polyclinicId`),
  KEY `updateby_to_account` (`updatedBy`),
  KEY `createdby_to_account` (`createdBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Master Poliklinik';

-- --------------------------------------------------------

--
-- Table structure for table `procedure_encounter`
--

DROP TABLE IF EXISTS `procedure_encounter`;
CREATE TABLE IF NOT EXISTS `procedure_encounter` (
  `procedureId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `patientId` int UNSIGNED NOT NULL COMMENT 'Dari tabel patient',
  `encounterId` int UNSIGNED NOT NULL COMMENT 'Dari tabel encounter',
  `satusehatCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Procedure ID from SATUSEHAT',
  `procedureStart` datetime NOT NULL COMMENT 'Start Procedure (UTC)',
  `procedureEnd` datetime DEFAULT NULL COMMENT 'End Procedure (UTC)',
  `resonReference` enum('ICD10','ICD11') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Versi ICD yang digunakan untuk resonCode',
  `resonCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kode ICD10 - Alasan tindakan',
  `resonDisplay` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Description ICD10 - Alasan tindakan',
  `postProcedure` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci COMMENT 'Keterangan/Catatan kondisi setelah tindakan',
  `creatAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateAt` datetime NOT NULL COMMENT 'Timezone UTC',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`procedureId`),
  KEY `id_pasien` (`patientId`),
  KEY `id_kunjungan` (`encounterId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data tindakan yang diberikan kepada pasien';

-- --------------------------------------------------------

--
-- Table structure for table `procedure_performer`
--

DROP TABLE IF EXISTS `procedure_performer`;
CREATE TABLE IF NOT EXISTS `procedure_performer` (
  `procedurePerformerId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tindakan` int UNSIGNED NOT NULL COMMENT 'Dari tabel tindakan',
  `id_praktisi` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel praktisi',
  `performer_type` enum('Utama','Pendamping') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `performer_ihs` varchar(255) DEFAULT NULL COMMENT 'ID Practitioner Pelaksana (SATUSEHAT)',
  `performer_nik` varchar(255) DEFAULT NULL COMMENT 'NIK Pelaksana',
  `performer_nama` varchar(255) NOT NULL COMMENT 'Nama lengkap pelaksana',
  `performer_notes` text COMMENT 'Catatan dari performer',
  PRIMARY KEY (`procedurePerformerId`),
  KEY `id_tindakan` (`id_tindakan`),
  KEY `id_praktisi` (`id_praktisi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Untuk mencatat siapa saja yang terlibat dalam tindakan';

-- --------------------------------------------------------

--
-- Table structure for table `procedure_reference`
--

DROP TABLE IF EXISTS `procedure_reference`;
CREATE TABLE IF NOT EXISTS `procedure_reference` (
  `procedureReferenceId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `procedureCategoryName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Kelompok tindakan, Jenis tindakan dalam istilah lokal',
  `procedureCategoryCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'kode kategori SNOMED',
  `procedureCategoryDipsplay` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Desktipsi kategori SNOMED',
  `procedureCategorySystem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'http://snomed.info/sct ' COMMENT 'http://snomed.info/sct',
  `procedureName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama tindakan dalam istilah lokal',
  `procedureCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kode Tindakan (berdasarkan SNOMED)',
  `procedureDisplay` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'NamaTindakan (berdasarkan SNOMED)',
  `procedureSystem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'http://snomed.info/sct' COMMENT 'Sistem Yang Digunakan (http://snomed.info/sct)',
  `bodySiteName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Nama Lokasi tubuh (Secara Lokal)',
  `bodySiteCode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Kode lokasi tubuh (berdasarkan SNOMED)(SNOMED)',
  `bodySiteDisplay` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'nama lokasi tubuh (berdasarkan SNOMED)',
  `bodySiteSystem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'http://snomed.info/sct' COMMENT 'Sistem yang digunakan',
  `icd9Code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'kode ICD9',
  `icd9Description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Deskripsi ICD9',
  `status` tinyint(1) NOT NULL COMMENT '0 : Deleted | 1 : Active',
  `creatAt` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `updateAt` datetime DEFAULT NULL COMMENT 'Timezone UTC',
  `creatBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  `updateBy` int UNSIGNED DEFAULT NULL COMMENT 'dari tabel account',
  PRIMARY KEY (`procedureReferenceId`),
  KEY `procedure_reference_to_account_1` (`creatBy`),
  KEY `procedure_reference_to_account_2` (`updateBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Mencatat referensi tindakan';

-- --------------------------------------------------------

--
-- Table structure for table `rate_limit`
--

DROP TABLE IF EXISTS `rate_limit`;
CREATE TABLE IF NOT EXISTS `rate_limit` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `endpoint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
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
-- Table structure for table `region_city`
--

DROP TABLE IF EXISTS `region_city`;
CREATE TABLE IF NOT EXISTS `region_city` (
  `cityId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `oldCode` varchar(9) DEFAULT NULL,
  `bps` varchar(8) DEFAULT NULL,
  `provinceId` int UNSIGNED NOT NULL,
  PRIMARY KEY (`cityId`),
  KEY `city_to_province` (`provinceId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Wilayah Kabupaten Kota';

-- --------------------------------------------------------

--
-- Table structure for table `region_district`
--

DROP TABLE IF EXISTS `region_district`;
CREATE TABLE IF NOT EXISTS `region_district` (
  `districtId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(31) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `oldCode` int DEFAULT NULL,
  `bps` varchar(7) DEFAULT NULL,
  `cityId` int UNSIGNED NOT NULL,
  PRIMARY KEY (`districtId`),
  KEY `distruct_to_city` (`cityId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Wilayah tingkat kecamatan';

-- --------------------------------------------------------

--
-- Table structure for table `region_province`
--

DROP TABLE IF EXISTS `region_province`;
CREATE TABLE IF NOT EXISTS `region_province` (
  `provinceId` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `bps` varchar(11) NOT NULL,
  PRIMARY KEY (`provinceId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Wilayah Tingkat Provinsi';

-- --------------------------------------------------------

--
-- Table structure for table `region_village`
--

DROP TABLE IF EXISTS `region_village`;
CREATE TABLE IF NOT EXISTS `region_village` (
  `villageId` bigint UNSIGNED NOT NULL,
  `name` varchar(225) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `oldCode` varchar(20) DEFAULT NULL,
  `bps` varchar(20) DEFAULT NULL,
  `districtId` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`villageId`),
  KEY `vilage_to_district` (`districtId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Wilayah tingkat desa/kelurahan';

-- --------------------------------------------------------

--
-- Table structure for table `satusehat`
--

DROP TABLE IF EXISTS `satusehat`;
CREATE TABLE IF NOT EXISTS `satusehat` (
  `credentialId` int NOT NULL AUTO_INCREMENT,
  `credentialName` varchar(255) NOT NULL,
  `baseUrl` varchar(255) NOT NULL,
  `organizationId` varchar(255) NOT NULL,
  `clientKey` varchar(255) NOT NULL,
  `secretKey` varchar(255) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Active Or Inactive',
  `token` varchar(255) DEFAULT NULL,
  `tokenExpired` timestamp NULL DEFAULT NULL COMMENT 'Batas waktu token (UTC)',
  `createdDate` datetime DEFAULT NULL,
  `updatedDate` datetime DEFAULT NULL,
  PRIMARY KEY (`credentialId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Menyimpan credential satusehat';

-- --------------------------------------------------------

--
-- Table structure for table `schedule`
--

DROP TABLE IF EXISTS `schedule`;
CREATE TABLE IF NOT EXISTS `schedule` (
  `id_jadwal` int NOT NULL AUTO_INCREMENT,
  `id_dokter` int NOT NULL,
  `id_poliklinik` int NOT NULL,
  `dokter` varchar(100) CHARACTER SET latin1 NOT NULL,
  `poliklinik` varchar(50) CHARACTER SET latin1 NOT NULL,
  `hari` varchar(25) CHARACTER SET latin1 NOT NULL,
  `jam` varchar(25) CHARACTER SET latin1 NOT NULL,
  `kuota_non_jkn` int DEFAULT NULL,
  `kuota_jkn` int DEFAULT NULL,
  `time_max` int NOT NULL,
  PRIMARY KEY (`id_jadwal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Jadwal Dokter';

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

--
-- Constraints for table `body_site`
--
ALTER TABLE `body_site`
  ADD CONSTRAINT `body_site_to_account` FOREIGN KEY (`author_id`) REFERENCES `account` (`accountId`) ON DELETE SET NULL;

--
-- Constraints for table `diagnosis`
--
ALTER TABLE `diagnosis`
  ADD CONSTRAINT `diagnosis_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `diagnosis_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `diagnosis_to_encounter` FOREIGN KEY (`encounterId`) REFERENCES `encounter` (`encounterId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `diagnosis_to_medicalPersonel` FOREIGN KEY (`medicalPersonelId`) REFERENCES `medical_personel` (`medicalPersonelId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `diagnosis_to_patient` FOREIGN KEY (`patientId`) REFERENCES `patient` (`patientId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `encounter`
--
ALTER TABLE `encounter`
  ADD CONSTRAINT `encounter_to_patient` FOREIGN KEY (`patientId`) REFERENCES `patient` (`patientId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `encounter_performer`
--
ALTER TABLE `encounter_performer`
  ADD CONSTRAINT `performer_to_encounter` FOREIGN KEY (`encounterId`) REFERENCES `encounter` (`encounterId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `performer_to_medical_personel` FOREIGN KEY (`medicalPersonelId`) REFERENCES `medical_personel` (`medicalPersonelId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inpatient_bed`
--
ALTER TABLE `inpatient_bed`
  ADD CONSTRAINT `bed_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `bed_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `bed_to_class` FOREIGN KEY (`inpatientClassId`) REFERENCES `inpatient_class` (`inpatientClassId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `bed_to_room` FOREIGN KEY (`inpatientRoomId`) REFERENCES `inpatient_room` (`inpatientRoomId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inpatient_class`
--
ALTER TABLE `inpatient_class`
  ADD CONSTRAINT `inpatient_class_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `inpatient_class_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Constraints for table `inpatient_room`
--
ALTER TABLE `inpatient_room`
  ADD CONSTRAINT `inpatient_room_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `inpatient_room_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `room_to_class` FOREIGN KEY (`inpatientClassId`) REFERENCES `inpatient_class` (`inpatientClassId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `medical_personel`
--
ALTER TABLE `medical_personel`
  ADD CONSTRAINT `personel_to_city` FOREIGN KEY (`cityId`) REFERENCES `region_city` (`cityId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `personel_to_createdBy` FOREIGN KEY (`createdBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `personel_to_district` FOREIGN KEY (`districtId`) REFERENCES `region_district` (`districtId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `personel_to_province` FOREIGN KEY (`provinceId`) REFERENCES `region_province` (`provinceId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `personel_to_updateBy` FOREIGN KEY (`updatedBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `personel_to_vilage` FOREIGN KEY (`villageId`) REFERENCES `region_village` (`villageId`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Constraints for table `patient`
--
ALTER TABLE `patient`
  ADD CONSTRAINT `patient_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `patient_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `patient_to_region_city` FOREIGN KEY (`cityId`) REFERENCES `region_city` (`cityId`) ON DELETE SET NULL,
  ADD CONSTRAINT `patient_to_region_district` FOREIGN KEY (`districtId`) REFERENCES `region_district` (`districtId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `patient_to_region_province` FOREIGN KEY (`provinceId`) REFERENCES `region_province` (`provinceId`) ON DELETE SET NULL,
  ADD CONSTRAINT `patient_to_region_vilage` FOREIGN KEY (`villageId`) REFERENCES `region_village` (`villageId`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Constraints for table `polyclinic`
--
ALTER TABLE `polyclinic`
  ADD CONSTRAINT `createdby_to_account` FOREIGN KEY (`createdBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `updateby_to_account` FOREIGN KEY (`updatedBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Constraints for table `procedure_encounter`
--
ALTER TABLE `procedure_encounter`
  ADD CONSTRAINT `procedure_to_encounter` FOREIGN KEY (`encounterId`) REFERENCES `encounter` (`encounterId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `procedure_to_patient` FOREIGN KEY (`patientId`) REFERENCES `patient` (`patientId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `procedure_reference`
--
ALTER TABLE `procedure_reference`
  ADD CONSTRAINT `procedure_reference_to_account_1` FOREIGN KEY (`creatBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `procedure_reference_to_account_2` FOREIGN KEY (`updateBy`) REFERENCES `account` (`accountId`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Constraints for table `region_city`
--
ALTER TABLE `region_city`
  ADD CONSTRAINT `city_to_province` FOREIGN KEY (`provinceId`) REFERENCES `region_province` (`provinceId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `region_district`
--
ALTER TABLE `region_district`
  ADD CONSTRAINT `distruct_to_city` FOREIGN KEY (`cityId`) REFERENCES `region_city` (`cityId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `region_village`
--
ALTER TABLE `region_village`
  ADD CONSTRAINT `vilage_to_district` FOREIGN KEY (`districtId`) REFERENCES `region_district` (`districtId`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
