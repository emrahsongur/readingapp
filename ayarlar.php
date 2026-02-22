<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$basari_mesaji = '';
$hata_mesaji = '';

// Mevcut Gemini anahtarını oku
$stmt = $pdo->prepare("SELECT gemini_api_key FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$mevcut_gemini_key = $row ? ($row['gemini_api_key'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gemini_api_key = trim($_POST['gemini_api_key'] ?? '');
    $anahtari_sil = !empty($_POST['anahtari_sil']);
    try {
        if ($anahtari_sil) {
            $pdo->prepare("UPDATE users SET gemini_api_key = NULL WHERE id = ?")->execute([$user_id]);
            $mevcut_gemini_key = '';
            $basari_mesaji = 'API anahtarı silindi.';
        } elseif ($gemini_api_key !== '') {
            $pdo->prepare("UPDATE users SET gemini_api_key = ? WHERE id = ?")->execute([$gemini_api_key, $user_id]);
            $mevcut_gemini_key = $gemini_api_key;
            $basari_mesaji = 'Ayarlar kaydedildi.';
        } else {
            $basari_mesaji = 'Değişiklik yapılmadı.';
        }
    } catch (PDOException $e) {
        $hata_mesaji = 'Kayıt hatası: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ayarlar - Reading App</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f9fafb; margin: 0; padding: 0; color: #1f2937; }
        .navbar {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 0 rgba(0,0,0,0.04);
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .navbar h1 { margin: 0; font-size: 1.35rem; font-weight: 700; }
        .navbar h1 a { color: #1e40af; text-decoration: none; }
        .navbar h1 a:hover { color: #2563eb; }
        .user-info { display: flex; align-items: center; gap: 0.5rem; }
        .user-info a {
            padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.9rem; font-weight: 500;
            text-decoration: none; color: #475569;
        }
        .user-info a:hover { background-color: #f1f5f9; color: #1e293b; }
        .user-info .nav-btn-primary { background-color: #2563eb; color: white !important; }
        .user-info .nav-btn-secondary { background-color: #10b981; color: white !important; }
        .user-info .btn-logout { color: #dc2626; }
        .container { max-width: 560px; margin: 2rem auto; padding: 1.5rem; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.08); }
        h2 { margin: 0 0 1rem 0; color: #1e293b; font-size: 1.25rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.35rem; color: #4b5563; font-size: 0.9rem; }
        .form-group input[type="password"], .form-group input[type="text"] {
            width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px;
            box-sizing: border-box; font-size: 0.95rem; font-family: inherit;
        }
        .form-group .hint { font-size: 0.8rem; color: #6b7280; margin-top: 0.35rem; }
        .form-group .hint a { color: #2563eb; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-success { background-color: #ecfdf5; color: #065f46; }
        .alert-error { background-color: #fef2f2; color: #b91c1c; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .btn-primary { background-color: #2563eb; color: white; }
        .btn-primary:hover { background-color: #1d4ed8; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1><a href="index.php">Reading App</a></h1>
    <div class="user-info">
        <a href="kitaplar.php">Kitaplar</a>
        <a href="raflar.php">Raflar</a>
        <a href="okumalar.php">Okumalar</a>
        <a href="kitap.php" class="nav-btn-primary">+ Kitap Ekle</a>
        <a href="raf.php" class="nav-btn-secondary">+ Raf Ekle</a>
        <a href="ayarlar.php">Ayarlar</a>
        <a href="logout.php" class="btn-logout" title="<?= htmlspecialchars($_SESSION['ad_soyad']) ?>">Çıkış Yap</a>
    </div>
</nav>

<div class="container">
    <h2>Ayarlar</h2>

    <?php if ($basari_mesaji): ?>
        <div class="alert alert-success"><?= htmlspecialchars($basari_mesaji) ?></div>
    <?php endif; ?>
    <?php if ($hata_mesaji): ?>
        <div class="alert alert-error"><?= htmlspecialchars($hata_mesaji) ?></div>
    <?php endif; ?>

    <form method="post" action="ayarlar.php">
        <div class="form-group">
            <label for="gemini_api_key">Gemini API anahtarı</label>
            <input type="password" id="gemini_api_key" name="gemini_api_key" value="" placeholder="<?= $mevcut_gemini_key !== '' ? 'Mevcut anahtar kayıtlı (değiştirmek için yeni anahtar yapıştırın)' : 'API anahtarınızı buraya yapıştırın' ?>" autocomplete="off">
            <?php if ($mevcut_gemini_key !== ''): ?>
            <p class="form-group" style="margin-top: 0.5rem;">
                <label><input type="checkbox" name="anahtari_sil" value="1"> Mevcut API anahtarını sil</label>
            </p>
            <?php endif; ?>
            <p class="hint">Alıntı fotoğraflarını metne çevirmek (OCR) için kullanılır. <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a> üzerinden ücretsiz API anahtarı alabilirsiniz. Yeni anahtar yapıştırıp Kaydet’e basın.</p>
        </div>
        <button type="submit" class="btn btn-primary">Kaydet</button>
    </form>
</div>

</body>
</html>
