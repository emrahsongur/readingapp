<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

function sure_format_ssddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d:%02d', (int) floor($s / 3600), (int) floor(fmod($s / 60, 60)), $s % 60);
}

// Düşünce kaydet (Sesli: sayfa null)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dusunce_kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    if ($book_id < 1) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz kitap.']);
        exit;
    }
    require_once __DIR__ . '/alintilar/bootstrap.php';
    if (!kitap_kullaniciya_ait($pdo, $book_id, $user_id)) {
        echo json_encode(['ok' => false, 'error' => 'Kitap bulunamadı veya yetkiniz yok.']);
        exit;
    }
    $dusunce_metin = isset($_POST['dusunce']) ? trim((string)$_POST['dusunce']) : '';
    $err = dusunce_ekle($pdo, $user_id, $book_id, $dusunce_metin, null, null, null);
    if ($err !== null) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Alıntı foto (Sesli: sayfa null)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'alinti_foto_upload') {
    header('Content-Type: application/json; charset=utf-8');
    $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    if ($book_id < 1) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz kitap.']);
        exit;
    }
    require_once __DIR__ . '/alintilar/bootstrap.php';
    if (!kitap_kullaniciya_ait($pdo, $book_id, $user_id)) {
        echo json_encode(['ok' => false, 'error' => 'Kitap bulunamadı veya yetkiniz yok.']);
        exit;
    }
    if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Fotoğraf yüklenemedi.']);
        exit;
    }
    $err = alinti_ekle($pdo, $user_id, $book_id, '', null, null, $_FILES['foto']);
    if ($err !== null) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true, 'alinti_id' => (int)$pdo->lastInsertId()]);
    exit;
}

// Sesli konum kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_position') {
    $book_id = (int)$_POST['book_id'];
    $stmtTip = $pdo->prepare("SELECT kitap_tipi_id, sesli_toplam_saniye FROM kitaplar WHERE id = ? AND user_id = ?");
    $stmtTip->execute([$book_id, $user_id]);
    $tipRow = $stmtTip->fetch(PDO::FETCH_ASSOC);
    if (!$tipRow || (int)($tipRow['kitap_tipi_id'] ?? 0) !== 3) {
        header("Location: index.php");
        exit;
    }
    $sh = (int)($_POST['konum_saat'] ?? 0);
    $sd = (int)($_POST['konum_dakika'] ?? 0);
    $ss = (int)($_POST['konum_saniye'] ?? 0);
    $bitis_sure_saniye = $sh * 3600 + $sd * 60 + $ss;

    $stmtSon = $pdo->prepare("SELECT bitis_sure_saniye FROM okumalar WHERE book_id = ? AND user_id = ? ORDER BY bitis DESC LIMIT 1");
    $stmtSon->execute([$book_id, $user_id]);
    $son = $stmtSon->fetch();
    $baslama_sure_saniye = $son && $son['bitis_sure_saniye'] !== null && $son['bitis_sure_saniye'] !== '' ? (int)$son['bitis_sure_saniye'] : 0;

    $now = date('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("INSERT INTO okumalar (user_id, book_id, baslama, bitis, sure_saniye, baslama_sure_saniye, bitis_sure_saniye, baslama_sayfasi, bitis_sayfasi) VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, NULL)");
        $stmt->execute([$user_id, $book_id, $now, $now, $baslama_sure_saniye, $bitis_sure_saniye]);

        $pdo->prepare("UPDATE kitaplar SET durum_id = 2 WHERE id = ? AND durum_id = 1")->execute([$book_id]);

        $sesli_toplam = (int)($tipRow['sesli_toplam_saniye'] ?? 0);
        if ($sesli_toplam > 0 && $bitis_sure_saniye >= $sesli_toplam) {
            $pdo->prepare("UPDATE kitaplar SET durum_id = 3 WHERE id = ? AND user_id = ?")->execute([$book_id, $user_id]);
        }

        header("Location: index.php");
        exit;
    } catch (PDOException $e) {
        die("Kayıt hatası: " . $e->getMessage());
    }
}

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$book_id = (int)$_GET['id'];
$stmtKitap = $pdo->prepare("SELECT * FROM kitaplar WHERE id = :id AND user_id = :user_id");
$stmtKitap->execute(['id' => $book_id, 'user_id' => $user_id]);
$kitap = $stmtKitap->fetch();

if (!$kitap) {
    die("Kitap bulunamadı veya yetkiniz yok.");
}

$kitap_tipi_id = (int)($kitap['kitap_tipi_id'] ?? 1);
if ($kitap_tipi_id !== 3) {
    header("Location: kitap.php?id=" . $book_id);
    exit;
}

$sesli_toplam_saniye = (int)($kitap['sesli_toplam_saniye'] ?? 0);
if ($sesli_toplam_saniye < 1) {
    die("Bu sesli kitabın toplam süresi tanımlı değil. Lütfen kitabı düzenleyip toplam süreyi girin.");
}

$stmtSon = $pdo->prepare("SELECT bitis_sure_saniye FROM okumalar WHERE book_id = ? AND user_id = ? ORDER BY bitis DESC LIMIT 1");
$stmtSon->execute([$book_id, $user_id]);
$son_konum = $stmtSon->fetch();
$mevcut_konum_saniye = $son_konum && $son_konum['bitis_sure_saniye'] !== null && $son_konum['bitis_sure_saniye'] !== '' ? (int)$son_konum['bitis_sure_saniye'] : 0;
$yuzde_dinlendi = $sesli_toplam_saniye > 0 ? min(100, (int) round($mevcut_konum_saniye / $sesli_toplam_saniye * 100)) : 0;

$mevcut_saat = (int) floor($mevcut_konum_saniye / 3600);
$mevcut_dakika = (int) floor(($mevcut_konum_saniye % 3600) / 60);
$mevcut_saniye = (int) ($mevcut_konum_saniye % 60);

$kitap_bitti = (int)($kitap['durum_id'] ?? 0) === 3;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesli Kitap - <?= htmlspecialchars($kitap['baslik']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #111827; color: #f3f4f6; margin: 0; padding: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 480px; margin: 0 auto; padding: 1.5rem; flex: 1; }
        .top-bar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .top-bar-cover { width: 64px; height: 96px; object-fit: cover; border-radius: 8px; background: #374151; }
        .top-bar h1 { margin: 0; font-size: 1.25rem; }
        .top-bar h1 a { color: #60a5fa; text-decoration: none; }
        .top-bar .meta { font-size: 0.9rem; color: #9ca3af; margin-top: 0.25rem; }
        .progress-wrap { background: #374151; border-radius: 8px; height: 12px; overflow: hidden; margin: 1rem 0; }
        .progress-fill { height: 100%; background: #10b981; border-radius: 8px; transition: width 0.2s; }
        .form-card { background: rgba(55, 65, 81, 0.8); border-radius: 12px; padding: 1.5rem; margin: 1rem 0; }
        .form-card label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-card .konum-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .form-card .konum-row input { width: 4.5rem; padding: 0.75rem; border: 2px solid #4b5563; border-radius: 8px; font-size: 1.1rem; text-align: center; background: #1f2937; color: #fff; }
        .btn { display: inline-block; padding: 1rem 1.5rem; border: none; border-radius: 8px; font-size: 1rem; font-weight: bold; cursor: pointer; text-decoration: none; text-align: center; }
        .btn-primary { background: #10b981; color: white; }
        .btn-primary:hover { background: #059669; }
        .btn-secondary { background: #4b5563; color: white; margin-top: 0.5rem; }
        .btn-secondary:hover { background: #6b7280; }
        .kitap-bitti-msg { text-align: center; padding: 1.5rem; color: #9ca3af; font-size: 1.1rem; }
        .yuzde-badge { display: inline-block; background: #374151; padding: 0.5rem 1rem; border-radius: 8px; font-weight: bold; color: #10b981; margin-top: 0.5rem; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); justify-content: center; align-items: center; z-index: 1000; }
        .modal-overlay.show { display: flex; }
        .modal { background: white; color: #1f2937; padding: 2rem; border-radius: 12px; width: 90%; max-width: 400px; }
        .modal h3 { margin-top: 0; color: #2563eb; }
        .modal .form-group { margin-bottom: 1rem; }
        .modal .form-group label { display: block; font-weight: bold; margin-bottom: 0.35rem; }
        .modal .form-group textarea { width: 100%; padding: 0.75rem; border: 2px solid #d1d5db; border-radius: 8px; min-height: 80px; resize: vertical; box-sizing: border-box; }
        .modal-buttons { display: flex; gap: 0.75rem; margin-top: 1rem; }
        .modal .btn-close { background: #d1d5db; color: #1f2937; }
        .modal .btn-save { background: #3b82f6; color: white; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <?php if (!empty($kitap['kapak'])): ?>
            <img src="assets/uploads/<?= htmlspecialchars($kitap['kapak']) ?>" class="top-bar-cover" alt="">
        <?php else: ?>
            <div class="top-bar-cover"></div>
        <?php endif; ?>
        <div>
            <h1><a href="kitap.php?id=<?= (int)$book_id ?>"><?= htmlspecialchars($kitap['baslik']) ?></a></h1>
            <div class="meta"><?= htmlspecialchars($kitap['yazar']) ?></div>
            <div class="meta">Toplam süre: <?= sure_format_ssddss($sesli_toplam_saniye) ?></div>
        </div>
    </div>

    <?php if ($kitap_bitti): ?>
        <div class="kitap-bitti-msg">Bu kitap bitti. Sadece alıntı ve düşünce ekleyebilirsiniz.</div>
        <div class="form-card">
            <button type="button" class="btn btn-primary" id="alintiEkleBtn" style="width:100%;">📷 Alıntı Ekle</button>
            <button type="button" class="btn btn-primary" id="dusunceEkleBtn" style="width:100%; margin-top:0.5rem;">💭 Düşünce Ekle</button>
        </div>
        <a href="index.php" class="btn btn-secondary" style="display:block;">Ana Sayfa</a>
    <?php else: ?>
        <div class="progress-wrap" title="%<?= $yuzde_dinlendi ?> dinlendi">
            <div class="progress-fill" style="width: <?= $yuzde_dinlendi ?>%;"></div>
        </div>
        <p>Toplam sürenin <span class="yuzde-badge">%<?= $yuzde_dinlendi ?></span> dinlendi.</p>

        <form method="post" action="dinle.php" class="form-card">
            <input type="hidden" name="action" value="save_position">
            <input type="hidden" name="book_id" value="<?= $book_id ?>">
            <label>Dinlediğim konum (ss:dd:ss)</label>
            <div class="konum-row">
                <input type="number" name="konum_saat" id="konum_saat" value="<?= $mevcut_saat ?>" min="0" max="999" placeholder="Saat">
                <span>:</span>
                <input type="number" name="konum_dakika" id="konum_dakika" value="<?= $mevcut_dakika ?>" min="0" max="59" placeholder="Dk">
                <span>:</span>
                <input type="number" name="konum_saniye" id="konum_saniye" value="<?= $mevcut_saniye ?>" min="0" max="59" placeholder="Sn">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1rem;">Konumu Kaydet</button>
        </form>

        <div class="form-card">
            <button type="button" class="btn btn-primary" id="alintiEkleBtn" style="width:100%;">📷 Alıntı Ekle</button>
            <button type="button" class="btn btn-primary" id="dusunceEkleBtn" style="width:100%; margin-top:0.5rem;">💭 Düşünce Ekle</button>
        </div>
        <a href="index.php" class="btn btn-secondary" style="display:block;">Ana Sayfa</a>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="alintiModal">
    <div class="modal">
        <h3>Alıntı – Fotoğraf</h3>
        <input type="file" id="alintiFileInput" accept="image/*" capture="environment" style="display:none;">
        <div id="alintiPreviewWrap" style="display:none; margin:1rem 0;"><img id="alintiPreviewImg" alt="" style="max-width:100%; max-height:200px;"></div>
        <div class="modal-buttons">
            <button type="button" class="btn btn-close" id="alintiSelectBtn">Dosya Seç</button>
            <button type="button" class="btn btn-close" id="alintiModalCloseBtn">Kapat</button>
            <button type="button" class="btn btn-save" id="alintiUploadBtn" style="display:none;">Yükle</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="dusunceModal">
    <div class="modal">
        <h3>Düşünce Ekle</h3>
        <div class="form-group">
            <label>Düşünceniz</label>
            <textarea id="dusunceEditor" placeholder="Düşüncenizi yazın..."></textarea>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn btn-close" id="dusunceModalCloseBtn">Kapat</button>
            <button type="button" class="btn btn-save" id="dusunceKaydetBtn">Kaydet</button>
        </div>
    </div>
</div>

<script>
(function() {
    const bookId = <?= (int)$book_id ?>;

    document.getElementById('alintiSelectBtn').addEventListener('click', function() {
        document.getElementById('alintiFileInput').click();
    });
    document.getElementById('alintiFileInput').addEventListener('change', function() {
        const file = this.files && this.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        document.getElementById('alintiPreviewImg').src = url;
        document.getElementById('alintiPreviewWrap').style.display = 'block';
        document.getElementById('alintiUploadBtn').style.display = 'inline-block';
    });
    document.getElementById('alintiModalCloseBtn').addEventListener('click', function() {
        document.getElementById('alintiModal').classList.remove('show');
    });
    document.getElementById('alintiUploadBtn').addEventListener('click', function() {
        const file = document.getElementById('alintiFileInput').files && document.getElementById('alintiFileInput').files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'alinti_foto_upload');
        fd.append('book_id', String(bookId));
        fd.append('foto', file, file.name || 'alinti.jpg');
        fetch('dinle.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    document.getElementById('alintiModal').classList.remove('show');
                    window.location.reload();
                } else alert(data.error || 'Yükleme başarısız.');
            })
            .catch(function() { alert('Yükleme başarısız.'); });
    });

    if (document.getElementById('alintiEkleBtn')) {
        document.getElementById('alintiEkleBtn').addEventListener('click', function() {
            document.getElementById('alintiFileInput').value = '';
            document.getElementById('alintiPreviewWrap').style.display = 'none';
            document.getElementById('alintiUploadBtn').style.display = 'none';
            document.getElementById('alintiModal').classList.add('show');
        });
    }

    document.getElementById('dusunceModalCloseBtn').addEventListener('click', function() {
        document.getElementById('dusunceModal').classList.remove('show');
    });
    document.getElementById('dusunceKaydetBtn').addEventListener('click', function() {
        const metin = document.getElementById('dusunceEditor').value.trim();
        if (metin === '') { alert('Lütfen düşüncenizi yazın.'); return; }
        const fd = new FormData();
        fd.append('action', 'dusunce_kaydet');
        fd.append('book_id', String(bookId));
        fd.append('dusunce', metin);
        fetch('dinle.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    document.getElementById('dusunceModal').classList.remove('show');
                    document.getElementById('dusunceEditor').value = '';
                    window.location.reload();
                } else alert(data.error || 'Kayıt başarısız.');
            })
            .catch(function() { alert('Kayıt başarısız.'); });
    });

    if (document.getElementById('dusunceEkleBtn')) {
        document.getElementById('dusunceEkleBtn').addEventListener('click', function() {
            document.getElementById('dusunceEditor').value = '';
            document.getElementById('dusunceModal').classList.add('show');
        });
    }
})();
</script>
</body>
</html>
