<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Tarih filtresi: varsayılan son 7 gün (7 gün öncesi + bugün dahil = 8 takvim günü, tüm seanslar görünsün)
$baslangic = isset($_GET['baslangic']) ? $_GET['baslangic'] : date('Y-m-d', strtotime('-7 days'));
$bitis = isset($_GET['bitis']) ? $_GET['bitis'] : date('Y-m-d');
// Kitap filtresi (isteğe bağlı)
$kitap_id = isset($_GET['kitap_id']) ? (int) $_GET['kitap_id'] : 0;
// Sayfalama: sayfa başına 25, 50 veya 100 seans
$per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 25;
if (!in_array($per_page, [25, 50, 100], true)) {
    $per_page = 25;
}
$sayfa = isset($_GET['sayfa']) ? max(1, (int) $_GET['sayfa']) : 1;
$offset = ($sayfa - 1) * $per_page;

try {
    // Filtrelenen aralıkta en az bir seansı olan kitaplar (kitap dropdown için)
    $stmtKitaplar = $pdo->prepare("
        SELECT DISTINCT k.id, k.baslik
        FROM okumalar o
        JOIN kitaplar k ON o.book_id = k.id AND k.user_id = :user_id
        WHERE o.user_id = :user_id2
          AND DATE(o.baslama) >= :baslangic
          AND DATE(o.baslama) <= :bitis
        ORDER BY k.baslik ASC
    ");
    $stmtKitaplar->execute([
        'user_id' => $user_id,
        'user_id2' => $user_id,
        'baslangic' => $baslangic,
        'bitis' => $bitis
    ]);
    $filtre_kitaplari = $stmtKitaplar->fetchAll(PDO::FETCH_ASSOC);

    // Toplam seans sayısı (sayfalama için)
    $where_kitap = $kitap_id > 0 ? ' AND o.book_id = :book_id' : '';
    $params_count = ['user_id' => $user_id, 'baslangic' => $baslangic, 'bitis' => $bitis];
    if ($kitap_id > 0) {
        $params_count['book_id'] = $kitap_id;
    }
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM okumalar o
        WHERE o.user_id = :user_id
          AND DATE(o.baslama) >= :baslangic
          AND DATE(o.baslama) <= :bitis
          $where_kitap
    ");
    $stmtCount->execute($params_count);
    $toplam_seans = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $toplam_sayfa_sayisi = $toplam_seans > 0 ? (int) ceil($toplam_seans / $per_page) : 1;
    if ($sayfa > $toplam_sayfa_sayisi) {
        $sayfa = max(1, $toplam_sayfa_sayisi);
        $offset = ($sayfa - 1) * $per_page;
    }

    // Sayfalı seans listesi
    $params = ['user_id' => $user_id, 'baslangic' => $baslangic, 'bitis' => $bitis, 'limit' => $per_page, 'offset' => $offset];
    if ($kitap_id > 0) {
        $params['book_id'] = $kitap_id;
    }
    $stmt = $pdo->prepare("
        SELECT o.id, o.book_id, o.baslama, o.bitis, o.sure_saniye, o.baslama_sayfasi, o.bitis_sayfasi,
               k.baslik as kitap_baslik, k.kapak
        FROM okumalar o
        LEFT JOIN kitaplar k ON o.book_id = k.id
        WHERE o.user_id = :user_id
          AND DATE(o.baslama) >= :baslangic
          AND DATE(o.baslama) <= :bitis
          " . ($kitap_id > 0 ? ' AND o.book_id = :book_id' : '') . "
        ORDER BY o.baslama DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':baslangic', $baslangic, PDO::PARAM_STR);
    $stmt->bindValue(':bitis', $bitis, PDO::PARAM_STR);
    if ($kitap_id > 0) {
        $stmt->bindValue(':book_id', $kitap_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $okumalar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtrelenen tüm seanslar için toplam/ortalama (sayfalama dışı)
    $stmtToplam = $pdo->prepare("
        SELECT COUNT(*) as adet,
               COALESCE(SUM(o.sure_saniye), 0) as toplam_saniye,
               COALESCE(SUM(GREATEST(0, COALESCE(o.bitis_sayfasi, o.baslama_sayfasi) - o.baslama_sayfasi + 1)), 0) as toplam_sayfa
        FROM okumalar o
        WHERE o.user_id = :user_id
          AND DATE(o.baslama) >= :baslangic
          AND DATE(o.baslama) <= :bitis
          $where_kitap
    ");
    $stmtToplam->execute($params_count);
    $genel_toplam = $stmtToplam->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// Günlük gruplama: toplam saniye ve toplam sayfa
$gunlere_gore = [];
foreach ($okumalar as $o) {
    $gun = date('Y-m-d', strtotime($o['baslama']));
    if (!isset($gunlere_gore[$gun])) {
        $gunlere_gore[$gun] = ['toplam_saniye' => 0, 'toplam_sayfa' => 0, 'seanslar' => []];
    }
    $gunlere_gore[$gun]['toplam_saniye'] += (int) $o['sure_saniye'];
    $sayfa_adedi = (int)($o['bitis_sayfasi'] ?? 0) - (int)$o['baslama_sayfasi'] + 1;
    if ($sayfa_adedi < 1) {
        $sayfa_adedi = 0;
    }
    $gunlere_gore[$gun]['toplam_sayfa'] += $sayfa_adedi;
    $gunlere_gore[$gun]['seanslar'][] = $o;
}

function sure_format_ssddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d:%02d', (int) floor($s / 3600), (int) floor(fmod($s / 60, 60)), $s % 60);
}

function sure_format_ddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d', (int) floor($s / 60), $s % 60);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Okumalar - Reading App</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f9fafb;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }
        .container { width: 80%; max-width: 1400px; margin: 2rem auto; padding: 0 1rem; box-sizing: border-box; }
        .day-group {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .day-header {
            background-color: #f3f4f6;
            padding: 0.75rem 1rem;
            font-weight: 700;
            color: #374151;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .day-total {
            font-variant-numeric: tabular-nums;
            color: #10b981;
            font-size: 1rem;
        }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 0.75rem 1rem; border-bottom: 1px solid #e5e7eb; }
        th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #4b5563;
            font-size: 0.9rem;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #f9fafb; }
        .cover-thumb {
            width: 64px;
            height: 96px;
            object-fit: cover;
            border-radius: 4px;
            background-color: #e5e7eb;
        }
        .cover-thumb-placeholder {
            width: 64px;
            height: 96px;
            border-radius: 4px;
            background-color: #e5e7eb;
            font-size: 0.6rem;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .filter-form { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .filter-form input[type="date"] { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; }
        .filter-form button { padding: 0.5rem 1rem; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        .pagination { display: flex; align-items: center; gap: 1rem; margin: 1.5rem 0; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 0.4rem 0.75rem; border-radius: 4px; text-decoration: none; color: #2563eb; border: 1px solid #d1d5db; background: #fff; font-size: 0.9rem; }
        .pagination a:hover { background: #eff6ff; }
        .pagination .current { background: #2563eb; color: white; border-color: #2563eb; }
        .pagination .disabled { color: #9ca3af; pointer-events: none; }
        .genel-ozet {
            margin-top: 2rem; padding: 1.25rem 1.5rem; background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 8px; font-weight: 600; color: #166534;
            display: flex; flex-wrap: wrap; gap: 1rem 2rem; align-items: center;
        }
        .genel-ozet .label { font-weight: 700; color: #14532d; }
    </style>
</head>
<body>

<?php $nav_current = 'okumalar'; include __DIR__ . '/includes/nav.php'; ?>

<div class="container">
    <h2 style="margin-top: 0;">Okuma Seansları</h2>

    <form method="get" action="okumalar.php" class="filter-form">
        <label for="baslangic">Başlangıç:</label>
        <input type="date" id="baslangic" name="baslangic" value="<?= htmlspecialchars($baslangic) ?>">
        <label for="bitis">Bitiş:</label>
        <input type="date" id="bitis" name="bitis" value="<?= htmlspecialchars($bitis) ?>">
        <label for="kitap_id">Kitap:</label>
        <select id="kitap_id" name="kitap_id">
            <option value="">Tüm kitaplar</option>
            <?php foreach ($filtre_kitaplari as $fk): ?>
                <option value="<?= (int) $fk['id'] ?>"<?= $kitap_id === (int) $fk['id'] ? ' selected' : '' ?>><?= htmlspecialchars($fk['baslik']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="per_page" value="<?= (int) $per_page ?>">
        <button type="submit">Filtrele</button>
    </form>

    <?php if (count($gunlere_gore) > 0): ?>
        <?php
        $gun_adi = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        foreach ($gunlere_gore as $gun => $veri):
            $gun_tarih = new DateTime($gun);
            $aylar = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
            $gun_etiket = $gun_tarih->format('j') . ' ' . $aylar[(int) $gun_tarih->format('n')] . ' ' . $gun_tarih->format('Y') . ' – ' . $gun_adi[(int) $gun_tarih->format('w')];
            $gun_toplam_sayfa = (int) ($veri['toplam_sayfa'] ?? 0);
            $gun_ortalama_ddss = $gun_toplam_sayfa > 0 ? sure_format_ddss((int) round($veri['toplam_saniye'] / $gun_toplam_sayfa)) : '—';
            ?>
            <section class="day-group">
                <div class="day-header">
                    <span><?= $gun_etiket ?></span>
                    <span class="day-total">
                        Toplam: <?= sure_format_ssddss($veri['toplam_saniye']) ?>
                        <?php if ($gun_toplam_sayfa > 0): ?>
                            | <?= $gun_toplam_sayfa ?> sayfa | Sayfa başı ortalama: <?= $gun_ortalama_ddss ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="50">Kapak</th>
                                <th>Tarih / Saat</th>
                                <th>Kitap</th>
                                <th>Başlama sayfası</th>
                                <th>Bitiş sayfası</th>
                                <th>Okunan sayfa</th>
                                <th>Süre</th>
                                <th>Sayfa başı</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($veri['seanslar'] as $o):
                                $sayfa_adedi = (int)($o['bitis_sayfasi'] ?? 0) - (int)$o['baslama_sayfasi'] + 1;
                                if ($sayfa_adedi < 1) {
                                    $sayfa_adedi = 1;
                                }
                                $saniye_per_sayfa = (int) round((int)$o['sure_saniye'] / $sayfa_adedi);
                                $sayfa_basi_ddss = sure_format_ddss($saniye_per_sayfa);
                            ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($o['kapak'])): ?>
                                            <img src="assets/uploads/<?= htmlspecialchars($o['kapak']) ?>" class="cover-thumb" alt="">
                                        <?php else: ?>
                                            <div class="cover-thumb-placeholder">—</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($o['baslama'])) ?></td>
                                    <td>
                                        <a href="kitap.php?id=<?= (int)($o['book_id'] ?? 0) ?>"><?= htmlspecialchars($o['kitap_baslik'] ?? '—') ?></a>
                                    </td>
                                    <td><?= (int) $o['baslama_sayfasi'] ?></td>
                                    <td><?= $o['bitis_sayfasi'] !== null ? (int) $o['bitis_sayfasi'] : '—' ?></td>
                                    <td><?= $sayfa_adedi > 0 ? $sayfa_adedi : '—' ?></td>
                                    <td><?= sure_format_ssddss($o['sure_saniye']) ?></td>
                                    <td><?= $sayfa_basi_ddss ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight: 600; background-color: #f9fafb;">
                                <td colspan="5">Toplam</td>
                                <td><?= $gun_toplam_sayfa > 0 ? (int) $gun_toplam_sayfa : '—' ?></td>
                                <td><?= sure_format_ssddss($veri['toplam_saniye']) ?></td>
                                <td><?= $gun_ortalama_ddss ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (!empty($genel_toplam) && (int)($genel_toplam['adet'] ?? 0) > 0): ?>
            <?php
            $gt_saniye = (int)($genel_toplam['toplam_saniye'] ?? 0);
            $gt_sayfa = (int)($genel_toplam['toplam_sayfa'] ?? 0);
            $gt_ortalama_ddss = $gt_sayfa > 0 ? sure_format_ddss((int) round($gt_saniye / $gt_sayfa)) : '—';
            ?>
            <div class="genel-ozet">
                <span class="label">Filtrelenen tüm seanslar:</span>
                <span><?= (int) $genel_toplam['adet'] ?> seans</span>
                <span>Toplam süre: <?= sure_format_ssddss($gt_saniye) ?></span>
                <span>Toplam sayfa: <?= $gt_sayfa ?></span>
                <span>Sayfa başı ortalama: <?= $gt_ortalama_ddss ?></span>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <h3>Henüz okuma seansı kaydı yok.</h3>
            <p>Ana sayfadan bir kitaba <strong>Oku</strong> ile başlayarak seans kaydedebilirsiniz.</p>
        </div>
    <?php endif; ?>

    <?php
    // Sayfalama linkleri için parametre dizisi (mevcut filtreleri korur)
    $base_params = [
        'baslangic' => $baslangic,
        'bitis' => $bitis,
        'per_page' => $per_page
    ];
    if ($kitap_id > 0) {
        $base_params['kitap_id'] = $kitap_id;
    }
    function pagination_url($params, $sayfa) {
        $params['sayfa'] = $sayfa;
        return 'okumalar.php?' . http_build_query($params);
    }
    ?>
    <?php if ($toplam_seans > 0): ?>
        <div class="pagination">
            <span>Sayfa başına:</span>
            <?php foreach ([25, 50, 100] as $pp): ?>
                <?php $url = 'okumalar.php?' . http_build_query(array_merge($base_params, ['per_page' => $pp, 'sayfa' => 1])); ?>
                <?php if ($pp === $per_page): ?>
                    <span class="current"><?= $pp ?></span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($url) ?>"><?= $pp ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <span style="margin-left: 0.5rem;">Toplam <?= $toplam_seans ?> seans</span>
            <?php if ($toplam_sayfa_sayisi > 1): ?>
                <span style="margin-left: 1rem;">
                    <?php if ($sayfa > 1): ?>
                        <a href="<?= htmlspecialchars(pagination_url($base_params, $sayfa - 1)) ?>">Önceki</a>
                    <?php else: ?>
                        <span class="disabled">Önceki</span>
                    <?php endif; ?>
                    <span style="margin: 0 0.5rem;">Sayfa <?= $sayfa ?> / <?= $toplam_sayfa_sayisi ?></span>
                    <?php if ($sayfa < $toplam_sayfa_sayisi): ?>
                        <a href="<?= htmlspecialchars(pagination_url($base_params, $sayfa + 1)) ?>">Sonraki</a>
                    <?php else: ?>
                        <span class="disabled">Sonraki</span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
