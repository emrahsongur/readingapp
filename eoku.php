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

// Düşünce kaydet (E-kitap: sayfa alanları kullanılmaz, null gönder)
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
    $err = dusunce_ekle($pdo, $user_id, $dusunce_book_id, $dusunce_metin, null, null, null);
    if ($err !== null) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Alıntı foto (E-kitap: sayfa null)
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
    echo json_encode(['ok' => true, 'alinti_id' => (int)$pdo->lastInsertId()]);
    exit;
}

// E-kitap seans kaydet: baslama_yuzde, bitis_yuzde, sure_saniye
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_session') {
    $book_id = (int)$_POST['book_id'];
    $stmtTip = $pdo->prepare("SELECT kitap_tipi_id FROM kitaplar WHERE id = ? AND user_id = ?");
    $stmtTip->execute([$book_id, $user_id]);
    $tipRow = $stmtTip->fetch(PDO::FETCH_ASSOC);
    if (!$tipRow || (int)($tipRow['kitap_tipi_id'] ?? 0) !== 2) {
        header("Location: index.php");
        exit;
    }
    $baslama_yuzde = isset($_POST['baslama_yuzde']) && $_POST['baslama_yuzde'] !== '' ? (float)$_POST['baslama_yuzde'] : 0;
    $bitis_yuzde = isset($_POST['bitis_yuzde']) && $_POST['bitis_yuzde'] !== '' ? (float)$_POST['bitis_yuzde'] : 0;
    $sure_saniye = (int)$_POST['sure_saniye'];

    if ($bitis_yuzde < $baslama_yuzde) {
        die("Hata: Bitiş yüzdesi, başlangıç yüzdesinden küçük olamaz.");
    }

    try {
        $bitis_zamani = date('Y-m-d H:i:s');
        $baslama_zamani = date('Y-m-d H:i:s', time() - $sure_saniye);

        $stmt = $pdo->prepare("INSERT INTO okumalar (user_id, book_id, baslama, bitis, sure_saniye, baslama_yuzde, bitis_yuzde, baslama_sayfasi, bitis_sayfasi) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)");
        $stmt->execute([$user_id, $book_id, $baslama_zamani, $bitis_zamani, $sure_saniye, $baslama_yuzde, $bitis_yuzde]);

        $pdo->prepare("UPDATE kitaplar SET durum_id = 2 WHERE id = ? AND durum_id = 1")->execute([$book_id]);

        if ($bitis_yuzde >= 100) {
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
if ($kitap_tipi_id !== 2) {
    header("Location: kitap.php?id=" . $book_id);
    exit;
}

// Son seans bitiş yüzdesi = bu seansın başlangıç yüzdesi
$stmtSon = $pdo->prepare("SELECT bitis_yuzde FROM okumalar WHERE book_id = ? AND user_id = ? ORDER BY bitis DESC LIMIT 1");
$stmtSon->execute([$book_id, $user_id]);
$son_seans = $stmtSon->fetch();
$baslama_yuzde = $son_seans && $son_seans['bitis_yuzde'] !== null && $son_seans['bitis_yuzde'] !== '' ? (float)$son_seans['bitis_yuzde'] : 0;

// Tahmini bitiş: geçmiş seanslardan saniye / (bitis_yuzde - baslama_yuzde) = saniye per 1%
$tahmini_bitis_metin = '';
$stmtOku = $pdo->prepare("SELECT sure_saniye, baslama_yuzde, bitis_yuzde FROM okumalar WHERE book_id = ? AND user_id = ? AND bitis_yuzde IS NOT NULL AND baslama_yuzde IS NOT NULL");
$stmtOku->execute([$book_id, $user_id]);
$okumalar = $stmtOku->fetchAll(PDO::FETCH_ASSOC);
$toplam_saniye = 0;
$toplam_yuzde = 0;
foreach ($okumalar as $o) {
    $by = (float)($o['baslama_yuzde'] ?? 0);
    $bit = (float)($o['bitis_yuzde'] ?? 0);
    $delta = $bit - $by;
    if ($delta > 0) {
        $toplam_saniye += (int)$o['sure_saniye'];
        $toplam_yuzde += $delta;
    }
}
$son_yuzde = $baslama_yuzde; // mevcut ilerleme = bu seansın başlangıcı
$kalan_yuzde = max(0, 100 - $son_yuzde);
if ($toplam_yuzde > 0 && $kalan_yuzde > 0) {
    $saniye_per_yuzde = $toplam_saniye / $toplam_yuzde;
    $tahmini_saniye = (int) round($kalan_yuzde * $saniye_per_yuzde);
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
    <title>E-kitap Seansı - <?= htmlspecialchars($kitap['baslik']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #f3f4f6; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; position: relative; background-color: #111827; }
        body.has-cover::before { content: ''; position: fixed; inset: 0; z-index: 0; background-image: url('assets/uploads/<?= !empty($kitap['kapak']) ? htmlspecialchars($kitap['kapak']) : '' ?>'); background-size: cover; background-position: center; background-repeat: no-repeat; }
        body.has-cover::after { content: ''; position: fixed; inset: 0; z-index: 1; background: rgba(0, 0, 0, 0.65); }
        body.has-cover .top-bar, body.has-cover .timer-container, body.has-cover .modal-overlay { position: relative; z-index: 2; }
        .top-bar { padding: 1rem; background-color: rgba(31, 41, 55, 0.85); box-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 1rem; justify-content: center; flex-wrap: wrap; }
        .top-bar-cover { width: 56px; height: 84px; object-fit: cover; border-radius: 6px; background-color: #374151; }
        .top-bar-text { text-align: center; }
        .top-bar h2 { margin: 0; font-size: 1.2rem; }
        .top-bar h2 a { color: #60a5fa; text-decoration: none; }
        .top-bar p { margin: 0.2rem 0 0 0; font-size: 0.9rem; color: #9ca3af; }
        .top-bar .tahmini-bitis { font-size: 0.85rem; color: #93c5fd; margin-top: 0.15rem; font-weight: 600; }
        .timer-container { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .timer-row { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; margin-bottom: 2rem; }
        .timer { font-size: 5rem; font-weight: bold; font-variant-numeric: tabular-nums; letter-spacing: 2px; color: #fff; text-shadow: 0 0 20px rgba(96, 165, 250, 0.3); }
        .timer-label { font-size: 0.85rem; color: #9ca3af; }
        .clock { font-size: 1.75rem; font-variant-numeric: tabular-nums; color: #60a5fa; }
        .controls { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box; }
        .btn { flex: 1; padding: 1.2rem; border: none; border-radius: 8px; font-size: 1.2rem; font-weight: bold; cursor: pointer; color: white; transition: transform 0.1s; }
        .btn:active { transform: scale(0.95); }
        .btn-start { background-color: #10b981; }
        .btn-pause { background-color: #f59e0b; display: none; }
        .btn-stop { background-color: #ef4444; width: 100%; margin-top: 1rem; flex: none; }
        .btn-cancel { background-color: #4b5563; text-decoration: none; display: block; text-align: center; margin-top: 1rem; font-size: 1rem; padding: 1rem; border-radius: 8px; }
        .btn-ana-sayfa { display: block; width: 100%; margin-top: 0.5rem; padding: 0.75rem; font-size: 0.95rem; background: #374151; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; }
        .btn-reset { background-color: #6b7280; width: 100%; margin-top: 0.5rem; flex: none; display: none; }
        .btn-reset.visible { display: block; }
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
        .btn-dusunce { background-color: #059669; margin-top: 0.5rem; flex: none; width: 100%; }
        #alintiModal .modal { max-width: 420px; }
        #alintiModal .alinti-preview-wrap { margin: 1rem 0; text-align: center; max-height: 280px; overflow: hidden; border-radius: 8px; background: #f3f4f6; }
        #alintiModal .alinti-preview-wrap img { max-width: 100%; max-height: 260px; object-fit: contain; display: block; margin: 0 auto; }
        #alintiModal .alinti-crop-canvas-wrap { display: none; margin: 0.5rem 0; }
        #alintiModal .alinti-crop-canvas-wrap.active { display: block; }
        #alintiModal .alinti-crop-canvas-wrap canvas { display: block; max-width: 100%; height: auto; margin: 0 auto; border: 2px solid #6366f1; border-radius: 6px; }
        #alintiModal .alinti-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem; }
        #alintiModal .alinti-actions button { flex: 1; min-width: 120px; padding: 0.6rem; font-size: 0.9rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
        #alintiModal .alinti-file-input { display: none; }
        #dusunceModal .modal { max-width: 420px; position: relative; }
        #dusunceModal .form-group textarea { width: 100%; padding: 1rem; border: 2px solid #d1d5db; border-radius: 8px; font-size: 1rem; box-sizing: border-box; min-height: 80px; resize: vertical; }
        #dusunceModal .dusunce-voice-row { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
        #dusunceModal .btn-mic { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6b7280; color: white; }
        #dusunceModal .btn-mic.recording { background: #dc2626; }
        #dusunceModal .btn-gemini-duzelt { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6366f1; color: white; }
        #dusunceModal .dusunce-rich-toolbar { display: flex; gap: 2px; padding: 4px 0; flex-wrap: wrap; }
        #dusunceModal .dusunce-rich-toolbar button { padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; cursor: pointer; font-size: 0.85rem; }
        #dusunceModal .dusunce-editor { min-height: 120px; padding: 0.75rem; border: 2px solid #d1d5db; border-radius: 8px; background: #fff; font-family: inherit; font-size: 1rem; overflow-y: auto; }
        .kitap-bitti-message { text-align: center; font-size: 1.1rem; color: #9ca3af; margin-bottom: 1.5rem; padding: 0 1rem; }
    </style>
</head>
<body<?= !empty($kitap['kapak']) ? ' class="has-cover"' : '' ?>>

<div class="top-bar">
    <?php if (!empty($kitap['kapak'])): ?>
        <img src="assets/uploads/<?= htmlspecialchars($kitap['kapak']) ?>" class="top-bar-cover" alt="">
    <?php endif; ?>
    <div class="top-bar-text">
        <h2><a href="kitap.php?id=<?= (int)$book_id ?>"><?= htmlspecialchars($kitap['baslik']) ?></a></h2>
        <?php if ($kitap_bitti): ?>
        <p><?= htmlspecialchars($kitap['yazar']) ?> · Kitap bitti – alıntı ve düşünce ekleyebilirsiniz</p>
        <?php else: ?>
        <p><?= htmlspecialchars($kitap['yazar']) ?> · Başlangıç: %<?= number_format($baslama_yuzde, 1) ?></p>
        <?php if ($tahmini_bitis_metin !== ''): ?>
        <p class="tahmini-bitis">Tahmini bitiş: <?= htmlspecialchars($tahmini_bitis_metin) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="timer-container">
    <?php if ($kitap_bitti): ?>
    <div class="kitap-bitti-message">Bu kitap bitti. Sadece alıntı ve düşünce ekleyebilirsiniz.</div>
    <div style="width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box;">
        <button type="button" class="btn btn-alinti" id="alintiEkleBtn">📷 Alıntı Ekle</button>
        <button type="button" class="btn btn-dusunce" id="dusunceEkleBtn">💭 Düşünce Ekle</button>
        <a href="index.php" class="btn-cancel">Vazgeç ve Çık</a>
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
        <button type="button" class="btn btn-pause" id="pauseBtn" style="display:none;">⏸ Duraklat</button>
        <button type="button" class="btn btn-stop" id="stopBtn">⏹ Seansı Bitir</button>
    </div>
    <div style="width: 100%; max-width: 400px; padding: 0 1rem; box-sizing: border-box;">
        <button type="button" class="btn btn-alinti" id="alintiEkleBtn">📷 Alıntı Ekle</button>
        <button type="button" class="btn btn-dusunce" id="dusunceEkleBtn">💭 Düşünce Ekle</button>
        <button type="button" class="btn btn-reset" id="resetBtn" style="display:none;">Yeni seans başlat</button>
        <a href="index.php" class="btn-cancel" id="vazgecLink">Vazgeç ve Çık</a>
        <button type="button" class="btn btn-ana-sayfa" id="anaSayfaBtn">Ana Sayfa</button>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="alintiModal" style="display:none;">
    <div class="modal">
        <h3>Alıntı – Sayfa fotoğrafı</h3>
        <input type="file" id="alintiFileInput" class="alinti-file-input" accept="image/*" capture="environment">
        <div class="alinti-preview-wrap" id="alintiPreviewWrap" style="display:none;"><img id="alintiPreviewImg" alt=""></div>
        <div class="alinti-crop-canvas-wrap" id="alintiCropWrap"><canvas id="alintiCropCanvas"></canvas></div>
        <div class="alinti-actions" id="alintiActions" style="display:none;">
            <button type="button" id="alintiUploadFullBtn" style="background:#10b981; color:white;">Yükle</button>
        </div>
        <div class="modal-buttons" style="margin-top:1rem;">
            <button type="button" class="btn-close" id="alintiModalCloseBtn">Kapat</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="dusunceModal" style="display:none;">
    <div class="modal">
        <h3>Düşünce Ekle</h3>
        <div class="form-group">
            <label>Sesle kaydet</label>
            <div class="dusunce-voice-row">
                <button type="button" class="btn-mic" id="dusunceMicBtn">🎤 Mikrofon</button>
                <button type="button" class="btn-gemini-duzelt" id="dusunceGeminiDuzeltBtn">Gemini ile düzelt</button>
            </div>
            <textarea id="dusunceTranscribeArea" placeholder="Ses kaydı metni veya elle yazın..."></textarea>
        </div>
        <div class="form-group">
            <label for="dusunceEditor">Düşünceniz</label>
            <div id="dusunceEditor" class="dusunce-editor" contenteditable="true"></div>
            <input type="hidden" id="dusunceHidden" value="">
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-close" id="dusunceModalCloseBtn">Kapat</button>
            <button type="button" class="btn-save" id="dusunceKaydetBtn">Kaydet</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="endModal" style="display:none;">
    <div class="modal">
        <h3>Seansı Kaydet</h3>
        <p style="color: #4b5563; font-size: 0.9rem;">Başlangıç ve bitiş yüzdesini girin (0–100).</p>
        <form id="saveForm" method="POST" action="eoku.php">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="book_id" value="<?= $book_id ?>">
            <input type="hidden" name="baslama_yuzde" value="<?= number_format($baslama_yuzde, 2, '.', '') ?>">
            <input type="hidden" name="sure_saniye" id="formSureSaniye" value="0">
            <div class="form-group">
                <label for="bitis_yuzde">Bitiş % (0–100)</label>
                <input type="number" id="bitis_yuzde" name="bitis_yuzde" min="0" max="100" step="0.1" value="<?= $baslama_yuzde >= 100 ? 100 : number_format($baslama_yuzde, 1, '.', '') ?>" required>
                <p style="font-size:0.85rem; color:#6b7280; margin-top:0.35rem;">Başlangıç: %<?= number_format($baslama_yuzde, 1) ?></p>
                <button type="button" id="kitapBittiBtn" style="margin-top:0.5rem; padding:0.4rem 0.8rem; font-size:0.9rem; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer;">Kitabı bitirdim (%100)</button>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn-close" id="closeModalBtn">İptal</button>
                <button type="submit" class="btn-save">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const bookId = <?= $book_id ?>;
    const storageKey = 'reading_ebook_session_' + bookId;
    const kitapBitti = <?= $kitap_bitti ? 'true' : 'false'; ?>;
    const baslamaYuzde = <?= json_encode($baslama_yuzde) ?>;

    let isRunning = false;
    let startTime = null;
    let pausedAccumulatedSeconds = 0;
    let timerInterval = null;
    let timerPausedByModal = false;

    const display = document.getElementById('display');
    const clockEl = document.getElementById('clock');
    const startBtn = document.getElementById('startBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const stopBtn = document.getElementById('stopBtn');
    const statusText = document.getElementById('statusText');
    const endModal = document.getElementById('endModal');
    const saveForm = document.getElementById('saveForm');
    const formSureSaniye = document.getElementById('formSureSaniye');
    const bitisYuzdeInput = document.getElementById('bitis_yuzde');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const resetBtn = document.getElementById('resetBtn');

    function getCurrentSeconds() {
        if (!isRunning || startTime === null) return pausedAccumulatedSeconds;
        return pausedAccumulatedSeconds + Math.floor((Date.now() - startTime) / 1000);
    }

    function formatDuration(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return (h < 10 ? "0" + h : h) + ":" + (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
    }

    function updateDisplay() {
        const sec = getCurrentSeconds();
        display.innerText = formatDuration(sec);
        document.title = formatDuration(sec) + " - E-kitap";
    }

    function updateClock() {
        const now = new Date();
        const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        clockEl.textContent = (h < 10 ? "0" + h : h) + ":" + (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
    }

    function tick() {
        updateDisplay();
        updateClock();
        if (getCurrentSeconds() % 3 === 0) localStorage.setItem(storageKey, JSON.stringify({ seconds: getCurrentSeconds() }));
    }

    function startTimer() {
        if (!isRunning) {
            isRunning = true;
            startTime = Date.now();
            if (startBtn) startBtn.style.display = 'none';
            if (pauseBtn) pauseBtn.style.display = 'block';
            if (statusText) { statusText.innerText = "Okuma devam ediyor..."; statusText.style.color = "#10b981"; }
            timerInterval = setInterval(tick, 1000);
            if (resetBtn) resetBtn.classList.remove('visible');
        }
    }

    function pauseTimer() {
        if (isRunning) {
            isRunning = false;
            pausedAccumulatedSeconds = getCurrentSeconds();
            startTime = null;
            clearInterval(timerInterval);
            if (pauseBtn) pauseBtn.style.display = 'none';
            if (startBtn) { startBtn.style.display = 'block'; startBtn.innerText = "▶ Devam Et"; }
            if (statusText) { statusText.innerText = "Duraklatıldı"; statusText.style.color = "#f59e0b"; }
            updateDisplay();
            localStorage.setItem(storageKey, JSON.stringify({ seconds: pausedAccumulatedSeconds }));
            if (resetBtn) resetBtn.classList.add('visible');
        }
    }

    if (!kitapBitti) {
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            const data = JSON.parse(saved);
            pausedAccumulatedSeconds = data.seconds || 0;
            updateDisplay();
            updateClock();
            if (pausedAccumulatedSeconds > 0 && statusText) {
                statusText.innerText = "Yarım kalan seans bulundu.";
                statusText.style.color = "#f59e0b";
                if (resetBtn) resetBtn.classList.add('visible');
            }
        } else {
            updateClock();
        }

        if (startBtn) startBtn.addEventListener('click', startTimer);
        if (pauseBtn) pauseBtn.addEventListener('click', pauseTimer);

        if (stopBtn) stopBtn.addEventListener('click', function() {
            pauseTimer();
            if (getCurrentSeconds() < 10) {
                alert("Seans çok kısa! En az 10 saniye okumalısınız.");
                return;
            }
            formSureSaniye.value = String(Math.floor(getCurrentSeconds()));
            endModal.style.display = 'flex';
            if (bitisYuzdeInput) bitisYuzdeInput.focus();
        });

        if (closeModalBtn) closeModalBtn.addEventListener('click', function() { endModal.style.display = 'none'; });

        const kitapBittiBtn = document.getElementById('kitapBittiBtn');
        if (kitapBittiBtn) kitapBittiBtn.addEventListener('click', function() { bitisYuzdeInput.value = '100'; });

        if (resetBtn) resetBtn.addEventListener('click', function() {
            if (!confirm("Yarım kalan seans silinecek. Yeni seans başlatmak ister misiniz?")) return;
            localStorage.removeItem(storageKey);
            pausedAccumulatedSeconds = 0;
            startTime = null;
            updateDisplay();
            if (statusText) { statusText.innerText = "Seansı başlatmak için Dokunun"; statusText.style.color = "#10b981"; }
            resetBtn.classList.remove('visible');
        });

        saveForm.addEventListener('submit', function() {
            localStorage.removeItem(storageKey);
        });
    }

    if (document.getElementById('vazgecLink')) {
        document.getElementById('vazgecLink').addEventListener('click', function(e) {
            if (getCurrentSeconds() > 0 && !confirm("Kaydedilmemiş okuma süreniz var. Çıkmak istediğinize emin misiniz?")) e.preventDefault();
        });
    }

    if (document.getElementById('anaSayfaBtn')) {
        document.getElementById('anaSayfaBtn').addEventListener('click', function() {
            if (!confirm('Ana sayfaya dönmek istediğinize emin misiniz?')) return;
            window.location.href = 'index.php';
        });
    }

    setInterval(function() { fetch('eoku.php?ping=1').catch(function() {}); }, 600000);

    // Alıntı modal (basit: sadece yükle)
    const alintiModal = document.getElementById('alintiModal');
    const alintiFileInput = document.getElementById('alintiFileInput');
    const alintiPreviewWrap = document.getElementById('alintiPreviewWrap');
    const alintiPreviewImg = document.getElementById('alintiPreviewImg');
    const alintiActions = document.getElementById('alintiActions');
    const alintiUploadFullBtn = document.getElementById('alintiUploadFullBtn');

    if (document.getElementById('alintiEkleBtn')) {
        document.getElementById('alintiEkleBtn').addEventListener('click', function() {
            if (isRunning) { pauseTimer(); timerPausedByModal = true; }
            alintiFileInput.value = '';
            alintiPreviewWrap.style.display = 'none';
            alintiActions.style.display = 'none';
            alintiModal.style.display = 'flex';
            alintiFileInput.click();
        });
    }
    if (document.getElementById('alintiModalCloseBtn')) {
        document.getElementById('alintiModalCloseBtn').addEventListener('click', function() {
            alintiModal.style.display = 'none';
            if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
        });
    }
    alintiFileInput.addEventListener('change', function() {
        const file = this.files && this.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        alintiPreviewImg.src = url;
        alintiPreviewWrap.style.display = 'block';
        alintiActions.style.display = 'flex';
    });
    if (alintiUploadFullBtn) {
        alintiUploadFullBtn.addEventListener('click', function() {
            const file = alintiFileInput.files && alintiFileInput.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'alinti_foto_upload');
            fd.append('book_id', String(bookId));
            fd.append('foto', file, file.name || 'alinti.jpg');
            fetch('eoku.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        alintiModal.style.display = 'none';
                        if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
                        if (statusText) { statusText.textContent = 'Alıntı eklendi.'; statusText.style.color = '#10b981'; setTimeout(function() { statusText.textContent = 'Okuma devam ediyor...'; statusText.style.color = ''; }, 2500); }
                    } else { alert(data.error || 'Yükleme başarısız.'); }
                })
                .catch(function() { alert('Yükleme başarısız.'); });
        });
    }

    // Düşünce modal (sayfa alanı yok)
    const dusunceModal = document.getElementById('dusunceModal');
    const dusunceEditor = document.getElementById('dusunceEditor');
    const dusunceHidden = document.getElementById('dusunceHidden');
    const dusunceTranscribeArea = document.getElementById('dusunceTranscribeArea');

    function syncDusunceToHidden() {
        const html = dusunceEditor ? dusunceEditor.innerHTML.trim() : '';
        if (dusunceHidden) dusunceHidden.value = (html === '' || html === '<br>') ? '' : html;
    }

    if (document.getElementById('dusunceEkleBtn')) {
        document.getElementById('dusunceEkleBtn').addEventListener('click', function() {
            if (isRunning) { pauseTimer(); timerPausedByModal = true; }
            dusunceEditor.innerHTML = '';
            dusunceHidden.value = '';
            dusunceTranscribeArea.value = '';
            dusunceModal.style.display = 'flex';
            dusunceEditor.focus();
        });
    }
    if (document.getElementById('dusunceModalCloseBtn')) {
        document.getElementById('dusunceModalCloseBtn').addEventListener('click', function() {
            dusunceModal.style.display = 'none';
            if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
        });
    }
    if (document.getElementById('dusunceKaydetBtn')) {
        document.getElementById('dusunceKaydetBtn').addEventListener('click', function() {
            syncDusunceToHidden();
            const metin = dusunceHidden ? dusunceHidden.value.trim() : '';
            if (metin === '') { alert('Lütfen düşüncenizi yazın.'); return; }
            const fd = new FormData();
            fd.append('action', 'dusunce_kaydet');
            fd.append('book_id', String(bookId));
            fd.append('dusunce', metin);
            fetch('eoku.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        dusunceModal.style.display = 'none';
                        if (timerPausedByModal) { startTimer(); timerPausedByModal = false; }
                        if (statusText) { statusText.textContent = 'Düşünce kaydedildi.'; statusText.style.color = '#10b981'; setTimeout(function() { statusText.textContent = 'Okuma devam ediyor...'; statusText.style.color = ''; }, 2500); }
                    } else { alert(data.error || 'Kayıt başarısız.'); }
                })
                .catch(function() { alert('Kayıt başarısız.'); });
        });
    }

    window.onload = function() {
        if (!kitapBitti) {
            updateClock();
            setInterval(updateClock, 1000);
        }
    };
})();
</script>
</body>
</html>
