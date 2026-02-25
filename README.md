# 📚 Reading App

Kişisel okuma takip web uygulaması. Okuduğunuz kitapları, okuma seanslarını, rafları (etiketleri) ve kitap başına alıntı ile düşüncelerinizi tek yerden yönetin.

---
## Geliştirmeler
### 22.02.2026
- Kitapların kendine ait sayfasında düşünceler, alıntıların listesi tarih ve sayfa numarasına göre düzenlendi. Sayfaya içindekiler bölümü kondu.
- Okuma süresinin ölçüldüğü sayfada estetik geliştirmeler yapılıp tahmini bitiş süresi ve kitap için link eklendi.
- **Gemini ile alıntı kaydı:** Mobilde (oku.php) okuma seansı sırasında sayfa fotoğrafı çekilip alıntı olarak yüklenebiliyor; sayaç durmuyor. Web’de (kitap.php) alıntı düzenleme modalında foto solda, “Gemini ile metne çevir” butonu ile görsel OCR yapılıyor; metin ve sayfa numarası alana aktarılıyor. Ayarlar sayfasından kullanıcı kendi Gemini API anahtarını girebiliyor. 
- Ayarlar.php sayfası ayrıntılandırıldı. 

### 24.02.2026
- **Düşünce Ekle (oku.php):** Okuma seansı sayfasında “Alıntı Ekle”nin altına “Düşünce Ekle” butonu eklendi. Butona tıklanınca sayaç durur, modal açılır; modal kapatılınca veya düşünce kaydedilince sayaç kaldığı yerden devam eder. Aynı mantık “Alıntı Ekle” modalı için de uygulandı: alıntı modali açıkken sayaç durur, kapatılınca veya yükleme bitince devam eder.
- **Sesle düşünce ve Gemini:** Düşünce modalında mikrofon butonu ile ses kaydı alınır (kayıt sırasında buton kırmızı, durdurulunca yeşil); kayıt bitince ses Gemini ile metne çevrilir ve üstteki metin alanına yazılır. “Gemini ile düzelt” butonu bu metni (veya elle yazılan metni) imla ve akıcılık için düzeltip aşağıdaki düşünce alanına ekler. Alıntıda kullanılan aynı Gemini API anahtarı kullanılır; `gemini_dusunce.php` ses çevirisi (transcribe) ve metin düzeltme (duzelt) isteklerini karşılar.
- **Düşünce alanında rich text:** Düşünce metni artık kitap sayfasındaki gibi kalın (B), italik (İ), altı çizili (U) ve vurgu ile biçimlenebilir; kayıtta HTML olarak saklanır ve kitap.php’deki alıntı/düşünce görünümü ile uyumludur.

### 25.02.2026
- Oku.php sayfasında okunmuş kitaplar için sayaç özelliği kapatıldı. Düşünce ve alıntı girilebilmesi sağlandı. 

## ✨ Özellikler

- **Kitaplar:** Kitap ekleme, düzenleme, kapak yükleme, durum (Okunacak / Okunuyor / Bitti / Yarım Bırakıldı), sayfa ve ilerleme takibi
- **Raflar (etiketler):** Kitapları raflara atayarak gruplama; rafa göre listeleme
- **Okuma seansları:** Kronometre ile süre ve sayfa takibi, günlük gruplu seans listesi, tarih ve kitap filtresi
- **Alıntılar ve düşünceler:** Kitap sayfasında rich text alıntı (kalın, italik, vurgu, sayfa aralığı, opsiyonel fotoğraf); alıntıya bağlı veya kitaba özel düşünce; kronolojik akış
- **Gemini ile alıntı kaydı:** Okuma sayfasında (mobil) “Alıntı Ekle” ile sayfa fotoğrafı çekilip (opsiyonel kırpma ile) yüklenir; kayıt sadece fotoğraf olarak oluşur, sayaç etkilenmez. Masaüstünde kitap sayfasında bu alıntıyı düzenlerken solda fotoğraf, “Gemini ile metne çevir” butonu ile Google Gemini API kullanılarak görselden metin (OCR) ve sayfa numarası çıkarılır; metin alanına ve sayfa alanına yazılır, kullanıcı kontrol edip kaydeder. İşlenmemiş (henüz metin eklenmemiş) foto-alıntılar listede tepede gösterilir; kaydedildikten sonra sayfa sırasına göre yer alır.
- **Yazdırma (PDF):** Alıntılar ve düşünceleri A4 formatında yazdırma; kapak, kitap adı, yazar ve sayfa bilgisi; çok sayfalı çıktı ve sayfa numarası
- **Zaman dilimi:** Tüm tarih/saat değerleri Türkiye saati (UTC+3) ile tutarlı

Arayüz Türkçe’dir.

---

## 🛠 Teknoloji

- **Backend:** PHP (vanilla, framework yok)
- **Veritabanı:** MySQL (PDO, prepared statements)
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Karakter seti:** UTF-8 (utf8mb4)

---

## 📋 Gereksinimler

- PHP 7.4+ (8.x önerilir)
- MySQL 5.7+ / MariaDB 10.2+
- Web sunucusu (Apache/Nginx) veya yerel ortam (XAMPP, MAMP, Laravel Herd vb.)
- `config/` ve `assets/uploads/`, `alintilar/uploads/` dizinleri yazılabilir olmalı

---

## 🚀 Kurulum

### 1. Projeyi indirin

```bash
git clone https://github.com/emrahsongur/readingapp.git
cd reading
```

### 2. Veritabanını oluşturun

MySQL’de `reading_app` adında bir veritabanı oluşturun ve karakter setini `utf8mb4` yapın. Ardından şemayı ve gerekli tabloları import edin:

- Ana şema: `reading_app.sql` veya `reading_app_database_schema.sql` (proje kökünde veya dokümantasyonda belirtilen dosyalar)
- Ek migration’lar: `migrations/add_kitap_sayfa_aralik.sql`, `migrations/add_alintilar_dusunceler.sql`

### 3. Yapılandırma

`config/` klasöründe `config.php` dosyası yoksa önce örnek dosyayı kopyalayın:

```bash
cp config/config.sample.php config/config.php
```

`config/config.php` içinde veritabanı bilgilerini düzenleyin:

- `$db_host` — Veritabanı sunucusu (örn. `localhost` veya `mysql`)
- `$db_name` — Veritabanı adı (örn. `reading_app`)
- `$db_user` — Veritabanı kullanıcı adı
- `$db_pass` — Veritabanı şifresi

Zaman dilimi ve oturum süresi (8 saat) bu dosyada tanımlıdır; gerekirse değiştirebilirsiniz.

### 4. Dizin izinleri

Sunucunun aşağıdaki klasörlere yazabilmesi gerekir:

- `assets/uploads/` — Kitap kapakları
- `alintilar/uploads/` — Alıntı sayfa fotoğrafları

Örnek (Linux/macOS):

```bash
chmod 755 assets/uploads alintilar/uploads
# veya sunucu kullanıcısına göre: chown www-data:www-data assets/uploads alintilar/uploads
```

### 5. Gemini API anahtarı (isteğe bağlı)

Alıntı fotoğraflarını metne çevirmek (OCR) için Google Gemini API kullanılır. Anahtar zorunlu değildir; kullanmak isterseniz:

1. [Google AI Studio](https://aistudio.google.com/app/apikey) adresine gidin, Google hesabınızla giriş yapın.
2. “Create API key” ile yeni bir anahtar oluşturun (ücretsiz kotası vardır).
3. Uygulamada üst menüden **Ayarlar** sayfasına girin, “Gemini API anahtarı” alanına anahtarınızı yapıştırıp **Kaydet**’e tıklayın.

Anahtar yalnızca sizin kullanıcı hesabınızda saklanır; alıntı düzenlerken “Gemini ile metne çevir” butonu bu anahtarla çalışır.

### 6. İlk kullanıcı

İlk kullanıcıyı veritabanına manuel eklemeniz gerekir. `users` tablosuna bir kayıt ekleyin; şifreyi `password_hash('seçtiğiniz_şifre', PASSWORD_DEFAULT)` ile üretip `pass` alanına yazın.

---

## 📁 Proje yapısı (özet)

```
├── config/
│   ├── config.php          # Veritabanı ve oturum ayarları (git’e eklenmez)
│   └── config.sample.php   # Örnek yapılandırma
├── assets/uploads/         # Kitap kapakları
├── alintilar/
│   ├── bootstrap.php       # Alıntı/düşünce CRUD
│   └── uploads/            # Alıntı fotoğrafları
├── migrations/             # Veritabanı migration SQL dosyaları
├── index.php               # Ana sayfa (kitap kartları)
├── kitap.php               # Kitap ekleme/düzenleme, alıntılar, düşünceler, PDF
├── kitaplar.php            # Tüm kitaplar listesi
├── raf.php, raflar.php     # Raflar ve rafa göre kitaplar
├── oku.php                 # Okuma seansı (kronometre, mobil alıntı foto yükleme)
├── okumalar.php            # Seans listesi
├── ayarlar.php             # Kullanıcı ayarları (Gemini API anahtarı)
├── gemini_ocr.php          # Alıntı fotoğrafı → metin (Gemini API)
├── login.php, logout.php
└── ping.php                # Oturum canlı tutma (heartbeat)
```

---

## 📄 Lisans

Bu proje kişisel kullanım ve eğitim amaçlı paylaşılmaktadır. Kullanım koşulları proje sahibine aittir.
