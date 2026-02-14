<?php
require_once 'config/config.php';

// Güvenlik: Giriş yapılmamışsa yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hata_mesaji = '';
$basari_mesaji = '';

// --- SİLME İŞLEMİ (GET ile delete_id gelirse) ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // Rafın bu kullanıcıya ait olup olmadığını kontrol et
    $stmt = $pdo->prepare("SELECT id FROM raflar WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $delete_id, 'user_id' => $user_id]);
    
    if ($stmt->fetch()) {
        // Veritabanından sil (ON DELETE CASCADE olduğu için kitap_raf tablosundaki ilişkiler de otomatik silinir, kitaplar silinmez!)
        $delStmt = $pdo->prepare("DELETE FROM raflar WHERE id = :id");
        $delStmt->execute(['id' => $delete_id]);
        
        header("Location: raf.php?basari=silindi");
        exit;
    }
}

// Form varsayılan değerleri (Ekleme modu)
$raf_id = 0;
$etiket = '';
$aciklama = '';

// --- DÜZENLEME MODU (GET ile id gelirse mevcut verileri çek) ---
if (isset($_GET['id'])) {
    $raf_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM raflar WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $raf_id, 'user_id' => $user_id]);
    $raf = $stmt->fetch();

    if ($raf) {
        $etiket = $raf['etiket'];
        $aciklama = $raf['aciklama'];
    } else {
        die("Raf bulunamadı veya yetkiniz yok.");
    }
}

// --- FORM GÖNDERME (POST İşlemi - Ekleme veya Güncelleme) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $etiket = trim($_POST['etiket']);
    $aciklama = trim($_POST['aciklama']);
    $raf_id = (int)$_POST['raf_id'];

    if (empty($etiket)) {
        $hata_mesaji = "Raf adı (etiket) zorunludur.";
    } else {
        try {
            if ($raf_id > 0) {
                // GÜNCELLEME
                $stmt = $pdo->prepare("UPDATE raflar SET etiket=?, aciklama=?, degistiren=? WHERE id=? AND user_id=?");
                $stmt->execute([$etiket, $aciklama, $user_id, $raf_id, $user_id]);
                $basari_mesaji = "Raf başarıyla güncellendi.";
            } else {
                // YENİ KAYIT
                $stmt = $pdo->prepare("INSERT INTO raflar (user_id, etiket, aciklama, degistiren) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $etiket, $aciklama, $user_id]);
                $basari_mesaji = "Raf başarıyla eklendi.";
                
                // Formu temizle (yeni ekleme yaptıktan sonra boş form gelsin)
                $etiket = '';
                $aciklama = '';
            }
        } catch (PDOException $e) {
            $hata_mesaji = "Kayıt hatası: " . $e->getMessage();
        }
    }
}

// Bildirim mesajları için GET kontrolü
if (isset($_GET['basari']) && $_GET['basari'] == 'silindi') {
    $basari_mesaji = "Raf başarıyla silindi.";
}

// Kullanıcıya Ait Tüm Rafları Listelemek İçin Çek
$stmtRaflar = $pdo->prepare("
    SELECT r.*, COUNT(kr.kitap_id) as kitap_sayisi 
    FROM raflar r 
    LEFT JOIN kitap_raf kr ON r.id = kr.raf_id 
    WHERE r.user_id = :user_id 
    GROUP BY r.id 
    ORDER BY r.etiket ASC
");
$stmtRaflar->execute(['user_id' => $user_id]);
$raflarListesi = $stmtRaflar->fetchAll();

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raflar (Etiketler) - Reading App</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f9fafb; margin: 0; padding: 2rem; color: #1f2937; }
        .grid-container { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; max-width: 1000px; margin: 0 auto; }
        @media (max-width: 768px) { .grid-container { grid-template-columns: 1fr; } } /* Mobilde alt alta dizer */
        
        .card { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #10b981; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem; }
        
        /* Form Stilleri */
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.5rem; color: #4b5563; }
        input[type="text"], textarea { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; font-size: 1rem; }
        textarea { resize: vertical; min-height: 100px; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-secondary { background-color: #10b981; color: white; }
        .btn-secondary:hover { background-color: #059669; }
        .btn-cancel { background-color: #6b7280; color: white; margin-left: 0.5rem; }
        
        /* Tablo Stilleri */
        table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 1rem; }
        th, td { padding: 1rem; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f3f4f6; font-weight: 600; color: #4b5563; }
        tr:hover { background-color: #f9fafb; }
        .badge { background-color: #e0e7ff; color: #4f46e5; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.85rem; font-weight: bold; }
        .action-links a { margin-right: 0.5rem; text-decoration: none; font-size: 0.9rem; }
        .text-blue { color: #3b82f6; }
        .text-red { color: #ef4444; }
        
        .alert { padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; }
        .alert-error { background-color: #fee2e2; color: #b91c1c; }
        .alert-success { background-color: #d1fae5; color: #065f46; }
        
        .top-nav { margin-bottom: 2rem; }
        .top-nav a { color: #4b5563; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { color: #2563eb; }
    </style>
</head>
<body>

<div class="top-nav">
    <a href="index.php">&larr; Ana Sayfaya Dön</a>
</div>

<div class="grid-container">
    
    <div class="card">
        <h2><?= $raf_id > 0 ? 'Rafı Düzenle' : 'Yeni Raf Ekle' ?></h2>

        <?php if ($hata_mesaji): ?>
            <div class="alert alert-error"><?= $hata_mesaji ?></div>
        <?php endif; ?>
        <?php if ($basari_mesaji): ?>
            <div class="alert alert-success"><?= $basari_mesaji ?></div>
        <?php endif; ?>

        <form method="POST" action="raf.php">
            <input type="hidden" name="raf_id" value="<?= $raf_id ?>">
            
            <div class="form-group">
                <label for="etiket">Raf Adı / Etiket *</label>
                <input type="text" id="etiket" name="etiket" value="<?= htmlspecialchars($etiket) ?>" placeholder="Örn: Bilimkurgu, Audiobook..." required autofocus>
            </div>

            <div class="form-group">
                <label for="aciklama">Açıklama (İsteğe Bağlı)</label>
                <textarea id="aciklama" name="aciklama" placeholder="Bu raf hakkında kısa bir not..."><?= htmlspecialchars($aciklama) ?></textarea>
            </div>

            <button type="submit" class="btn btn-secondary"><?= $raf_id > 0 ? 'Güncelle' : 'Ekle' ?></button>
            
            <?php if ($raf_id > 0): ?>
                <a href="raf.php" class="btn btn-cancel">İptal</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Mevcut Raflarım</h2>
        
        <?php if (count($raflarListesi) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Raf Adı</th>
                        <th>Kitap Sayısı</th>
                        <th style="text-align: right;">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($raflarListesi as $r): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($r['etiket']) ?></strong>
                                <?php if (!empty($r['aciklama'])): ?>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 4px;">
                                        <?= htmlspecialchars($r['aciklama']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge"><?= $r['kitap_sayisi'] ?> Kitap</span>
                            </td>
                            <td style="text-align: right;" class="action-links">
                                <a href="raf.php?id=<?= $r['id'] ?>" class="text-blue">Düzenle</a>
                                <a href="raf.php?delete_id=<?= $r['id'] ?>" class="text-red" onclick="return confirm('Bu rafı silmek istediğinize emin misiniz? (İçindeki kitaplar SİLİNMEZ, sadece etiketleri kalkar.)');">Sil</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 2rem; color: #6b7280;">
                Henüz hiç raf oluşturmadınız. Sol taraftaki formu kullanarak ilk rafınızı ekleyebilirsiniz.
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>