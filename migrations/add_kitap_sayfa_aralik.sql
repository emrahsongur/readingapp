-- Kitap başlangıç/bitiş sayfası alanları (okuma aralığı)
-- Örn. kitap 15. sayfada başlayıp 215. sayfada bitiyorsa bu alanlar kullanılır.

ALTER TABLE kitaplar ADD COLUMN baslangic_sayfa INT DEFAULT 1;
ALTER TABLE kitaplar ADD COLUMN bitis_sayfa INT DEFAULT NULL;
