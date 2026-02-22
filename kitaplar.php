<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT k.*, d.durum as durum_adi,
               o.ilk_baslama, o.son_sayfa, o.toplam_saniye, o.son_bitis,
               COALESCE(al.alinti_sayisi, 0) as alinti_sayisi,
               COALESCE(du.dusunce_sayisi, 0) as dusunce_sayisi
        FROM kitaplar k
        LEFT JOIN durum d ON k.durum_id = d.id
        LEFT JOIN (
            SELECT book_id,
                   MIN(baslama) as ilk_baslama,
                   MAX(bitis_sayfasi) as son_sayfa,
                   SUM(sure_saniye) as toplam_saniye,
                   MAX(bitis) as son_bitis
            FROM okumalar
            WHERE user_id = :user_id
            GROUP BY book_id
        ) o ON k.id = o.book_id
        LEFT JOIN (SELECT kitap_id, COUNT(*) as alinti_sayisi FROM alintilar GROUP BY kitap_id) al ON k.id = al.kitap_id
        LEFT JOIN (SELECT kitap_id, COUNT(*) as dusunce_sayisi FROM dusunceler GROUP BY kitap_id) du ON k.id = du.kitap_id
        WHERE k.user_id = :user_id
        ORDER BY k.id DESC
    ");
    $stmt->execute(['user_id' => $user_id]);
    $kitaplar = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

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
    <title>Tüm Kitaplar - Reading App</title>
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
        .book-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1.5rem;
        }
        .book-card { max-width: 180px; }
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
        .cover-wrap .btn-edit-dot:hover { background: rgba(0,0,0,0.7); }
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
        .book-meta .durum-badge {
            display: inline-block;
            background: #e5e7eb;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
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
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
    </style>
</head>
<body>

<?php $nav_current = 'kitaplar'; include __DIR__ . '/includes/nav.php'; ?>

<div class="container">
    <h2 style="margin-top: 0;">Tüm Kitaplar</h2>

    <?php if (count($kitaplar) > 0): ?>
        <div class="book-grid">
            <?php foreach ($kitaplar as $kitap): ?>
                <?php
                $sayfa = (int) $kitap['sayfa'];
                $baslangic = isset($kitap['baslangic_sayfa']) ? (int)$kitap['baslangic_sayfa'] : 1;
                $bitis_eff = (isset($kitap['bitis_sayfa']) && $kitap['bitis_sayfa'] !== null && $kitap['bitis_sayfa'] !== '') ? (int)$kitap['bitis_sayfa'] : $sayfa;
                if ($bitis_eff < 1) {
                    $bitis_eff = $sayfa;
                }
                $son_sayfa = (int) ($kitap['son_sayfa'] ?? 0);
                $toplam_okunabilir = max(0, $bitis_eff - $baslangic + 1);
                $okunan = max(0, min($son_sayfa - $baslangic + 1, $toplam_okunabilir));
                $yuzde = $toplam_okunabilir > 0 ? min(100, (int) round(($okunan / $toplam_okunabilir) * 100)) : 0;
                $has_seans = (isset($kitap['toplam_saniye']) && (int)$kitap['toplam_saniye'] > 0) || !empty($kitap['ilk_baslama']) || !empty($kitap['son_bitis']);
                ?>
                <div class="book-card">
                    <div class="cover-wrap">
                        <a href="oku.php?id=<?= (int) $kitap['id'] ?>" class="cover-link" aria-label="Okumaya devam et">
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
                    <div class="book-meta">
                        <span class="durum-badge"><?= htmlspecialchars($kitap['durum_adi'] ?? 'Belirtilmedi') ?></span>
                        <div><?= $sayfa ?> sayfa</div>
                        <?php if ((int)$kitap['durum_id'] === 2 && $bitis_eff > 0): ?>
                            <div><?= $son_sayfa ?>/<?= $bitis_eff ?></div>
                        <?php endif; ?>
                        <?php if ((int)$kitap['durum_id'] === 2): ?>
                            <div class="progress-bar-wrap" title="<?= $yuzde ?>% okundu">
                                <div class="progress-bar-fill" style="width: <?= $yuzde ?>%;"></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($has_seans): ?>
                            <?php if (isset($kitap['toplam_saniye']) && (int)$kitap['toplam_saniye'] > 0): ?>
                                <div><?= sure_format_ssddss($kitap['toplam_saniye']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($kitap['ilk_baslama'])): ?>
                                <div>Başlama: <?= date('d.m.Y', strtotime($kitap['ilk_baslama'])) ?></div>
                            <?php endif; ?>
                            <?php if ((int)$kitap['durum_id'] === 3 && !empty($kitap['son_bitis'])): ?>
                                <div>Bitiş: <?= date('d.m.Y', strtotime($kitap['son_bitis'])) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>Henüz hiç kitap yok.</h3>
            <p>Üstteki <strong>+ Kitap Ekle</strong> ile kitap ekleyebilirsiniz.</p>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
