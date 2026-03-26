<?php
require_once 'config/config.php';

if (isset($_GET['ping'])) {
    echo "pong";
    exit;
}

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

// Sesli seans: başlangıç konumu = önceki seansın bitiş konumu (sayfa mantığı); sure_duvar = bitis_zaman - önceki_seans_bitis_zaman
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_session') {
    $book_id = (int)$_POST['book_id'];
    $stmtTip = $pdo->prepare("SELECT kitap_tipi_id, sesli_toplam_saniye FROM kitaplar WHERE id = ? AND user_id = ?");
    $stmtTip->execute([$book_id, $user_id]);
    $tipRow = $stmtTip->fetch(PDO::FETCH_ASSOC);
    if (!$tipRow || (int)($tipRow['kitap_tipi_id'] ?? 0) !== 3) {
        header("Location: index.php");
        exit;
    }

    $stmtOnceki = $pdo->prepare("SELECT bitis_sure_saniye, bitis FROM okumalar WHERE book_id = ? AND user_id = ? ORDER BY bitis DESC, id DESC LIMIT 1");
    $stmtOnceki->execute([$book_id, $user_id]);
    $onceki = $stmtOnceki->fetch(PDO::FETCH_ASSOC);
    $baslama_sure_saniye = 0;
    $onceki_bitis_epoch = null;
    if ($onceki && $onceki['bitis_sure_saniye'] !== null && $onceki['bitis_sure_saniye'] !== '') {
        $baslama_sure_saniye = (int)$onceki['bitis_sure_saniye'];
    }
    if ($onceki && !empty($onceki['bitis'])) {
        $onceki_bitis_epoch = strtotime($onceki['bitis']);
        if ($onceki_bitis_epoch === false) {
            $onceki_bitis_epoch = null;
        }
    }

    $mark_finished = isset($_POST['mark_finished']) && $_POST['mark_finished'] === '1';

    $sh = (int)($_POST['konum_saat'] ?? 0);
    $sd = (int)($_POST['konum_dakika'] ?? 0);
    $ss = (int)($_POST['konum_saniye'] ?? 0);
    $bitis_sure_saniye = $sh * 3600 + $sd * 60 + $ss;

    $sesli_toplam = (int)($tipRow['sesli_toplam_saniye'] ?? 0);
    if ($mark_finished) {
        $tsh = (int)($_POST['toplam_saat'] ?? 0);
        $tsd = (int)($_POST['toplam_dakika'] ?? 0);
        $tss = (int)($_POST['toplam_saniye_sn'] ?? 0);
        $posted_total = $tsh * 3600 + $tsd * 60 + $tss;
        if ($posted_total > 0) {
            $sesli_toplam = $posted_total;
            $pdo->prepare("UPDATE kitaplar SET sesli_toplam_saniye = ? WHERE id = ? AND user_id = ?")->execute([$sesli_toplam, $book_id, $user_id]);
        }
        if ($sesli_toplam < 1) {
            die("Hata: Sesli kitap için toplam süre gerekli.");
        }
        $bitis_sure_saniye = $sesli_toplam;
    }

    if ($bitis_sure_saniye < $baslama_sure_saniye) {
        die("Hata: Bitiş konumu, son kaydın bitiş konumundan küçük olamaz.");
    }

    if ($sesli_toplam > 0 && $bitis_sure_saniye > $sesli_toplam) {
        $bitis_sure_saniye = $sesli_toplam;
    }

    $bitis_zamani = date('Y-m-d H:i:s');
    $simdi_epoch = time();

    if ($onceki_bitis_epoch !== null && $onceki_bitis_epoch <= $simdi_epoch) {
        $baslama_zamani = date('Y-m-d H:i:s', $onceki_bitis_epoch);
        $sure_saniye = max(0, $simdi_epoch - $onceki_bitis_epoch);
    } else {
        $baslama_zamani = $bitis_zamani;
        $sure_saniye = 0;
    }

    if (!$mark_finished && $sure_saniye < 1 && $bitis_sure_saniye <= $baslama_sure_saniye) {
        die("Hata: Yeni kayıt için konumu ilerletin veya en az bir saniye sonra tekrar kaydedin.");
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO okumalar (user_id, book_id, baslama, bitis, sure_saniye, baslama_sure_saniye, bitis_sure_saniye, baslama_sayfasi, bitis_sayfasi) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)");
        $stmt->execute([$user_id, $book_id, $baslama_zamani, $bitis_zamani, $sure_saniye, $baslama_sure_saniye, $bitis_sure_saniye]);

        $pdo->prepare("UPDATE kitaplar SET durum_id = 2 WHERE id = ? AND durum_id = 1")->execute([$book_id]);

        if ($mark_finished) {
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

$stmtSon = $pdo->prepare("SELECT bitis_sure_saniye, bitis FROM okumalar WHERE book_id = ? AND user_id = ? ORDER BY bitis DESC, id DESC LIMIT 1");
$stmtSon->execute([$book_id, $user_id]);
$son_konum = $stmtSon->fetch();
$mevcut_konum_saniye = $son_konum && $son_konum['bitis_sure_saniye'] !== null && $son_konum['bitis_sure_saniye'] !== '' ? (int)$son_konum['bitis_sure_saniye'] : 0;
$son_kayit_zaman_metni = '';
if ($son_konum && !empty($son_konum['bitis'])) {
    $son_kayit_zaman_metni = date('d.m.Y H:i', strtotime($son_konum['bitis']));
}
$yuzde_dinlendi = $sesli_toplam_saniye > 0 ? min(100, (int) round($mevcut_konum_saniye / $sesli_toplam_saniye * 100)) : 0;

$mevcut_saat = (int) floor($mevcut_konum_saniye / 3600);
$mevcut_dakika = (int) floor(($mevcut_konum_saniye % 3600) / 60);
$mevcut_sn = (int) ($mevcut_konum_saniye % 60);

$toplam_saat_pref = (int) floor($sesli_toplam_saniye / 3600);
$toplam_dakika_pref = (int) floor(($sesli_toplam_saniye % 3600) / 60);
$toplam_sn_pref = (int) ($sesli_toplam_saniye % 60);

$kitap_bitti = (int)($kitap['durum_id'] ?? 0) === 3;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sesli Kitap - <?= htmlspecialchars($kitap['baslik']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #111827; color: #f3f4f6; margin: 0; padding: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 480px; margin: 0 auto; padding: 1.5rem; flex: 1; }
        .top-bar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .top-bar-cover { width: 64px; height: 96px; object-fit: cover; border-radius: 8px; background: #374151; }
        .top-bar h1 { margin: 0; font-size: 1.25rem; }
        .top-bar h1 a { color: #60a5fa; text-decoration: none; }
        .top-bar .meta { font-size: 0.9rem; color: #9ca3af; margin-top: 0.25rem; }
        .progress-wrap { background: #374151; border-radius: 8px; height: 12px; overflow: hidden; margin: 1rem 0; }
        .progress-fill { height: 100%; background: #10b981; border-radius: 8px; transition: width 0.2s; }
        .btn { display: inline-block; padding: 1rem 1.25rem; border: none; border-radius: 8px; font-size: 1rem; font-weight: bold; cursor: pointer; text-decoration: none; text-align: center; box-sizing: border-box; }
        .btn-start { background: #10b981; color: white; }
        .btn-secondary { background: #4b5563; color: white; margin-top: 0.5rem; }
        .btn-secondary:hover { background: #6b7280; }
        .btn-finish { background: #059669; color: white; width: 100%; margin-top: 0.75rem; }
        .form-card { background: rgba(55, 65, 81, 0.8); border-radius: 12px; padding: 1.5rem; margin: 1rem 0; }
        .form-card label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        .form-card .hint { font-size: 0.85rem; color: #9ca3af; margin-top: 0.35rem; line-height: 1.4; }
        .konum-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .konum-row input { width: 4.5rem; padding: 0.75rem; border: 2px solid #4b5563; border-radius: 8px; font-size: 1.1rem; text-align: center; background: #1f2937; color: #fff; }
        .toplam-sure-row input { width: 4rem; padding: 0.65rem; border: 2px solid #4b5563; border-radius: 8px; text-align: center; background: #1f2937; color: #fff; font-size: 1rem; }
        .kitap-bitti-msg { text-align: center; padding: 1.5rem; color: #9ca3af; font-size: 1.1rem; }
        .yuzde-badge { display: inline-block; background: #374151; padding: 0.5rem 1rem; border-radius: 8px; font-weight: bold; color: #10b981; margin-top: 0.5rem; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); justify-content: center; align-items: center; z-index: 1000; }
        .modal-overlay.show { display: flex; }
        .modal { background: white; color: #1f2937; padding: 2rem; border-radius: 12px; width: 90%; max-width: 400px; box-sizing: border-box; }
        .modal h3 { margin-top: 0; color: #2563eb; }
        .modal .form-group { margin-bottom: 1rem; }
        .modal .form-group label { display: block; font-weight: bold; margin-bottom: 0.35rem; }
        .modal .form-group textarea { width: 100%; padding: 0.75rem; border: 2px solid #d1d5db; border-radius: 8px; min-height: 80px; resize: vertical; box-sizing: border-box; }
        .modal-buttons { display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }
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
            <div class="meta">Son konum: <?= sure_format_ssddss($mevcut_konum_saniye) ?> · Toplam: <?= sure_format_ssddss($sesli_toplam_saniye) ?></div>
        </div>
    </div>

    <?php if ($kitap_bitti): ?>
        <div class="kitap-bitti-msg">Bu kitap bitti. Sadece alıntı ve düşünce ekleyebilirsiniz.</div>
        <div class="form-card">
            <button type="button" class="btn btn-start" id="alintiEkleBtn" style="width:100%;">📷 Alıntı Ekle</button>
            <button type="button" class="btn btn-start" id="dusunceEkleBtn" style="width:100%; margin-top:0.5rem;">💭 Düşünce Ekle</button>
        </div>
        <a href="index.php" class="btn btn-secondary" style="display:block;">Ana Sayfa</a>
    <?php else: ?>
        <div class="progress-wrap" title="%<?= $yuzde_dinlendi ?> dinlendi">
            <div class="progress-fill" style="width: <?= $yuzde_dinlendi ?>%;"></div>
        </div>
        <p style="text-align:center;">İlerleme: <span class="yuzde-badge">%<?= $yuzde_dinlendi ?></span></p>

        <form method="post" action="dinle.php" class="form-card">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="book_id" value="<?= (int)$book_id ?>">
            <p class="hint" style="margin-top:0;"><strong>Bu seansın başlangıç konumu</strong> (son kayıt): <strong><?= htmlspecialchars(sure_format_ssddss($mevcut_konum_saniye)) ?></strong><?php if ($son_kayit_zaman_metni !== ''): ?> · Son kayıt: <?= htmlspecialchars($son_kayit_zaman_metni) ?><?php endif; ?></p>
            <label>Şu anki konum (saat : dakika : saniye)</label>
            <div class="konum-row">
                <input type="number" name="konum_saat" value="<?= $mevcut_saat ?>" min="0" max="999" required aria-label="Konum saat">
                <span>:</span>
                <input type="number" name="konum_dakika" value="<?= $mevcut_dakika ?>" min="0" max="59" required aria-label="Konum dakika">
                <span>:</span>
                <input type="number" name="konum_saniye" value="<?= $mevcut_sn ?>" min="0" max="59" required aria-label="Konum saniye">
            </div>
            <p class="hint">Seans süresi: şu an ile son kaydın bitiş zamanı farkı (sayaç yok).</p>
            <button type="submit" class="btn btn-start" style="width:100%; margin-top:1rem;">Konumu kaydet</button>
        </form>

        <form method="post" action="dinle.php" class="form-card">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="book_id" value="<?= (int)$book_id ?>">
            <input type="hidden" name="mark_finished" value="1">
            <strong style="color:#e5e7eb;">Kitabı bitirdim</strong>
            <p class="hint">Toplam süreyi onaylayın veya düzeltin; kayıtta konum sona alınır ve kitap &quot;Bitti&quot; işaretlenir.</p>
            <label>Toplam süre (saat : dakika : saniye)</label>
            <div class="toplam-sure-row konum-row">
                <input type="number" name="toplam_saat" value="<?= $toplam_saat_pref ?>" min="0" max="999" required>
                <span>:</span>
                <input type="number" name="toplam_dakika" value="<?= $toplam_dakika_pref ?>" min="0" max="59" required>
                <span>:</span>
                <input type="number" name="toplam_saniye_sn" value="<?= $toplam_sn_pref ?>" min="0" max="59" required>
            </div>
            <button type="submit" class="btn btn-finish">Kitabı bitirdim — kaydet</button>
        </form>

        <div class="form-card">
            <button type="button" class="btn btn-start" id="alintiEkleBtn2" style="width:100%;">📷 Alıntı Ekle</button>
            <button type="button" class="btn btn-start" id="dusunceEkleBtn2" style="width:100%; margin-top:0.5rem;">💭 Düşünce Ekle</button>
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
    const kitapBitti = <?= $kitap_bitti ? 'true' : 'false' ?>;

    if (!kitapBitti) {
        setInterval(function() { fetch('dinle.php?ping=1').catch(function() {}); }, 600000);
    }

    function bindAlintiDusunce(alintiId, dusunceId) {
        const alintiBtn = document.getElementById(alintiId);
        const dusunceBtn = document.getElementById(dusunceId);
        if (alintiBtn) alintiBtn.addEventListener('click', function() {
            document.getElementById('alintiFileInput').value = '';
            document.getElementById('alintiPreviewWrap').style.display = 'none';
            document.getElementById('alintiUploadBtn').style.display = 'none';
            document.getElementById('alintiModal').classList.add('show');
        });
        if (dusunceBtn) dusunceBtn.addEventListener('click', function() {
            document.getElementById('dusunceEditor').value = '';
            document.getElementById('dusunceModal').classList.add('show');
        });
    }
    bindAlintiDusunce('alintiEkleBtn', 'dusunceEkleBtn');
    bindAlintiDusunce('alintiEkleBtn2', 'dusunceEkleBtn2');

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
                    window.location.reload();
                } else alert(data.error || 'Kayıt başarısız.');
            })
            .catch(function() { alert('Kayıt başarısız.'); });
    });
})();
</script>
</body>
</html>
