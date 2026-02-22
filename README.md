# 📚 Reading App

Kişisel okuma takip web uygulaması. Okuduğunuz kitapları, okuma seanslarını, rafları (etiketleri) ve kitap başına alıntı ile düşüncelerinizi tek yerden yönetin.

---
## Geliştirmeler
### 22.02.2026
- Kitapların kendine ait sayfasında düşünceler, alıntıların listesi tarih ve sayfa numarasına göre düzenlendi. Sayfaya içindekiler bölümü kondu. 
- Okuma süresinin ölçüldüğü sayfada estetik geliştirmeler yapılıp tahmini bitiş süresi ve kitap için link eklendi. 

## ✨ Özellikler

- **Kitaplar:** Kitap ekleme, düzenleme, kapak yükleme, durum (Okunacak / Okunuyor / Bitti / Yarım Bırakıldı), sayfa ve ilerleme takibi
- **Raflar (etiketler):** Kitapları raflara atayarak gruplama; rafa göre listeleme
- **Okuma seansları:** Kronometre ile süre ve sayfa takibi, günlük gruplu seans listesi, tarih ve kitap filtresi
- **Alıntılar ve düşünceler:** Kitap sayfasında rich text alıntı (kalın, italik, vurgu, sayfa aralığı, opsiyonel fotoğraf); alıntıya bağlı veya kitaba özel düşünce; kronolojik akış
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

### 5. İlk kullanıcı

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
├── oku.php                 # Okuma seansı (kronometre)
├── okumalar.php            # Seans listesi
├── login.php, logout.php
└── ping.php                # Oturum canlı tutma (heartbeat)
```

---

## 📄 Lisans

Bu proje kişisel kullanım ve eğitim amaçlı paylaşılmaktadır. Kullanım koşulları proje sahibine aittir.
