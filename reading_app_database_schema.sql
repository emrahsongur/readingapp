-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Anamakine: mysql
-- Üretim Zamanı: 14 Şub 2026, 21:11:20
-- Sunucu sürümü: 8.0.45
-- PHP Sürümü: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `reading_app`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alintilar`
--

CREATE TABLE `alintilar` (
  `id` int NOT NULL,
  `kitap_id` int NOT NULL,
  `kayit` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degisiklik` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `alinti` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sayfa_baslangic` int DEFAULT NULL,
  `sayfa_bitis` int DEFAULT NULL,
  `foto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `durum`
--

CREATE TABLE `durum` (
  `id` int NOT NULL,
  `durum` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grup` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aktif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `durum`
--

INSERT INTO `durum` (`id`, `durum`, `grup`, `aktif`) VALUES
(1, 'Okunacak', 'Plan', 1),
(2, 'Okunuyor', 'Süreç', 1),
(3, 'Bitti', 'Sonuç', 1),
(4, 'Yarım Bırakıldı', 'Sonuç', 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `dusunceler`
--

CREATE TABLE `dusunceler` (
  `id` int NOT NULL,
  `kitap_id` int NOT NULL,
  `alinti_id` int DEFAULT NULL,
  `kayit` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degisiklik` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `dusunce` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sayfa_baslangic` int DEFAULT NULL,
  `sayfa_bitis` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kitaplar`
--

CREATE TABLE `kitaplar` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `durum_id` int NOT NULL,
  `baslik` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `yazar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kapak` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sayfa` int DEFAULT '0',
  `baslangic_sayfa` int DEFAULT '1',
  `bitis_sayfa` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kitap_raf`
--

CREATE TABLE `kitap_raf` (
  `kitap_id` int NOT NULL,
  `raf_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `okumalar`
--

CREATE TABLE `okumalar` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `baslama` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `bitis` timestamp NULL DEFAULT NULL,
  `sure_saniye` int DEFAULT '0',
  `baslama_sayfasi` int NOT NULL,
  `bitis_sayfasi` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `raflar`
--

CREATE TABLE `raflar` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `etiket` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aciklama` text COLLATE utf8mb4_unicode_ci,
  `kaydet` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degistir` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `degistiren` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pass` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ad_soyad` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin` tinyint(1) DEFAULT '0',
  `gemini_api_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kaydet` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degis` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `alintilar`
--
ALTER TABLE `alintilar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kitap_id` (`kitap_id`);

--
-- Tablo için indeksler `durum`
--
ALTER TABLE `durum`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `dusunceler`
--
ALTER TABLE `dusunceler`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kitap_id` (`kitap_id`),
  ADD KEY `alinti_id` (`alinti_id`);

--
-- Tablo için indeksler `kitaplar`
--
ALTER TABLE `kitaplar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `durum_id` (`durum_id`);

--
-- Tablo için indeksler `kitap_raf`
--
ALTER TABLE `kitap_raf`
  ADD PRIMARY KEY (`kitap_id`,`raf_id`),
  ADD KEY `raf_id` (`raf_id`);

--
-- Tablo için indeksler `okumalar`
--
ALTER TABLE `okumalar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Tablo için indeksler `raflar`
--
ALTER TABLE `raflar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `degistiren` (`degistiren`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `alintilar`
--
ALTER TABLE `alintilar`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `durum`
--
ALTER TABLE `durum`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Tablo için AUTO_INCREMENT değeri `dusunceler`
--
ALTER TABLE `dusunceler`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `kitaplar`
--
ALTER TABLE `kitaplar`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `okumalar`
--
ALTER TABLE `okumalar`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `raflar`
--
ALTER TABLE `raflar`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `alintilar`
--
ALTER TABLE `alintilar`
  ADD CONSTRAINT `alintilar_ibfk_1` FOREIGN KEY (`kitap_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `dusunceler`
--
ALTER TABLE `dusunceler`
  ADD CONSTRAINT `dusunceler_ibfk_1` FOREIGN KEY (`kitap_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dusunceler_ibfk_2` FOREIGN KEY (`alinti_id`) REFERENCES `alintilar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `kitaplar`
--
ALTER TABLE `kitaplar`
  ADD CONSTRAINT `kitaplar_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kitaplar_ibfk_2` FOREIGN KEY (`durum_id`) REFERENCES `durum` (`id`) ON DELETE RESTRICT;

--
-- Tablo kısıtlamaları `kitap_raf`
--
ALTER TABLE `kitap_raf`
  ADD CONSTRAINT `kitap_raf_ibfk_1` FOREIGN KEY (`kitap_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kitap_raf_ibfk_2` FOREIGN KEY (`raf_id`) REFERENCES `raflar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `okumalar`
--
ALTER TABLE `okumalar`
  ADD CONSTRAINT `okumalar_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `okumalar_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `kitaplar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `raflar`
--
ALTER TABLE `raflar`
  ADD CONSTRAINT `raflar_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `raflar_ibfk_2` FOREIGN KEY (`degistiren`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
