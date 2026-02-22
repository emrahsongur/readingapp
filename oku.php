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

// 2. SEANS KAYDETME İŞLEMİ (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_session') {
    $book_id = (int)$_POST['book_id'];
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
    </style>
</head>
<body<?= !empty($kitap['kapak']) ? ' class="has-cover"' : '' ?>>

<div class="top-bar">
    <?php if (!empty($kitap['kapak'])): ?>
        <img src="assets/uploads/<?= htmlspecialchars($kitap['kapak']) ?>" class="top-bar-cover" alt="">
    <?php endif; ?>
    <div class="top-bar-text">
        <h2><a href="kitap.php?id=<?= (int)$book_id ?>" target="_blank" rel="noopener"><?= htmlspecialchars($kitap['baslik']) ?></a></h2>
        <p><?= htmlspecialchars($kitap['yazar']) ?> &bull; Başlangıç: Sayfa <?= $baslama_sayfasi ?></p>
        <?php if ($tahmini_bitis_metin !== ''): ?>
        <p class="tahmini-bitis">Tahmini bitiş: <?= htmlspecialchars($tahmini_bitis_metin) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="timer-container">
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
        <button type="button" class="btn btn-reset" id="resetBtn">Yeni seans başlat</button>
        <a href="index.php" class="btn-cancel" onclick="return confirmExit();">Vazgeç ve Çık</a>
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

    // Arayüz Elementleri
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

    function startTimer() {
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
    }

    function pauseTimer() {
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
    }

    // --- 4. EVENT LİSTENER'LAR ---
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

    function confirmExit() {
        if (getCurrentSeconds() > 0) {
            return confirm("Kaydedilmemiş okuma süreniz var. Çıkmak istediğinize emin misiniz?");
        }
        return true;
    }

    // --- 5. HEARTBEAT ---
    setInterval(() => {
        fetch('oku.php?ping=1').catch(err => console.error("Heartbeat hatası", err));
    }, 600000);

    window.onload = () => {
        loadFromStorage();
        setInterval(updateClock, 1000);
    };
</script>

</body>
</html>