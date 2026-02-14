<?php
// 1. OTURUM (SESSION) AYARLARI
// Session süresini 8 saat (28800 saniye) olarak ayarlıyoruz.
// Bu ayarların session_start() fonksiyonundan ÖNCE yapılması zorunludur.
$session_suresi = 8 * 60 * 60; // 8 saat

ini_set('session.gc_maxlifetime', $session_suresi);
session_set_cookie_params($session_suresi);

// Session'ı başlatıyoruz
session_start();

// 2. ZAMAN DİLİMİ AYARI
// Okuma seanslarının ve tarih damgalarının doğru kaydedilmesi için Türkiye saati.
date_default_timezone_set('Europe/Istanbul');

// 3. VERİTABANI BAĞLANTI AYARLARI
$db_host = 'mysql';
$db_name = 'VERİTABANI_ADI';
$db_user = 'VERİTABANI_KULLANICI_ADI';      // Kendi veritabanı kullanıcı adını yazmalısın (XAMPP/MAMP için genelde root'tur)
$db_pass = 'VERİTABANI_ŞİFRESİ';          // Kendi veritabanı şifreni yazmalısın (XAMPP için genelde boştur)

try {
    // PDO ile güvenli veritabanı bağlantısı oluşturuyoruz (utf8mb4 karakter seti ile)
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    
    // Hata modunu Exception (İstisna) fırlatacak şekilde ayarlıyoruz ki hataları yakalayabilelim
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verileri varsayılan olarak İlişkisel Dizi (Associative Array) olarak çekmesini sağlıyoruz
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Veritabanından dönen TIMESTAMP değerleri Türkiye saatine (UTC+3) göre gelsin
    $pdo->exec("SET time_zone = '+03:00'");

} catch (PDOException $e) {
    // Veritabanına bağlanılamazsa çalışmayı durdur ve hata mesajını göster
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// Güvenlik: Session çalınmasına (Session Hijacking) karşı basit bir kontrol
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > $session_suresi) {
    // Eğer 8 saat dolmuşsa session'ı yenile
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?>