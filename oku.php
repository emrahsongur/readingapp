<?php
require_once 'config/config.php';

// 1. HEARTBEAT (Kalp Atışı) İŞLEMİ
// Javascript her 10 dakikada bir arka planda bu sayfaya ?ping=1 isteği atarak 8 saatlik session'ı sıfırlar.
if (isset($_GET['ping'])) {
    echo "pong";
    exit;
}

// Güvenlik: Giriş yapılmamışsa yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1b. DÜŞÜNCE KAYDETME (POST, JSON yanıt – kitaba özel düşünce)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dusunce_kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    $dusunce_book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    if ($dusunce_book_id < 1) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz kitap.']);
        exit;
    }
    require_once __DIR__ . '/alintilar/bootstrap.php';
    if (!kitap_kullaniciya_ait($pdo, $dusunce_book_id, $user_id)) {
        echo json_encode(['ok' => false, 'error' => 'Kitap bulunamadı veya yetkiniz yok.']);
        exit;
    }
    $dusunce_metin = isset($_POST['dusunce']) ? trim((string)$_POST['dusunce']) : '';
    $sayfa_bas = isset($_POST['sayfa_baslangic']) && $_POST['sayfa_baslangic'] !== '' ? (int)$_POST['sayfa_baslangic'] : null;
    $sayfa_bit = isset($_POST['sayfa_bitis']) && $_POST['sayfa_bitis'] !== '' ? (int)$_POST['sayfa_bitis'] : null;
    $err = dusunce_ekle($pdo, $user_id, $dusunce_book_id, $dusunce_metin, $sayfa_bas, $sayfa_bit, null);
    if ($err !== null) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// 1c. ALINTI FOTO YÜKLEME (POST, JSON yanıt – modal açıkken sayaç durar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'alinti_foto_upload') {
    header('Content-Type: application/json; charset=utf-8');
    $upload_book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    if ($upload_book_id < 1) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz kitap.']);
        exit;
    }
    require_once __DIR__ . '/alintilar/bootstrap.php';
    if (!kitap_kullaniciya_ait($pdo, $upload_book_id, $user_id)) {
        echo json_encode(['ok' => false, 'error' => 'Kitap bulunamadı veya yetkiniz yok.']);
        exit;
    }
    if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Fotoğraf yüklenemedi.']);
        exit;
    }
    $err = alinti_ekle($pdo, $user_id, $upload_book_id, '', null, null, $_FILES['foto']);
    if ($err !== null) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    $alinti_id = (int) $pdo->lastInsertId();
    echo json_encode(['ok' => true, 'alinti_id' => $alinti_id]);
    exit;
}

// 2. SEANS KAYDETME İŞLEMİ (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_session') {
    $book_id = (int)$_POST['book_id'];
    $stmtTip = $pdo->prepare("SELECT kitap_tipi_id FROM kitaplar WHERE id = ? AND user_id = ?");
    $stmtTip->execute([$book_id, $user_id]);
    $tipRow = $stmtTip->fetch(PDO::FETCH_ASSOC);
    if ($tipRow && (int)($tipRow['kitap_tipi_id'] ?? 1) !== 1) {
        header("Location: kitap.php?id=" . $book_id);
        exit;
    }
    $sure_saniye = (int)$_POST['sure_saniye'];
    $baslama_sayfasi = (int)$_POST['baslama_sayfasi'];
    $bitis_sayfasi = (int)$_POST['bitis_sayfasi'];

    if ($bitis_sayfasi < $baslama_sayfasi) {
        die("Hata: Bitiş sayfası, başlama sayfasından küçük olamaz.");
    }

    try {
        // Başlama zamanını sunucu tarafında güvenli hesapla (Şu anki zaman eksi okunan saniye)
        $bitis_zamani = date('Y-m-d H:i:s');
        $baslama_zamani = date('Y-m-d H:i:s', time() - $sure_saniye);

        $stmt = $pdo->prepare("INSERT INTO okumalar (user_id, book_id, baslama, bitis, sure_saniye, baslama_sayfasi, bitis_sayfasi) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $book_id, $baslama_zamani, $bitis_zamani, $sure_saniye, $baslama_sayfasi, $bitis_sayfasi]);

        // Kitap 'Okunacak' ise 'Okunuyor' (2) durumuna geçir
        $updateDurum = $pdo->prepare("UPDATE kitaplar SET durum_id = 2 WHERE id = ? AND durum_id = 1");
        $updateDurum->execute([$book_id]);

        // Bitiş sayfası kitabın son sayfasına eşitse ve kitap Okunuyor ise 'Bitti' (3) yap
        $stmtBook = $pdo->prepare("SELECT durum_id, COALESCE(NULLIF(bitis_sayfa, 0), sayfa) as eff_bitis FROM kitaplar WHERE id = ? AND user_id = ?");
        $stmtBook->execute([$book_id, $user_id]);
        $bookRow = $stmtBook->fetch(PDO::FETCH_ASSOC);
        if ($bookRow && (int)$bookRow['eff_bitis'] > 0 && (int)$bitis_sayfasi === (int)$bookRow['eff_bitis'] && (int)$bookRow['durum_id'] === 2) {
            $pdo->prepare("UPDATE kitaplar SET durum_id = 3 WHERE id = ? AND user_id = ?")->execute([$book_id, $user_id]);
        }

        header("Location: index.php");
        exit;
    } catch (PDOException $e) {
        die("Kayıt hatası: " . $e->getMessage());
    }
}

// 3. KİTAP VE SEANS BİLGİLERİNİ ÇEKME (GET)
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$book_id = (int)$_GET['id'];

// Kitabın kullanıcıya ait olup olmadığını kontrol et
$stmtKitap = $pdo->prepare("SELECT * FROM kitaplar WHERE id = :id AND user_id = :user_id");
$stmtKitap->execute(['id' => $book_id, 'user_id' => $user_id]);
$kitap = $stmtKitap->fetch();

if (!$kitap) {
    die("Kitap bulunamadı veya yetkiniz yok.");
}

// Bu sayfa sadece basılı kitaplar içindir; E-kitap ve Sesli için eoku.php / dinle.php kullanılır
$kitap_tipi_id = (int)($kitap['kitap_tipi_id'] ?? 1);
if ($kitap_tipi_id !== 1) {
    header("Location: kitap.php?id=" . $book_id);
    exit;
}

// Bu kitap için son okuma seansını bul (Başlama sayfasını belirlemek için)
$stmtSonSeans = $pdo->prepare("SELECT bitis_sayfasi FROM okumalar WHERE book_id = :book_id AND user_id = :user_id ORDER BY bitis DESC LIMIT 1");
$stmtSonSeans->execute(['book_id' => $book_id, 'user_id' => $user_id]);
$son_seans = $stmtSonSeans->fetch();

$baslama_sayfasi = $son_seans ? $son_seans['bitis_sayfasi'] : (isset($kitap['baslangic_sayfa']) && (int)$kitap['baslangic_sayfa'] > 0 ? (int)$kitap['baslangic_sayfa'] : 1);
$kitap_bitis_sayfa = (int)(isset($kitap['bitis_sayfa']) && $kitap['bitis_sayfa'] !== null && $kitap['bitis_sayfa'] !== '' ? $kitap['bitis_sayfa'] : $kitap['sayfa']);
if ($kitap_bitis_sayfa < 1) {
    $kitap_bitis_sayfa = (int)$kitap['sayfa'];
}

// Tahmini bitiş süresi: geçmiş seanslardan sayfa/saniye ortalaması
$tahmini_bitis_metin = '';
$stmtOku = $pdo->prepare("SELECT sure_saniye, baslama_sayfasi, bitis_sayfasi FROM okumalar WHERE book_id = ? AND user_id = ?");
$stmtOku->execute([$book_id, $user_id]);
$okumalar = $stmtOku->fetchAll(PDO::FETCH_ASSOC);
$toplam_saniye = 0;
$toplam_sayfa = 0;
foreach ($okumalar as $o) {
    $toplam_saniye += (int)$o['sure_saniye'];
    $adet = (int)($o['bitis_sayfasi'] ?? 0) - (int)$o['baslama_sayfasi'] + 1;
    if ($adet > 0) $toplam_sayfa += $adet;
}
$kalan_sayfa = max(0, $kitap_bitis_sayfa - $baslama_sayfasi);
if ($toplam_sayfa > 0 && $kalan_sayfa > 0) {
    $saniye_per_sayfa = $toplam_saniye / $toplam_sayfa;
    $tahmini_saniye = (int) round($kalan_sayfa * $saniye_per_sayfa);
    $tahmini_bitis_ts = time() + $tahmini_saniye;
    $tahmini_bitis_metin = date('H:i', $tahmini_bitis_ts);
    if (date('Y-m-d', $tahmini_bitis_ts) !== date('Y-m-d')) {
        $tahmini_bitis_metin = date('d.m.Y', $tahmini_bitis_ts) . ' ' . $tahmini_bitis_metin;
    }
}

$kitap_bitti = (int)($kitap['durum_id'] ?? 0) === 3;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Okuma Seansı - <?= htmlspecialchars($kitap['baslik']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #f3f4f6; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; position: relative; background-color: #111827; }
        body.has-cover::before {
            content: ''; position: fixed; inset: 0; z-index: 0;
            background-image: url('assets/uploads/<?= !empty($kitap['kapak']) ? htmlspecialchars($kitap['kapak']) : '' ?>');
            background-size: cover; background-position: center;
            background-repeat: no-repeat;
        }
        body.has-cover::after {
            content: ''; position: fixed; inset: 0; z-index: 1;
            background: rgba(0, 0, 0, 0.65);
        }
        body.has-cover .top-bar, body.has-cover .timer-container, body.has-cover .modal-overlay { position: relative; z-index: 2; }
        .top-bar { padding: 1rem; background-color: rgba(31, 41, 55, 0.85); box-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 1rem; justify-content: center; flex-wrap: wrap; }
        .top-bar-cover { width: 56px; height: 84px; object-fit: cover; border-radius: 6px; background-color: #374151; }
        .top-bar-text { text-align: center; }
        .top-bar h2 { margin: 0; font-size: 1.2rem; }
        .top-bar h2 a { color: #60a5fa; text-decoration: none; }
        .top-bar h2 a:hover { text-decoration: underline; }
        .top-bar p { margin: 0.2rem 0 0 0; font-size: 0.9rem; color: #9ca3af; }
        .top-bar .tahmini-bitis { font-size: 0.85rem; color: #93c5fd; margin-top: 0.15rem; font-weight: 600; }
        
        .timer-container { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .timer-row { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; margin-bottom: 2rem; }
        .timer { font-size: 5rem; font-weight: bold; font-variant-numeric: tabular-nums; letter-spacing: 2px; color: #ffffff; text-shadow: 0 0 20px rgba(96, 165, 250, 0.3); }
        .timer-label { font-size: 0.85rem; color: #9ca3af; }
        .clock { font-size: 1.75rem; font-variant-numeric: tabular-nums; color: #60a5fa; letter-spacing: 1px; }
        @media (max-width: 500px) { .timer { font-size: 4rem; } .clock { font-size: 1.5rem; } }
        
        .controls { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box; }
        .btn { flex: 1; padding: 1.2rem; border: none; border-radius: 8px; font-size: 1.2rem; font-weight: bold; cursor: pointer; color: white; transition: transform 0.1s; }
        .btn:active { transform: scale(0.95); }
        .btn-start { background-color: #10b981; }
        .btn-pause { background-color: #f59e0b; display: none; }
        .btn-stop { background-color: #ef4444; width: 100%; margin-top: 1rem; flex: none; }
        .btn-cancel { background-color: #4b5563; text-decoration: none; display: block; text-align: center; margin-top: 1rem; font-size: 1rem; padding: 1rem; border-radius: 8px; }
        .btn-ana-sayfa { display: block; width: 100%; margin-top: 0.5rem; padding: 0.75rem; font-size: 0.95rem; background: #374151; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; }
        .btn-ana-sayfa:hover { background: #4b5563; }
        .btn-reset { background-color: #6b7280; width: 100%; margin-top: 0.5rem; flex: none; display: none; }
        .btn-reset.visible { display: block; }
        
        /* Modal (Popup) Stilleri */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); justify-content: center; align-items: center; z-index: 1000; }
        .modal { background: white; color: #1f2937; padding: 2rem; border-radius: 8px; width: 90%; max-width: 400px; }
        .modal h3 { margin-top: 0; color: #2563eb; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 0.5rem; }
        .form-group input { width: 100%; padding: 1rem; border: 2px solid #d1d5db; border-radius: 8px; font-size: 1.2rem; box-sizing: border-box; }
        .modal-buttons { display: flex; gap: 1rem; }
        .btn-save { background-color: #3b82f6; color: white; border: none; padding: 1rem; border-radius: 8px; font-weight: bold; font-size: 1.1rem; flex: 1; cursor: pointer; }
        .btn-close { background-color: #d1d5db; color: #1f2937; border: none; padding: 1rem; border-radius: 8px; font-weight: bold; font-size: 1.1rem; flex: 1; cursor: pointer; }
        
        .status-text { text-align: center; color: #10b981; margin-bottom: 1rem; font-weight: bold; height: 1.5rem; }
        .btn-alinti { background-color: #6366f1; margin-top: 0.5rem; flex: none; width: 100%; }
        .btn-alinti:hover { background-color: #4f46e5; }
        #alintiModal .modal { max-width: 420px; }
        #alintiModal .alinti-preview-wrap { margin: 1rem 0; text-align: center; max-height: 280px; overflow: hidden; border-radius: 8px; background: #f3f4f6; }
        #alintiModal .alinti-preview-wrap img { max-width: 100%; max-height: 260px; object-fit: contain; display: block; margin: 0 auto; }
        #alintiModal .alinti-crop-canvas-wrap { display: none; margin: 0.5rem 0; position: relative; touch-action: none; }
        #alintiModal .alinti-crop-canvas-wrap.active { display: block; }
        #alintiModal .alinti-crop-canvas-wrap canvas { display: block; max-width: 100%; height: auto; margin: 0 auto; border: 2px solid #6366f1; border-radius: 6px; }
        #alintiModal .alinti-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem; }
        #alintiModal .alinti-actions button { flex: 1; min-width: 120px; padding: 0.6rem; font-size: 0.9rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
        #alintiModal .alinti-file-input { display: none; }
        .btn-dusunce { background-color: #059669; margin-top: 0.5rem; flex: none; width: 100%; }
        .btn-dusunce:hover { background-color: #047857; }
        #dusunceModal .modal { max-width: 420px; position: relative; }
        #dusunceModal .modal-header-wrap { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; }
        #dusunceModal .modal-close-x { min-width: 44px; min-height: 44px; padding: 0; border: none; background: #e5e7eb; color: #374151; font-size: 1.5rem; line-height: 1; border-radius: 8px; cursor: pointer; flex-shrink: 0; }
        #dusunceModal .modal-close-x:hover { background: #d1d5db; }
        #dusunceModal .form-group textarea { width: 100%; padding: 1rem; border: 2px solid #d1d5db; border-radius: 8px; font-size: 1rem; box-sizing: border-box; min-height: 80px; resize: vertical; font-family: inherit; }
        #dusunceModal .dusunce-voice-row { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
        #dusunceModal .btn-mic { min-width: 44px; min-height: 44px; padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6b7280; color: white; }
        #dusunceModal .btn-mic.recording { background: #dc2626; }
        #dusunceModal .btn-mic.stopped { background: #059669; }
        #dusunceModal .btn-gemini-duzelt { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6366f1; color: white; }
        #dusunceModal .btn-gemini-duzelt:hover { background: #4f46e5; }
        #dusunceModal .btn-gemini-duzelt:disabled { opacity: 0.6; cursor: not-allowed; }
        #dusunceModal .dusunce-rich-toolbar { display: flex; gap: 2px; padding: 4px 0; flex-wrap: wrap; }
        #dusunceModal .dusunce-rich-toolbar button { padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; cursor: pointer; font-size: 0.85rem; }
        #dusunceModal .dusunce-rich-toolbar button:hover { background: #e5e7eb; }
        #dusunceModal .dusunce-editor { min-height: 120px; padding: 0.75rem; border: 2px solid #d1d5db; border-radius: 8px; background: #fff; font-family: inherit; font-size: 1rem; overflow-y: auto; }
        #dusunceModal .dusunce-editor:focus { outline: none; border-color: #2563eb; }
        .kitap-bitti-message { text-align: center; font-size: 1.1rem; color: #9ca3af; margin-bottom: 1.5rem; padding: 0 1rem; }
    </style>
</head>
<body<?= !empty($kitap['kapak']) ? ' class="has-cover"' : '' ?>>

<div class="top-bar">
    <?php if (!empty($kitap['kapak'])): ?>
        <img src="assets/uploads/<?= htmlspecialchars($kitap['kapak']) ?>" class="top-bar-cover" alt="">
    <?php endif; ?>
    <div class="top-bar-text">
        <h2><a href="kitap.php?id=<?= (int)$book_id ?>" target="_blank" rel="noopener"><?= htmlspecialchars($kitap['baslik']) ?></a></h2>
        <?php if ($kitap_bitti): ?>
        <p><?= htmlspecialchars($kitap['yazar']) ?> &bull; Kitap bitti – alıntı ve düşünce ekleyebilirsiniz</p>
        <?php else: ?>
        <p><?= htmlspecialchars($kitap['yazar']) ?> &bull; Başlangıç: Sayfa <?= $baslama_sayfasi ?></p>
        <?php if ($tahmini_bitis_metin !== ''): ?>
        <p class="tahmini-bitis">Tahmini bitiş: <?= htmlspecialchars($tahmini_bitis_metin) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="timer-container">
    <?php if ($kitap_bitti): ?>
    <div class="kitap-bitti-message" id="kitapBittiMessage">Bu kitap bitti. Sadece alıntı ve düşünce ekleyebilirsiniz.</div>
    <div style="width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box;">
        <button type="button" class="btn btn-alinti" id="alintiEkleBtn">📷 Alıntı Ekle</button>
        <button type="button" class="btn btn-dusunce" id="dusunceEkleBtn">💭 Düşünce Ekle</button>
        <a href="index.php" class="btn-cancel" onclick="return true;">Vazgeç ve Çık</a>
        <button type="button" class="btn btn-ana-sayfa" id="anaSayfaBtn">Ana Sayfa</button>
    </div>
    <?php else: ?>
    <div class="status-text" id="statusText">Seansı başlatmak için Dokunun</div>
    <div class="timer-row">
        <span class="timer-label">Geçen süre</span>
        <div class="timer" id="display">00:00:00</div>
        <span class="timer-label">Güncel saat</span>
        <div class="clock" id="clock">--:--:--</div>
    </div>
    
    <div class="controls">
        <button type="button" class="btn btn-start" id="startBtn">▶ Başla</button>
        <button type="button" class="btn btn-pause" id="pauseBtn">⏸ Duraklat</button>
        <button type="button" class="btn btn-stop" id="stopBtn">⏹ Seansı Bitir</button>
    </div>
    
    <div style="width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box;">
        <button type="button" class="btn btn-alinti" id="alintiEkleBtn">📷 Alıntı Ekle</button>
        <button type="button" class="btn btn-dusunce" id="dusunceEkleBtn">💭 Düşünce Ekle</button>
        <button type="button" class="btn btn-reset" id="resetBtn">Yeni seans başlat</button>
        <a href="index.php" class="btn-cancel" onclick="return confirmExit();">Vazgeç ve Çık</a>
        <button type="button" class="btn btn-ana-sayfa" id="anaSayfaBtn">Ana Sayfa</button>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="alintiModal" style="display:none;">
    <div class="modal">
        <h3>Alıntı – Sayfa fotoğrafı</h3>
        <p style="color:#4b5563; font-size:0.9rem;">Sayfanın fotoğrafını çekin veya galeriden seçin. İsterseniz alanı kırpabilirsiniz.</p>
        <input type="file" id="alintiFileInput" class="alinti-file-input" accept="image/*" capture="environment">
        <div class="alinti-preview-wrap" id="alintiPreviewWrap" style="display:none;">
            <img id="alintiPreviewImg" alt="Önizleme">
        </div>
        <div class="alinti-crop-canvas-wrap" id="alintiCropWrap">
            <canvas id="alintiCropCanvas"></canvas>
            <p style="font-size:0.8rem; color:#6b7280; margin-top:0.35rem;">Dikdörtgen çizmek için sürükleyin</p>
        </div>
        <div class="alinti-actions" id="alintiActions" style="display:none;">
            <button type="button" id="alintiUploadFullBtn" style="background:#10b981; color:white;">Yükle (kırpma yok)</button>
            <button type="button" id="alintiCropBtn" style="background:#6366f1; color:white;">Kırp</button>
        </div>
        <div class="alinti-actions" id="alintiCropActions" style="display:none;">
            <button type="button" id="alintiUploadCropBtn" style="background:#10b981; color:white;">Seçili alanı yükle</button>
            <button type="button" id="alintiCropCancelBtn" style="background:#6b7280; color:white;">İptal</button>
        </div>
        <div class="modal-buttons" style="margin-top:1rem;">
            <button type="button" class="btn-close" id="alintiModalCloseBtn">Kapat</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="dusunceModal" style="display:none;">
    <div class="modal">
        <div class="modal-header-wrap">
            <h3>Düşünce Ekle</h3>
            <button type="button" class="modal-close-x" id="dusunceModalCloseX" aria-label="Kapat">×</button>
        </div>
        <div class="form-group">
            <label>Sesle kaydet</label>
            <div class="dusunce-voice-row">
                <button type="button" class="btn-mic" id="dusunceMicBtn">🎤 Mikrofon</button>
                <button type="button" class="btn-gemini-duzelt" id="dusunceGeminiDuzeltBtn">Gemini ile düzelt</button>
            </div>
            <textarea id="dusunceTranscribeArea" placeholder="Ses kaydı metni buraya yazılacak veya elle yazıp düzeltebilirsiniz..."></textarea>
        </div>
        <div class="form-group">
            <label for="dusunceEditor">Düşünceniz</label>
            <div class="dusunce-rich-toolbar" id="dusunceToolbar">
                <button type="button" onclick="dusunceRichCmd('bold')" title="Kalın">B</button>
                <button type="button" onclick="dusunceRichCmd('italic')" title="İtalik">İ</button>
                <button type="button" onclick="dusunceRichCmd('underline')" title="Altı çizili">U</button>
                <button type="button" onclick="dusunceRichCmd('hiliteColor', false, '#fef08a')" title="Vurgula">Vurgula</button>
            </div>
            <div id="dusunceEditor" class="dusunce-editor" contenteditable="true"></div>
            <input type="hidden" id="dusunceHidden" value="">
        </div>
        <div class="form-group">
            <label for="dusunceSayfaBaslangic">Başlangıç sayfası (opsiyonel)</label>
            <input type="number" id="dusunceSayfaBaslangic" min="1" placeholder="">
        </div>
        <div class="form-group">
            <label for="dusunceSayfaBitis">Bitiş sayfası (opsiyonel)</label>
            <input type="number" id="dusunceSayfaBitis" min="1" placeholder="">
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-close" id="dusunceModalCloseBtn">Kapat</button>
            <button type="button" class="btn-save" id="dusunceKaydetBtn">Düşünceyi kaydet</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="endModal">
    <div class="modal">
        <h3>Seansı Kaydet</h3>
        <p style="color: #4b5563; font-size: 0.9rem;">Harika! Okuma süreniz kaydedilecek.</p>
        
        <form id="saveForm" method="POST" action="oku.php">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="book_id" value="<?= $book_id ?>">
            <input type="hidden" name="baslama_sayfasi" value="<?= $baslama_sayfasi ?>">
            <input type="hidden" name="sure_saniye" id="formSureSaniye" value="0">
            
            <div class="form-group">
                <label for="bitis_sayfasi">Hangi sayfada kaldınız?</label>
                <input type="number" id="bitis_sayfasi" name="bitis_sayfasi" min="<?= $baslama_sayfasi ?>" max="<?= $kitap_bitis_sayfa ?>" required>
                <button type="button" id="kitapBittiBtn" style="margin-top:0.5rem; padding:0.4rem 0.8rem; font-size:0.9rem; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer;">Kitabı bitirdim</button>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-close" id="closeModalBtn">İptal</button>
                <button type="submit" class="btn-save">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<script>
    // PHP'den gelen kitap ID'sini al (LocalStorage anahtarı için benzersiz olmalı)
    const bookId = <?= $book_id ?>;
    const storageKey = 'reading_session_book_' + bookId;
    const kitapBitti = <?= $kitap_bitti ? 'true' : 'false'; ?>;

    var isRunning = false;
    var timerPausedByModal = false;
    var pauseTimer = function() {};
    var startTimer = function() {};

    if (!kitapBitti) {
    // Arayüz Elementleri (sadece seans modunda)
    const display = document.getElementById('display');
    const clockEl = document.getElementById('clock');
    const startBtn = document.getElementById('startBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const stopBtn = document.getElementById('stopBtn');
    const statusText = document.getElementById('statusText');
    const endModal = document.getElementById('endModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const saveForm = document.getElementById('saveForm');
    const formSureSaniye = document.getElementById('formSureSaniye');
    const bitisSayfasiInput = document.getElementById('bitis_sayfasi');
    const kitapBitisSayfa = <?= $kitap_bitis_sayfa ?>;
    const resetBtn = document.getElementById('resetBtn');

    // Zaman: gerçek süre için zaman damgası (mobil ekran kapalıyken doğru sayar)
    let startTime = null;           // Şu anki parçanın başlangıcı (ms)
    let pausedAccumulatedSeconds = 0;
    let timerInterval = null;
    let isRunning = false;
    let wakeLock = null;
    let timerPausedByModal = false;

    function getCurrentSeconds() {
        if (!isRunning || startTime === null) return pausedAccumulatedSeconds;
        return pausedAccumulatedSeconds + Math.floor((Date.now() - startTime) / 1000);
    }

    // --- 1. LOCAL STORAGE ---
    function loadFromStorage() {
        const savedData = localStorage.getItem(storageKey);
        if (savedData) {
            const data = JSON.parse(savedData);
            pausedAccumulatedSeconds = data.seconds || 0;
            updateDisplay();
            updateClock();
            if (pausedAccumulatedSeconds > 0) {
                statusText.innerText = "Yarım kalan seans bulundu.";
                statusText.style.color = "#f59e0b";
                resetBtn.classList.add('visible');
            }
        } else {
            updateClock();
        }
    }

    function resetSession() {
        if (!confirm("Yarım kalan seans silinecek. Sıfırdan yeni seans başlatmak istiyor musunuz?")) return;
        localStorage.removeItem(storageKey);
        pausedAccumulatedSeconds = 0;
        startTime = null;
        updateDisplay();
        statusText.innerText = "Seansı başlatmak için Dokunun";
        statusText.style.color = "#10b981";
        startBtn.innerText = "▶ Başla";
        resetBtn.classList.remove('visible');
    }

    function saveToStorage() {
        const sec = getCurrentSeconds();
        localStorage.setItem(storageKey, JSON.stringify({ seconds: sec }));
    }

    // --- 2. KRONOMETRE (zaman damgası tabanlı) ---
    function formatDuration(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return (h < 10 ? "0" + h : h) + ":" + (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
    }

    function updateDisplay() {
        const sec = getCurrentSeconds();
        const formatted = formatDuration(sec);
        display.innerText = formatted;
        document.title = formatted + " - Okunuyor...";
    }

    function updateClock() {
        const now = new Date();
        const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        clockEl.textContent = (h < 10 ? "0" + h : h) + ":" + (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
    }

    function tick() {
        updateDisplay();
        updateClock();
        if (getCurrentSeconds() % 3 === 0) saveToStorage();
    }

    // --- 3. WAKE LOCK (ekran açık kalsın) ---
    async function requestWakeLock() {
        if (!navigator.wakeLock) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
        } catch (e) {
            console.warn("Wake Lock alınamadı:", e);
        }
    }

    function releaseWakeLock() {
        if (wakeLock) {
            wakeLock.release().catch(() => {});
            wakeLock = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            updateDisplay();
            updateClock();
            if (isRunning) requestWakeLock();
        }
    });

    startTimer = function() {
        if (!isRunning) {
            isRunning = true;
            startTime = Date.now();
            startBtn.style.display = 'none';
            pauseBtn.style.display = 'block';
            statusText.innerText = "Okuma devam ediyor...";
            statusText.style.color = "#10b981";
            requestWakeLock();
            timerInterval = setInterval(tick, 1000);
            resetBtn.classList.remove('visible');
        }
    };

    pauseTimer = function() {
        if (isRunning) {
            var elapsed = getCurrentSeconds();
            isRunning = false;
            pausedAccumulatedSeconds = elapsed;
            startTime = null;
            clearInterval(timerInterval);
            releaseWakeLock();
            pauseBtn.style.display = 'none';
            startBtn.style.display = 'block';
            startBtn.innerText = "▶ Devam Et";
            statusText.innerText = "Duraklatıldı";
            statusText.style.color = "#f59e0b";
            updateDisplay();
            saveToStorage();
            resetBtn.classList.add('visible');
        }
    };

    // --- 4. EVENT LİSTENER'LAR (seans) ---
    startBtn.addEventListener('click', startTimer);
    pauseBtn.addEventListener('click', pauseTimer);

    stopBtn.addEventListener('click', () => {
        pauseTimer();
        const sec = getCurrentSeconds();
        if (sec < 10) {
            alert("Seans çok kısa! Kaydetmek için en az 10 saniye okumalısınız.");
            return;
        }
        formSureSaniye.value = String(Math.floor(sec));
        endModal.style.display = 'flex';
        bitisSayfasiInput.focus();
    });

    closeModalBtn.addEventListener('click', () => {
        endModal.style.display = 'none';
    });

    document.getElementById('kitapBittiBtn').addEventListener('click', () => {
        bitisSayfasiInput.value = kitapBitisSayfa;
    });

    resetBtn.addEventListener('click', resetSession);

    saveForm.addEventListener('submit', () => {
        localStorage.removeItem(storageKey);
        releaseWakeLock();
        resetBtn.classList.remove('visible');
    });
    }

    // --- Alıntı foto modal (açıkken sayaç durar, kapanınca devam eder) ---
    const alintiModal = document.getElementById('alintiModal');
    const alintiFileInput = document.getElementById('alintiFileInput');
    const alintiPreviewWrap = document.getElementById('alintiPreviewWrap');
    const alintiPreviewImg = document.getElementById('alintiPreviewImg');
    const alintiActions = document.getElementById('alintiActions');
    const alintiCropWrap = document.getElementById('alintiCropWrap');
    const alintiCropCanvas = document.getElementById('alintiCropCanvas');
    const alintiCropActions = document.getElementById('alintiCropActions');
    let alintiCurrentFile = null;
    let alintiCropRect = null;
    let alintiLastObjectUrl = null;

    function closeAlintiModalAndResume() {
        alintiModal.style.display = 'none';
        alintiCurrentFile = null;
        if (alintiLastObjectUrl) { URL.revokeObjectURL(alintiLastObjectUrl); alintiLastObjectUrl = null; }
        if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
    }

    document.getElementById('alintiEkleBtn').addEventListener('click', () => {
        if (isRunning) { pauseTimer(); timerPausedByModal = true; }
        alintiFileInput.value = '';
        alintiPreviewWrap.style.display = 'none';
        alintiActions.style.display = 'none';
        alintiCropWrap.classList.remove('active');
        alintiCropActions.style.display = 'none';
        alintiModal.style.display = 'flex';
        alintiFileInput.click();
    });

    document.getElementById('alintiModalCloseBtn').addEventListener('click', closeAlintiModalAndResume);

    alintiFileInput.addEventListener('change', function() {
        const file = this.files && this.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        alintiCurrentFile = file;
        if (alintiLastObjectUrl) URL.revokeObjectURL(alintiLastObjectUrl);
        alintiLastObjectUrl = URL.createObjectURL(file);
        alintiPreviewImg.src = alintiLastObjectUrl;
        alintiPreviewWrap.style.display = 'block';
        alintiActions.style.display = 'flex';
    });

    function alintiDoUpload(blobOrFile) {
        const fd = new FormData();
        fd.append('action', 'alinti_foto_upload');
        fd.append('book_id', String(bookId));
        fd.append('foto', blobOrFile, blobOrFile.name || 'alinti.jpg');
        fetch('oku.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    closeAlintiModalAndResume();
                    const prev = statusText.textContent;
                    statusText.textContent = 'Alıntı eklendi.';
                    statusText.style.color = '#10b981';
                    setTimeout(() => { statusText.textContent = prev; statusText.style.color = ''; }, 2500);
                } else {
                    alert(data.error || 'Yükleme başarısız.');
                }
            })
            .catch(() => alert('Yükleme başarısız.'));
    }

    document.getElementById('alintiUploadFullBtn').addEventListener('click', () => {
        if (!alintiCurrentFile) return;
        alintiDoUpload(alintiCurrentFile);
    });

    document.getElementById('alintiCropBtn').addEventListener('click', () => {
        if (!alintiPreviewImg.src || !alintiPreviewImg.complete) return;
        alintiCropWrap.classList.add('active');
        alintiCropActions.style.display = 'flex';
        alintiActions.style.display = 'none';
        const img = alintiPreviewImg;
        const maxW = 360, maxH = 320;
        let w = img.naturalWidth, h = img.naturalHeight;
        if (w > maxW || h > maxH) {
            const r = Math.min(maxW/w, maxH/h);
            w = Math.round(w*r); h = Math.round(h*r);
        }
        alintiCropCanvas.width = w; alintiCropCanvas.height = h;
        const ctx = alintiCropCanvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        alintiCropRect = { x: 0, y: 0, w: w, h: h };
        let dragging = false, startX, startY;
        function drawRect(r) {
            ctx.drawImage(img, 0, 0, w, h);
            if (r && r.w > 0 && r.h > 0) {
                ctx.strokeStyle = '#6366f1'; ctx.lineWidth = 2;
                ctx.strokeRect(r.x, r.y, r.w, r.h);
                ctx.fillStyle = 'rgba(0,0,0,0.4)';
                ctx.fillRect(0,0,w,h);
                ctx.clearRect(r.x,r.y,r.w,r.h);
                ctx.strokeRect(r.x,r.y,r.w,r.h);
            }
        }
        function getCoords(e) {
            const rect = alintiCropCanvas.getBoundingClientRect();
            const scaleX = alintiCropCanvas.width / rect.width, scaleY = alintiCropCanvas.height / rect.height;
            const ev = e.touches ? e.touches[0] : e;
            return { x: (ev.clientX - rect.left) * scaleX, y: (ev.clientY - rect.top) * scaleY };
        }
        alintiCropCanvas.onmousedown = alintiCropCanvas.ontouchstart = function(e) {
            e.preventDefault();
            const p = getCoords(e);
            dragging = true; startX = p.x; startY = p.y;
            alintiCropRect = { x: p.x, y: p.y, w: 0, h: 0 };
            drawRect(alintiCropRect);
        };
        alintiCropCanvas.onmousemove = alintiCropCanvas.ontouchmove = function(e) {
            if (!dragging) return;
            e.preventDefault();
            const p = getCoords(e);
            alintiCropRect.x = Math.min(startX, p.x);
            alintiCropRect.y = Math.min(startY, p.y);
            alintiCropRect.w = Math.abs(p.x - startX);
            alintiCropRect.h = Math.abs(p.y - startY);
            drawRect(alintiCropRect);
        };
        alintiCropCanvas.onmouseup = alintiCropCanvas.onmouseleave = alintiCropCanvas.ontouchend = function(e) {
            if (!dragging) return;
            e.preventDefault();
            dragging = false;
        };
    });

    document.getElementById('alintiCropCancelBtn').addEventListener('click', () => {
        alintiCropWrap.classList.remove('active');
        alintiCropActions.style.display = 'none';
        alintiActions.style.display = 'flex';
    });

    document.getElementById('alintiUploadCropBtn').addEventListener('click', () => {
        if (!alintiCropRect || alintiCropRect.w < 5 || alintiCropRect.h < 5) {
            alert('Lütfen kırpmak için bir alan seçin.');
            return;
        }
        const img = alintiPreviewImg;
        const scaleX = img.naturalWidth / alintiCropCanvas.width;
        const scaleY = img.naturalHeight / alintiCropCanvas.height;
        const sx = Math.round(alintiCropRect.x * scaleX);
        const sy = Math.round(alintiCropRect.y * scaleY);
        const sw = Math.round(alintiCropRect.w * scaleX);
        const sh = Math.round(alintiCropRect.h * scaleY);
        const out = document.createElement('canvas');
        out.width = sw; out.height = sh;
        out.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
        out.toBlob(function(blob) {
            if (blob) {
                blob.name = alintiCurrentFile && alintiCurrentFile.name ? alintiCurrentFile.name : 'alinti.jpg';
                alintiDoUpload(blob);
            }
        }, 'image/jpeg', 0.92);
    });

    // --- Düşünce modal (açıkken sayaç durar, kapanınca devam eder) ---
    const dusunceModal = document.getElementById('dusunceModal');
    const dusunceEditor = document.getElementById('dusunceEditor');
    const dusunceHidden = document.getElementById('dusunceHidden');
    const dusunceTranscribeArea = document.getElementById('dusunceTranscribeArea');
    const dusunceSayfaBaslangic = document.getElementById('dusunceSayfaBaslangic');
    const dusunceSayfaBitis = document.getElementById('dusunceSayfaBitis');

    function dusunceRichCmd(cmd, ui, value) {
        dusunceEditor.focus();
        document.execCommand(cmd, ui || false, value || null);
    }

    function syncDusunceRichToHidden() {
        const html = dusunceEditor.innerHTML.trim();
        dusunceHidden.value = (html === '' || html === '<br>') ? '' : html;
    }

    function closeDusunceModalAndResume() {
        dusunceModal.style.display = 'none';
        dusunceEditor.innerHTML = '';
        dusunceHidden.value = '';
        dusunceTranscribeArea.value = '';
        dusunceSayfaBaslangic.value = '';
        dusunceSayfaBitis.value = '';
        dusunceMicBtn.classList.remove('recording', 'stopped');
        dusunceMicBtn.textContent = '🎤 Mikrofon';
        if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
    }

    const dusunceMicBtn = document.getElementById('dusunceMicBtn');
    let dusunceMediaRecorder = null;
    let dusunceAudioChunks = [];

    dusunceMicBtn.addEventListener('click', () => {
        if (dusunceMediaRecorder && dusunceMediaRecorder.state === 'recording') {
            dusunceMediaRecorder.stop();
            dusunceMicBtn.classList.remove('recording');
            dusunceMicBtn.classList.add('stopped');
            dusunceMicBtn.textContent = '✓ Durduruldu';
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Bu tarayıcı mikrofon kaydını desteklemiyor.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
            const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm' : 'audio/ogg';
            dusunceMediaRecorder = new MediaRecorder(stream);
            dusunceAudioChunks = [];
            dusunceMediaRecorder.ondataavailable = e => { if (e.data.size > 0) dusunceAudioChunks.push(e.data); };
            dusunceMediaRecorder.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                if (dusunceAudioChunks.length === 0) return;
                const blob = new Blob(dusunceAudioChunks, { type: mime });
                const fd = new FormData();
                fd.append('action', 'transcribe');
                fd.append('audio', blob, 'audio.webm');
                dusunceTranscribeArea.placeholder = 'Dönüştürülüyor...';
                fetch('gemini_dusunce.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        dusunceTranscribeArea.placeholder = 'Ses kaydı metni buraya yazılacak...';
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        const prev = dusunceTranscribeArea.value;
                        dusunceTranscribeArea.value = prev ? prev + '\n' + (data.text || '') : (data.text || '');
                    })
                    .catch(() => {
                        dusunceTranscribeArea.placeholder = 'Ses kaydı metni buraya yazılacak...';
                        alert('Ses dönüştürme başarısız.');
                    });
            };
            dusunceMediaRecorder.start();
            dusunceMicBtn.classList.add('recording');
            dusunceMicBtn.classList.remove('stopped');
            dusunceMicBtn.textContent = '⏹ Kaydı Durdur';
        }).catch(() => alert('Mikrofon erişimi verilmedi.'));
    });

    document.getElementById('dusunceGeminiDuzeltBtn').addEventListener('click', () => {
        const ham = dusunceTranscribeArea.value.trim();
        if (ham === '') {
            alert('Önce ses kaydı yapıp metne dönüştürün veya yukarıdaki alana metin yazın.');
            return;
        }
        const btn = document.getElementById('dusunceGeminiDuzeltBtn');
        btn.disabled = true;
        btn.textContent = 'Düzeltiliyor...';
        const fd = new FormData();
        fd.append('action', 'duzelt');
        fd.append('metin', ham);
        fetch('gemini_dusunce.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Gemini ile düzelt';
                if (data.error) {
                    alert(data.error);
                    return;
                }
                const txt = (data.text || '').trim();
                if (txt) {
                    const sel = window.getSelection();
                    const range = document.createRange();
                    dusunceEditor.focus();
                    if (dusunceEditor.childNodes.length) {
                        range.setStart(dusunceEditor, dusunceEditor.childNodes.length);
                        range.collapse(true);
                    } else {
                        range.setStart(dusunceEditor, 0);
                        range.collapse(true);
                    }
                    sel.removeAllRanges();
                    sel.addRange(range);
                    document.execCommand('insertText', false, txt);
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Gemini ile düzelt';
                alert('Düzeltme isteği başarısız.');
            });
    });

    document.getElementById('dusunceEkleBtn').addEventListener('click', () => {
        if (isRunning) { pauseTimer(); timerPausedByModal = true; }
        dusunceEditor.innerHTML = '';
        dusunceHidden.value = '';
        dusunceTranscribeArea.value = '';
        dusunceSayfaBaslangic.value = '';
        dusunceSayfaBitis.value = '';
        dusunceMicBtn.classList.remove('recording', 'stopped');
        dusunceMicBtn.textContent = '🎤 Mikrofon';
        dusunceModal.style.display = 'flex';
        dusunceEditor.focus();
    });

    document.getElementById('dusunceModalCloseX').addEventListener('click', closeDusunceModalAndResume);
    document.getElementById('dusunceModalCloseBtn').addEventListener('click', closeDusunceModalAndResume);

    document.getElementById('dusunceKaydetBtn').addEventListener('click', () => {
        syncDusunceRichToHidden();
        const metin = dusunceHidden.value.trim();
        if (metin === '') {
            alert('Lütfen düşüncenizi yazın.');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'dusunce_kaydet');
        fd.append('book_id', String(bookId));
        fd.append('dusunce', metin);
        fd.append('sayfa_baslangic', dusunceSayfaBaslangic.value.trim());
        fd.append('sayfa_bitis', dusunceSayfaBitis.value.trim());
        fetch('oku.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    closeDusunceModalAndResume();
                    const prev = statusText.textContent;
                    statusText.textContent = 'Düşünce kaydedildi.';
                    statusText.style.color = '#10b981';
                    setTimeout(() => { statusText.textContent = prev; statusText.style.color = ''; }, 2500);
                } else {
                    alert(data.error || 'Kayıt başarısız.');
                }
            })
            .catch(() => alert('Kayıt başarısız.'));
    });

    function confirmExit() {
        if (getCurrentSeconds() > 0) {
            return confirm("Kaydedilmemiş okuma süreniz var. Çıkmak istediğinize emin misiniz?");
        }
        return true;
    }

    // Ana Sayfa butonu: yanlışlıkla tıklamada sayaç durmasın diye onay iste
    const anaSayfaBtn = document.getElementById('anaSayfaBtn');
    if (anaSayfaBtn) {
        anaSayfaBtn.addEventListener('click', function() {
            if (!confirm('Ana sayfaya dönmek istediğinize emin misiniz? (Seansınız arka planda kayıtlı kalır.)')) return;
            window.location.href = 'index.php';
        });
    }

    // --- 5. HEARTBEAT ---
    setInterval(() => {
        fetch('oku.php?ping=1').catch(err => console.error("Heartbeat hatası", err));
    }, 600000);

    window.onload = () => {
        if (!kitapBitti) {
            loadFromStorage();
            setInterval(updateClock, 1000);
        }
    };
</script>

</body>
</html>