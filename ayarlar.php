<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$is_admin = !empty($_SESSION['admin']);
$basari_mesaji = '';
$hata_mesaji = '';

// Kullanıcıya ait tüm gösterilebilir alanları çek (şifre hariç)
$stmtUser = $pdo->prepare("SELECT id, username, ad_soyad, admin, gemini_api_key FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$mevcut = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$mevcut) {
    header("Location: login.php");
    exit;
}

$username = $mevcut['username'];
$ad_soyad = $mevcut['ad_soyad'];
$mevcut_gemini_key = $mevcut['gemini_api_key'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'profil') {
        // Ad soyad + isteğe bağlı Gemini
        $ad_soyad_yeni = trim($_POST['ad_soyad'] ?? '');
        $gemini_api_key = trim($_POST['gemini_api_key'] ?? '');
        $anahtari_sil = !empty($_POST['anahtari_sil']);
        if ($ad_soyad_yeni === '') {
            $hata_mesaji = 'Ad soyad boş bırakılamaz.';
        } else {
            try {
                if ($anahtari_sil) {
                    $pdo->prepare("UPDATE users SET ad_soyad = ?, gemini_api_key = NULL WHERE id = ?")->execute([$ad_soyad_yeni, $user_id]);
                    $mevcut_gemini_key = '';
                } elseif ($gemini_api_key !== '') {
                    $pdo->prepare("UPDATE users SET ad_soyad = ?, gemini_api_key = ? WHERE id = ?")->execute([$ad_soyad_yeni, $gemini_api_key, $user_id]);
                    $mevcut_gemini_key = $gemini_api_key;
                } else {
                    $pdo->prepare("UPDATE users SET ad_soyad = ? WHERE id = ?")->execute([$ad_soyad_yeni, $user_id]);
                }
                $ad_soyad = $ad_soyad_yeni;
                $_SESSION['ad_soyad'] = $ad_soyad;
                $basari_mesaji = 'Profil ve ayarlar kaydedildi.';
            } catch (PDOException $e) {
                $hata_mesaji = 'Kayıt hatası: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'sifre') {
        $mevcut_sifre = $_POST['mevcut_sifre'] ?? '';
        $yeni_sifre = $_POST['yeni_sifre'] ?? '';
        $yeni_sifre_tekrar = $_POST['yeni_sifre_tekrar'] ?? '';
        if ($mevcut_sifre === '' || $yeni_sifre === '' || $yeni_sifre_tekrar === '') {
            $hata_mesaji = $hata_mesaji ?: 'Şifre alanları boş bırakılamaz.';
        } elseif ($yeni_sifre !== $yeni_sifre_tekrar) {
            $hata_mesaji = $hata_mesaji ?: 'Yeni şifre ve tekrarı eşleşmiyor.';
        } elseif (strlen($yeni_sifre) < 6) {
            $hata_mesaji = $hata_mesaji ?: 'Yeni şifre en az 6 karakter olmalıdır.';
        } else {
            $stmtPass = $pdo->prepare("SELECT pass FROM users WHERE id = ?");
            $stmtPass->execute([$user_id]);
            $row = $stmtPass->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($mevcut_sifre, $row['pass'])) {
                $hata_mesaji = $hata_mesaji ?: 'Mevcut şifre hatalı.';
            } else {
                try {
                    $hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET pass = ? WHERE id = ?")->execute([$hash, $user_id]);
                    $basari_mesaji = $basari_mesaji ?: 'Şifre güncellendi.';
                } catch (PDOException $e) {
                    $hata_mesaji = 'Kayıt hatası: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'kullanici_ekle' && $is_admin) {
        $yeni_username = trim($_POST['yeni_username'] ?? '');
        $yeni_pass = $_POST['yeni_pass'] ?? '';
        $yeni_ad_soyad = trim($_POST['yeni_ad_soyad'] ?? '');
        $yeni_admin = !empty($_POST['yeni_admin']);
        if ($yeni_username === '' || $yeni_pass === '' || $yeni_ad_soyad === '') {
            $hata_mesaji = $hata_mesaji ?: 'Kullanıcı eklerken kullanıcı adı, şifre ve ad soyad zorunludur.';
        } elseif (strlen($yeni_pass) < 6) {
            $hata_mesaji = $hata_mesaji ?: 'Yeni kullanıcı şifresi en az 6 karakter olmalıdır.';
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$yeni_username]);
                if ($check->fetch()) {
                    $hata_mesaji = $hata_mesaji ?: 'Bu kullanıcı adı zaten kullanılıyor.';
                } else {
                    $hash = password_hash($yeni_pass, PASSWORD_DEFAULT);
                    $admin_val = $yeni_admin ? 1 : 0;
                    $pdo->prepare("INSERT INTO users (username, pass, ad_soyad, admin) VALUES (?, ?, ?, ?)")->execute([$yeni_username, $hash, $yeni_ad_soyad, $admin_val]);
                    $basari_mesaji = $basari_mesaji ?: 'Kullanıcı eklendi.';
                }
            } catch (PDOException $e) {
                $hata_mesaji = 'Kayıt hatası: ' . $e->getMessage();
            }
        }
    }
}

// Admin ise tüm kullanıcıları listele
$kullanici_listesi = [];
if ($is_admin) {
    $stmtList = $pdo->query("SELECT id, username, ad_soyad, admin FROM users ORDER BY username ASC");
    $kullanici_listesi = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];
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
        .container { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        h2 { margin: 0 0 1rem 0; color: #1e293b; font-size: 1.25rem; }
        h3 { margin: 0 0 0.75rem 0; color: #374151; font-size: 1.05rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.35rem; color: #4b5563; font-size: 0.9rem; }
        .form-group input[type="password"], .form-group input[type="text"] {
            width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px;
            box-sizing: border-box; font-size: 0.95rem; font-family: inherit;
        }
        .form-group input[readonly] { background: #f3f4f6; color: #6b7280; }
        .form-group .hint { font-size: 0.8rem; color: #6b7280; margin-top: 0.35rem; }
        .form-group .hint a { color: #2563eb; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-success { background-color: #ecfdf5; color: #065f46; }
        .alert-error { background-color: #fef2f2; color: #b91c1c; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .btn-primary { background-color: #2563eb; color: white; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .btn-secondary { background-color: #10b981; color: white; }
        .btn-secondary:hover { background-color: #059669; }
        .users-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .users-table th, .users-table td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        .users-table th { background: #f9fafb; font-weight: 600; color: #4b5563; }
        .users-table tr:hover { background: #f9fafb; }
        .badge-admin { display: inline-block; background: #2563eb; color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
    </style>
</head>
<body>

<?php $nav_current = 'ayarlar'; include __DIR__ . '/includes/nav.php'; ?>

<div class="container">
    <h2>Ayarlar</h2>

    <?php if ($basari_mesaji): ?>
        <div class="alert alert-success"><?= htmlspecialchars($basari_mesaji) ?></div>
    <?php endif; ?>
    <?php if ($hata_mesaji): ?>
        <div class="alert alert-error"><?= htmlspecialchars($hata_mesaji) ?></div>
    <?php endif; ?>

    <!-- Profil bilgileri + Gemini -->
    <div class="card">
        <h3>Hesap bilgileri</h3>
        <form method="post" action="ayarlar.php">
            <input type="hidden" name="action" value="profil">
            <div class="form-group">
                <label for="username">Kullanıcı adı</label>
                <input type="text" id="username" value="<?= htmlspecialchars($username) ?>" readonly>
                <p class="hint">Kullanıcı adı değiştirilemez.</p>
            </div>
            <div class="form-group">
                <label for="ad_soyad">Ad soyad</label>
                <input type="text" id="ad_soyad" name="ad_soyad" value="<?= htmlspecialchars($ad_soyad) ?>" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="gemini_api_key">Gemini API anahtarı</label>
                <input type="password" id="gemini_api_key" name="gemini_api_key" value="" placeholder="<?= $mevcut_gemini_key !== '' ? 'Mevcut anahtar kayıtlı (değiştirmek için yeni anahtar yapıştırın)' : 'API anahtarınızı buraya yapıştırın' ?>" autocomplete="off">
                <?php if ($mevcut_gemini_key !== ''): ?>
                <p class="form-group" style="margin-top: 0.5rem;">
                    <label><input type="checkbox" name="anahtari_sil" value="1"> Mevcut API anahtarını sil</label>
                </p>
                <?php endif; ?>
                <p class="hint">Alıntı fotoğraflarını metne çevirmek (OCR) için. <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a> üzerinden ücretsiz API anahtarı alabilirsiniz.</p>
            </div>
            <button type="submit" class="btn btn-primary">Profil ve API kaydet</button>
        </form>
    </div>

    <!-- Şifre değiştir -->
    <div class="card">
        <h3>Şifre değiştir</h3>
        <form method="post" action="ayarlar.php">
            <input type="hidden" name="action" value="sifre">
            <div class="form-group">
                <label for="mevcut_sifre">Mevcut şifre</label>
                <input type="password" id="mevcut_sifre" name="mevcut_sifre" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="yeni_sifre">Yeni şifre</label>
                <input type="password" id="yeni_sifre" name="yeni_sifre" autocomplete="new-password" minlength="6">
            </div>
            <div class="form-group">
                <label for="yeni_sifre_tekrar">Yeni şifre (tekrar)</label>
                <input type="password" id="yeni_sifre_tekrar" name="yeni_sifre_tekrar" autocomplete="new-password" minlength="6">
            </div>
            <button type="submit" class="btn btn-primary">Şifreyi güncelle</button>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <!-- Kullanıcı listesi -->
    <div class="card">
        <h3>Kullanıcılar</h3>
        <?php if (count($kullanici_listesi) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kullanıcı adı</th>
                        <th>Ad soyad</th>
                        <th>Rol</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kullanici_listesi as $u): ?>
                    <tr>
                        <td><?= (int) $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['ad_soyad']) ?></td>
                        <td><?= !empty($u['admin']) ? '<span class="badge-admin">Admin</span>' : 'Kullanıcı' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p style="color: #6b7280;">Henüz kullanıcı yok.</p>
        <?php endif; ?>
    </div>

    <!-- Kullanıcı ekle (admin) -->
    <div class="card">
        <h3>Kullanıcı ekle</h3>
        <form method="post" action="ayarlar.php">
            <input type="hidden" name="action" value="kullanici_ekle">
            <div class="form-group">
                <label for="yeni_username">Kullanıcı adı</label>
                <input type="text" id="yeni_username" name="yeni_username" required maxlength="50" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="yeni_pass">Şifre</label>
                <input type="password" id="yeni_pass" name="yeni_pass" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="yeni_ad_soyad">Ad soyad</label>
                <input type="text" id="yeni_ad_soyad" name="yeni_ad_soyad" required maxlength="100">
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="yeni_admin" value="1"> Admin yetkisi ver</label>
            </div>
            <button type="submit" class="btn btn-secondary">Kullanıcı ekle</button>
        </form>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
