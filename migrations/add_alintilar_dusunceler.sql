-- Alıntılar ve Düşünceler tabloları (Alıntı/Düşünce modülü)
-- Veritabanı: reading_app, utf8mb4

SET NAMES utf8mb4;

-- --------------------------------------------------------
-- Tablo: alintilar
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alintilar` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kitap_id` int NOT NULL,
  `kayit` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degisiklik` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `alinti` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sayfa_baslangic` int DEFAULT NULL,
  `sayfa_bitis` int DEFAULT NULL,
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kitap_id` (`kitap_id`),
  CONSTRAINT `alintilar_ibfk_1` FOREIGN KEY (`kitap_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: dusunceler
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dusunceler` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kitap_id` int NOT NULL,
  `alinti_id` int DEFAULT NULL,
  `kayit` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degisiklik` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `dusunce` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sayfa_baslangic` int DEFAULT NULL,
  `sayfa_bitis` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kitap_id` (`kitap_id`),
  KEY `alinti_id` (`alinti_id`),
  CONSTRAINT `dusunceler_ibfk_1` FOREIGN KEY (`kitap_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dusunceler_ibfk_2` FOREIGN KEY (`alinti_id`) REFERENCES `alintilar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
