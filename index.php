<?php
// Yapılandırma ve veritabanı bağlantısını dahil et
require_once 'config/config.php';

// Güvenlik: Kullanıcı giriş yapmamışsa login sayfasına yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Çıkış yapma işlemi
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Kullanıcıya ait kitapları, durumlarını ve okuma özetini veritabanından çek
// Okuma özeti: ilk baslama, son okunan sayfa, toplam süre (ilerleme çubuğu için)
try {
    $stmt = $pdo->prepare("
        SELECT k.*, d.durum as durum_adi, kt.ad as kitap_tipi_adi,
               o.ilk_baslama, o.son_sayfa, o.toplam_saniye, o.son_seans_baslama, o.toplam_okunan_sayfa,
               o.son_yuzde, o.son_sure_saniye,
               COALESCE(al.alinti_sayisi, 0) as alinti_sayisi,
               COALESCE(du.dusunce_sayisi, 0) as dusunce_sayisi
        FROM kitaplar k
        LEFT JOIN durum d ON k.durum_id = d.id
        LEFT JOIN kitap_tipleri kt ON k.kitap_tipi_id = kt.id
        LEFT JOIN (
            SELECT book_id,
                   MIN(baslama) as ilk_baslama,
                   MAX(bitis_sayfasi) as son_sayfa,
                   SUM(sure_saniye) as toplam_saniye,
                   MAX(baslama) as son_seans_baslama,
                   SUM(GREATEST(0, COALESCE(bitis_sayfasi, baslama_sayfasi) - baslama_sayfasi + 1)) as toplam_okunan_sayfa,
                   MAX(bitis_yuzde) as son_yuzde,
                   MAX(bitis_sure_saniye) as son_sure_saniye
            FROM okumalar
            WHERE user_id = :user_id
            GROUP BY book_id
        ) o ON k.id = o.book_id
        LEFT JOIN (SELECT kitap_id, COUNT(*) as alinti_sayisi FROM alintilar GROUP BY kitap_id) al ON k.id = al.kitap_id
        LEFT JOIN (SELECT kitap_id, COUNT(*) as dusunce_sayisi FROM dusunceler GROUP BY kitap_id) du ON k.id = du.kitap_id
        WHERE k.user_id = :user_id
        ORDER BY (o.son_seans_baslama IS NULL), o.son_seans_baslama DESC, k.id DESC
    ");
    $stmt->execute(['user_id' => $user_id]);
    $kitaplar = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// Saniyeyi ss:dd:ss (saat:dakika:saniye) formatına çevir
function sure_format_ssddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d:%02d', (int) floor($s / 3600), (int) floor(fmod($s / 60, 60)), $s % 60);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Sayfa - Reading App</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f9fafb;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-weight: bold;
            transition: opacity 0.2s;
            display: inline-block;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-primary { background-color: #3b82f6; }
        .btn-secondary { background-color: #10b981; }
        .btn-read {
            background-color: #f59e0b;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-weight: bold;
            display: inline-block;
        }
        .btn-read:hover { background-color: #d97706; }
        /* Kitap kartları grid */
        .book-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1.5rem;
        }
        .book-card {
            max-width: 180px;
        }
        .cover-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 2/3;
            border-radius: 6px;
            overflow: hidden;
            background-color: #e5e7eb;
        }
        .cover-wrap a.cover-link {
            display: block;
            width: 100%;
            height: 100%;
            text-decoration: none;
        }
        .cover-wrap .cover-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .cover-wrap .cover-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: center;
        }
        .cover-wrap .btn-edit-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(0,0,0,0.5);
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            line-height: 1;
        }
        .cover-wrap .btn-edit-dot:hover {
            background: rgba(0,0,0,0.7);
        }
        .cover-wrap .cover-badge {
            position: absolute;
            bottom: 6px;
            left: 6px;
            right: 6px;
            background: rgba(0,0,0,0.75);
            color: #fff;
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 4px;
            text-align: center;
        }
        .book-title { font-weight: 600; font-size: 0.9rem; margin-top: 0.5rem; line-height: 1.25; color: #1f2937; }
        .book-title a { color: #1e40af; text-decoration: none; }
        .book-title a:hover { text-decoration: underline; }
        .book-author {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.2rem;
        }
        .book-meta {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #4b5563;
        }
        .progress-bar-wrap {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.4rem;
        }
        .progress-bar-fill {
            height: 100%;
            background: #10b981;
            border-radius: 3px;
            transition: width 0.2s;
        }
        .tahmini-kalan {
            background-color: #1e40af;
            color: white;
            padding: 0.5rem 0.6rem;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            font-variant-numeric: tabular-nums;
            text-align: center;
        }
        .tahmini-kalan strong { font-weight: 700; }
        .toplam-kalan-wrap {
            margin-top: 1.5rem;
            padding: 0.75rem 1rem;
            background-color:rgb(146, 146, 146);
            color: white;
            border-radius: 8px;
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        .toplam-kalan-label { font-size: 0.9rem; margin-right: 0.5rem; }
        .toplam-kalan-sure { font-size: 1.1rem; font-weight: 700; }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
    </style>
</head>
<body>

<?php $nav_current = ''; include __DIR__ . '/includes/nav.php'; ?>

<div class="container">

    <?php if (count($kitaplar) > 0): ?>
        <?php $toplam_tahmini_saniye = 0; ?>
        <div class="book-grid">
            <?php foreach ($kitaplar as $kitap): ?>
                <?php
                $tip_id = (int)($kitap['kitap_tipi_id'] ?? 1);
                $sayfa = (int) $kitap['sayfa'];
                $baslangic = isset($kitap['baslangic_sayfa']) ? (int)$kitap['baslangic_sayfa'] : 1;
                $bitis_eff = (isset($kitap['bitis_sayfa']) && $kitap['bitis_sayfa'] !== null && $kitap['bitis_sayfa'] !== '') ? (int)$kitap['bitis_sayfa'] : $sayfa;
                if ($bitis_eff < 1) {
                    $bitis_eff = $sayfa;
                }
                $son_sayfa = (int) ($kitap['son_sayfa'] ?? 0);
                $toplam_okunabilir = max(0, $bitis_eff - $baslangic + 1);
                $okunan = max(0, min($son_sayfa - $baslangic + 1, $toplam_okunabilir));
                $yuzde = 0;
                if ($tip_id === 1) {
                    $yuzde = $toplam_okunabilir > 0 ? min(100, (int) round(($okunan / $toplam_okunabilir) * 100)) : 0;
                } elseif ($tip_id === 2) {
                    $son_yuzde_val = isset($kitap['son_yuzde']) && $kitap['son_yuzde'] !== null && $kitap['son_yuzde'] !== '' ? (float)$kitap['son_yuzde'] : 0;
                    $yuzde = min(100, (int) round($son_yuzde_val));
                } elseif ($tip_id === 3) {
                    $sesli_toplam = (int)($kitap['sesli_toplam_saniye'] ?? 0);
                    $son_sure = (int)($kitap['son_sure_saniye'] ?? 0);
                    $yuzde = ($sesli_toplam > 0 && $son_sure > 0) ? min(100, (int) round($son_sure / $sesli_toplam * 100)) : 0;
                }
                $toplam_okunan_sayfa = (int) ($kitap['toplam_okunan_sayfa'] ?? 0);
                $toplam_saniye_kitap = (int) ($kitap['toplam_saniye'] ?? 0);
                $tahmini_goster = false;
                $tahmini_saniye = 0;
                if ((int)$kitap['durum_id'] === 2) {
                    if ($tip_id === 1) {
                        $tahmini_goster = $toplam_okunan_sayfa > 0;
                        $kalan_sayfa = max(0, $bitis_eff - $son_sayfa);
                        $saniye_per_sayfa = $toplam_okunan_sayfa > 0 ? $toplam_saniye_kitap / $toplam_okunan_sayfa : 0;
                        $tahmini_saniye = ($kalan_sayfa > 0 && $saniye_per_sayfa > 0) ? (int) round($kalan_sayfa * $saniye_per_sayfa) : 0;
                    } elseif ($tip_id === 2) {
                        $kalan_yuzde = max(0, 100 - $yuzde);
                        if ($kalan_yuzde > 0 && $yuzde > 0 && $toplam_saniye_kitap > 0) {
                            $tahmini_goster = true;
                            $tahmini_saniye = (int) round($toplam_saniye_kitap / $yuzde * $kalan_yuzde);
                        }
                    } elseif ($tip_id === 3) {
                        $sesli_toplam = (int)($kitap['sesli_toplam_saniye'] ?? 0);
                        $son_sure = (int)($kitap['son_sure_saniye'] ?? 0);
                        $kalan_sure = max(0, $sesli_toplam - $son_sure);
                        if ($kalan_sure > 0) {
                            $tahmini_goster = true;
                            $tahmini_saniye = $kalan_sure;
                        }
                    }
                    $toplam_tahmini_saniye += $tahmini_saniye;
                }
                $devam_url = $tip_id === 1 ? 'oku.php' : ($tip_id === 2 ? 'eoku.php' : 'dinle.php');
                $devam_label = $tip_id === 1 ? 'Okumaya devam et' : ($tip_id === 2 ? 'Okumaya devam et' : 'Dinlemeye devam et');
                ?>
                <div class="book-card">
                    <?php if ($tahmini_goster): ?>
                    <div class="tahmini-kalan">
                        <?php if ($tahmini_saniye > 0): ?>
                        <?= sure_format_ssddss($tahmini_saniye) ?>
                        <?php else: ?>
                        Bitti
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="cover-wrap">
                        <a href="<?= $devam_url ?>?id=<?= (int) $kitap['id'] ?>" class="cover-link" aria-label="<?= $devam_label ?>">
                            <?php if (!empty($kitap['kapak'])): ?>
                                <img src="assets/uploads/<?= htmlspecialchars($kitap['kapak']) ?>" class="cover-img" alt="<?= htmlspecialchars($kitap['baslik']) ?>">
                            <?php else: ?>
                                <div class="cover-placeholder">Kapak Yok</div>
                            <?php endif; ?>
                        </a>
                        <?php if (((int)($kitap['alinti_sayisi'] ?? 0)) > 0 || ((int)($kitap['dusunce_sayisi'] ?? 0)) > 0): ?>
                            <span class="cover-badge"><?= (int)($kitap['alinti_sayisi'] ?? 0) ?> alıntı · <?= (int)($kitap['dusunce_sayisi'] ?? 0) ?> düşünce</span>
                        <?php endif; ?>
                        <a href="kitap.php?id=<?= (int) $kitap['id'] ?>" class="btn-edit-dot" title="Kitabı düzenle">&#8230;</a>
                    </div>
                    <div class="book-title"><a href="kitap.php?id=<?= (int)$kitap['id'] ?>"><?= htmlspecialchars($kitap['baslik']) ?></a></div>
                    <div class="book-author"><?= htmlspecialchars($kitap['yazar']) ?></div>
                    <?php if ((int)$kitap['durum_id'] === 2): ?>
                        <div class="progress-bar-wrap" title="<?= $yuzde ?>% okundu">
                            <div class="progress-bar-fill" style="width: <?= $yuzde ?>%;"></div>
                        </div>
                        <div class="book-meta">
                            <?php if (!empty($kitap['ilk_baslama'])): ?>
                                <div><?= date('d.m.Y', strtotime($kitap['ilk_baslama'])) ?></div>
                            <?php endif; ?>
                            <?php if ($tip_id === 2): ?>
                                <div><?= $yuzde ?>%</div>
                            <?php elseif ($tip_id === 3): ?>
                                <?php
                                $ss = (int)($kitap['son_sure_saniye'] ?? 0);
                                $st = (int)($kitap['sesli_toplam_saniye'] ?? 0);
                                $pct = ($st > 0 && $ss > 0) ? min(100, (int)round($ss / $st * 100)) : 0;
                                ?>
                                <div><?= sure_format_ssddss($ss) ?> / <?= sure_format_ssddss($st) ?> (<?= $pct ?>%)</div>
                            <?php elseif (isset($kitap['toplam_saniye']) && (int)$kitap['toplam_saniye'] > 0): ?>
                                <div><?= sure_format_ssddss($kitap['toplam_saniye']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($toplam_tahmini_saniye > 0): ?>
        <div class="toplam-kalan-wrap">
            <span class="toplam-kalan-label">Toplam kalan okuma süresi:</span>
            <span class="toplam-kalan-sure"><?= sure_format_ssddss($toplam_tahmini_saniye) ?></span>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <h3>Henüz hiç kitap eklemediniz.</h3>
            <p>Üstteki <strong>+ Kitap Ekle</strong> butonuna tıklayarak ilk kitabınızı ekleyin.</p>
        </div>
    <?php endif; ?>

</div>

</body>
</html>