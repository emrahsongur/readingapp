-- Kitap tipleri (Basılı, Elektronik, Sesli) ve okumalar yüzde/süre alanları
-- Veritabanı: reading_app, utf8mb4

SET NAMES utf8mb4;

-- --------------------------------------------------------
-- Tablo: kitap_tipleri
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kitap_tipleri` (
  `id` int NOT NULL,
  `ad` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sira` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `kitap_tipleri` (`id`, `ad`, `sira`) VALUES
(1, 'Basılı', 1),
(2, 'Elektronik', 2),
(3, 'Sesli', 3);

-- --------------------------------------------------------
-- kitaplar: kitap_tipi_id, sesli_toplam_saniye
-- --------------------------------------------------------
ALTER TABLE `kitaplar`
  ADD COLUMN `kitap_tipi_id` int NOT NULL DEFAULT 1 AFTER `durum_id`,
  ADD COLUMN `sesli_toplam_saniye` int DEFAULT NULL AFTER `bitis_sayfa`;

ALTER TABLE `kitaplar`
  ADD KEY `kitap_tipi_id` (`kitap_tipi_id`),
  ADD CONSTRAINT `kitaplar_ibfk_tip` FOREIGN KEY (`kitap_tipi_id`) REFERENCES `kitap_tipleri` (`id`);

-- Mevcut kitapları Basılı yap (DEFAULT 1 zaten atanır; açıkça güncellemek için)
-- UPDATE kitaplar SET kitap_tipi_id = 1 WHERE kitap_tipi_id IS NULL;

-- --------------------------------------------------------
-- okumalar: sayfa sütunları nullable (E-kitap/Sesli için NULL)
-- --------------------------------------------------------
ALTER TABLE `okumalar`
  MODIFY COLUMN `baslama_sayfasi` int DEFAULT NULL,
  MODIFY COLUMN `bitis_sayfasi` int DEFAULT NULL;

-- --------------------------------------------------------
-- okumalar: yüzde ve sesli konum alanları
-- --------------------------------------------------------
ALTER TABLE `okumalar`
  ADD COLUMN `baslama_yuzde` decimal(5,2) DEFAULT NULL AFTER `bitis_sayfasi`,
  ADD COLUMN `bitis_yuzde` decimal(5,2) DEFAULT NULL AFTER `baslama_yuzde`,
  ADD COLUMN `baslama_sure_saniye` int DEFAULT NULL AFTER `bitis_yuzde`,
  ADD COLUMN `bitis_sure_saniye` int DEFAULT NULL AFTER `baslama_sure_saniye`;
