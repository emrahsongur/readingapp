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
        .navbar {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 0 rgba(0,0,0,0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .navbar h1 { margin: 0; font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }
        .navbar h1 a {
            color: #1e40af;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .navbar h1 a:hover { color: #2563eb; }
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .user-info a {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            color: #475569;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .user-info a[href="kitaplar.php"]:hover,
        .user-info a[href="raflar.php"]:hover {
            background-color: #f1f5f9;
            color: #1e293b;
        }
        .user-info a[href="okumalar.php"]:hover {
            background-color: #f1f5f9;
            color: #1e293b;
        }
        .user-info .nav-current {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #1e293b;
            background-color: #e2e8f0;
        }
        .user-info .nav-btn-primary {
            background-color: #2563eb;
            color: white !important;
        }
        .user-info .nav-btn-primary:hover {
            background-color: #1d4ed8 !important;
            color: white !important;
        }
        .user-info .nav-btn-secondary {
            background-color: #10b981;
            color: white !important;
        }
        .user-info .nav-btn-secondary:hover {
            background-color: #059669 !important;
            color: white !important;
        }
        .user-info .btn-logout {
            color: #dc2626;
            background-color: transparent;
        }
        .user-info .btn-logout:hover {
            background-color: #fef2f2;
            color: #b91c1c;
        }
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1rem; }
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
    </style>
</head>
<body>

<nav class="navbar">
    <h1><a href="index.php">Reading App</a></h1>
    <div class="user-info">
        <a href="kitaplar.php">Kitaplar</a>
        <a href="raflar.php">Raflar</a>
        <span class="nav-current">Okumalar</span>
        <a href="kitap.php" class="nav-btn-primary">+ Kitap Ekle</a>
        <a href="raf.php" class="nav-btn-secondary">+ Raf Ekle</a>
        <a href="ayarlar.php">Ayarlar</a>
        <a href="logout.php" class="btn-logout" title="<?= htmlspecialchars($_SESSION['ad_soyad']) ?>">Çıkış Yap</a>
    </div>
</nav>

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
        <?php foreach ($gunlere_gore as $gun => $veri): ?>
            <?php
            $gun_tarih = new DateTime($gun);
            $aylar = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
            $gun_etiket = $gun_tarih->format('j') . ' ' . $aylar[(int) $gun_tarih->format('n')] . ' ' . $gun_tarih->format('Y');
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
