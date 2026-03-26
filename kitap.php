<?php
require_once 'config/config.php';
require_once __DIR__ . '/alintilar/bootstrap.php';

// Güvenlik: Giriş yapılmamışsa yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hata_mesaji = '';
$basari_mesaji = '';

// --- ALINTI / DÜŞÜNCE POST AKSİYONLARI (kitap_id ile yönlendirme) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirect_kitap_id = isset($_POST['kitap_id']) ? (int)$_POST['kitap_id'] : 0;
    if ($redirect_kitap_id <= 0) {
        header("Location: index.php");
        exit;
    }
    // E-kitap (2) ve Sesli (3) için sayfa alanları kullanılmıyor
    $sayfa_baslangic = $_POST['sayfa_baslangic'] ?? '';
    $sayfa_bitis = $_POST['sayfa_bitis'] ?? '';
    $stmt_kt = $pdo->prepare("SELECT kitap_tipi_id FROM kitaplar WHERE id = ? AND user_id = ?");
    $stmt_kt->execute([$redirect_kitap_id, $user_id]);
    $kitap_tip_row = $stmt_kt->fetch(PDO::FETCH_ASSOC);
    if ($kitap_tip_row && (int)$kitap_tip_row['kitap_tipi_id'] !== 1) {
        $sayfa_baslangic = '';
        $sayfa_bitis = '';
    }
    $err = null;
    if ($action === 'alinti_ekle') {
        $err = alinti_ekle(
            $pdo, $user_id, $redirect_kitap_id,
            $_POST['alinti'] ?? '',
            $sayfa_baslangic,
            $sayfa_bitis,
            $_FILES['foto'] ?? null
        );
    } elseif ($action === 'alinti_guncelle') {
        $err = alinti_guncelle(
            $pdo, $user_id, (int)($_POST['alinti_id'] ?? 0), $redirect_kitap_id,
            $_POST['alinti'] ?? '',
            $sayfa_baslangic,
            $sayfa_bitis,
            $_POST['mevcut_foto'] ?? '',
            $_FILES['foto'] ?? null
        );
    } elseif ($action === 'alinti_sil') {
        $err = alinti_sil($pdo, $user_id, (int)($_POST['alinti_id'] ?? 0));
    } elseif ($action === 'dusunce_ekle') {
        $alinti_id = isset($_POST['alinti_id']) && $_POST['alinti_id'] !== '' ? (int)$_POST['alinti_id'] : null;
        $err = dusunce_ekle(
            $pdo, $user_id, $redirect_kitap_id,
            $_POST['dusunce'] ?? '',
            $sayfa_baslangic,
            $sayfa_bitis,
            $alinti_id
        );
    } elseif ($action === 'dusunce_guncelle') {
        $err = dusunce_guncelle(
            $pdo, $user_id, (int)($_POST['dusunce_id'] ?? 0), $redirect_kitap_id,
            $_POST['dusunce'] ?? '',
            $sayfa_baslangic,
            $sayfa_bitis
        );
    } elseif ($action === 'dusunce_sil') {
        $err = dusunce_sil($pdo, $user_id, (int)($_POST['dusunce_id'] ?? 0));
    }
    $q = $err ? '&alinti_hata=' . urlencode($err) : '';
    header("Location: kitap.php?id=" . $redirect_kitap_id . $q);
    exit;
}

// --- SİLME İŞLEMİ (GET ile delete_id gelirse) ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // Kitabın bu kullanıcıya ait olup olmadığını ve kapağını kontrol et
    $stmt = $pdo->prepare("SELECT kapak FROM kitaplar WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $delete_id, 'user_id' => $user_id]);
    $silinecek_kitap = $stmt->fetch();

    if ($silinecek_kitap) {
        // Alıntı fotoğraflarını sunucudan sil
        alinti_fotolarini_sil_kitap($pdo, $delete_id);
        // Varsa kapak resmini sunucudan sil
        if (!empty($silinecek_kitap['kapak']) && file_exists('assets/uploads/' . $silinecek_kitap['kapak'])) {
            unlink('assets/uploads/' . $silinecek_kitap['kapak']);
        }
        // Veritabanından sil (ON DELETE CASCADE olduğu için kitap_raf, okumalar, alintilar, dusunceler da silinir)
        $delStmt = $pdo->prepare("DELETE FROM kitaplar WHERE id = :id");
        $delStmt->execute(['id' => $delete_id]);
        
        header("Location: index.php");
        exit;
    }
}

// Form varsayılan değerleri (Ekleme modu için boş)
$kitap_id = 0;
$baslik = '';
$yazar = '';
$sayfa = 0;
$baslangic_sayfa = 1;
$bitis_sayfa = null;
$durum_id = 1; // Varsayılan: Okunacak (1)
$kitap_tipi_id = 1;
$sesli_toplam_saniye = null;
$sesli_saat = 0;
$sesli_dakika = 0;
$sesli_saniye = 0;
$mevcut_kapak = '';
$secili_raflar = [];

// --- DÜZENLEME MODU (GET ile id gelirse mevcut verileri çek) ---
if (isset($_GET['id'])) {
    $kitap_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM kitaplar WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $kitap_id, 'user_id' => $user_id]);
    $kitap = $stmt->fetch();

    if ($kitap) {
        $baslik = $kitap['baslik'];
        $yazar = $kitap['yazar'];
        $sayfa = $kitap['sayfa'];
        $baslangic_sayfa = isset($kitap['baslangic_sayfa']) ? (int)$kitap['baslangic_sayfa'] : 1;
        $bitis_sayfa = isset($kitap['bitis_sayfa']) && $kitap['bitis_sayfa'] !== null && $kitap['bitis_sayfa'] !== '' ? (int)$kitap['bitis_sayfa'] : null;
        $durum_id = $kitap['durum_id'];
        $kitap_tipi_id = isset($kitap['kitap_tipi_id']) ? (int)$kitap['kitap_tipi_id'] : 1;
        $sesli_toplam_saniye = isset($kitap['sesli_toplam_saniye']) && $kitap['sesli_toplam_saniye'] !== null && $kitap['sesli_toplam_saniye'] !== '' ? (int)$kitap['sesli_toplam_saniye'] : null;
        if ($sesli_toplam_saniye !== null && $sesli_toplam_saniye > 0) {
            $sesli_saat = (int) floor($sesli_toplam_saniye / 3600);
            $sesli_dakika = (int) floor(($sesli_toplam_saniye % 3600) / 60);
            $sesli_saniye = (int) ($sesli_toplam_saniye % 60);
        }
        $mevcut_kapak = $kitap['kapak'];

        // Kitabın kayıtlı olduğu rafları çek
        $rafStmt = $pdo->prepare("SELECT raf_id FROM kitap_raf WHERE kitap_id = :kitap_id");
        $rafStmt->execute(['kitap_id' => $kitap_id]);
        $secili_raflar = $rafStmt->fetchAll(PDO::FETCH_COLUMN); // Sadece ID'leri dizi olarak alır
    } else {
        die("Kitap bulunamadı veya yetkiniz yok.");
    }
}

// --- FORM GÖNDERME (POST İşlemi - Ekleme veya Güncelleme) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $baslik = trim($_POST['baslik']);
    $yazar = trim($_POST['yazar']);
    $sayfa = (int)$_POST['sayfa'];
    $baslangic_sayfa = isset($_POST['baslangic_sayfa']) && $_POST['baslangic_sayfa'] !== '' ? (int)$_POST['baslangic_sayfa'] : 1;
    $bitis_sayfa_raw = isset($_POST['bitis_sayfa']) && $_POST['bitis_sayfa'] !== '' ? (int)$_POST['bitis_sayfa'] : null;
    $bitis_sayfa = $bitis_sayfa_raw;
    $durum_id = (int)$_POST['durum_id'];
    $kitap_tipi_id = isset($_POST['kitap_tipi_id']) ? (int)$_POST['kitap_tipi_id'] : 1;
    $sesli_toplam_saniye = null;
    if ($kitap_tipi_id === 3) {
        $sh = (int)($_POST['sesli_saat'] ?? 0);
        $sd = (int)($_POST['sesli_dakika'] ?? 0);
        $ss = (int)($_POST['sesli_saniye'] ?? 0);
        $sesli_toplam_saniye = $sh * 3600 + $sd * 60 + $ss;
    }
    // E-kitap ve Sesli için sayfa alanları kullanılmıyor
    if ($kitap_tipi_id !== 1) {
        $sayfa = 0;
        $baslangic_sayfa = 1;
        $bitis_sayfa = null;
    }
    $kitap_id = (int)$_POST['kitap_id']; // 0 ise yeni kayıt, değilse güncelleme
    $gelen_raflar = $_POST['raflar'] ?? []; // Seçilen raflar dizisi

    // Düzenleme modunda mevcut kapağı korumak için veritabanından oku
    if ($kitap_id > 0) {
        $stmtMevcut = $pdo->prepare("SELECT kapak FROM kitaplar WHERE id = ? AND user_id = ?");
        $stmtMevcut->execute([$kitap_id, $user_id]);
        $row = $stmtMevcut->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $mevcut_kapak = $row['kapak'] ?? '';
        }
    }

    if (empty($baslik) || empty($yazar)) {
            $hata_mesaji = "Başlık ve yazar alanları zorunludur.";
    } elseif ($kitap_tipi_id === 3 && ($sesli_toplam_saniye === null || $sesli_toplam_saniye < 1)) {
        $hata_mesaji = "Sesli kitap için toplam süre (en az 1 saniye) girin.";
    } elseif ($kitap_tipi_id === 1 && $bitis_sayfa !== null && $baslangic_sayfa > $bitis_sayfa) {
        $hata_mesaji = "Bitiş sayfası, başlangıç sayfasından küçük olamaz.";
    } elseif ($kitap_tipi_id === 1 && $bitis_sayfa !== null && $sayfa > 0 && $bitis_sayfa > $sayfa) {
        $hata_mesaji = "Bitiş sayfası, toplam sayfa sayısından büyük olamaz.";
    } else {
        // 1. Kapak Fotoğrafı Yükleme İşlemi
        $kapak_adi = $mevcut_kapak; // Yeni yüklenmezse eskiyi koru
        
        if (isset($_FILES['kapak']) && $_FILES['kapak']['error'] == 0) {
            $izin_verilen_uzantilar = ['jpg', 'jpeg', 'png', 'webp'];
            $dosya_bilgisi = pathinfo($_FILES['kapak']['name']);
            $uzanti = strtolower($dosya_bilgisi['extension']);

            if (in_array($uzanti, $izin_verilen_uzantilar)) {
                // Eşsiz bir dosya adı oluştur
                $yeni_ad = uniqid('kapak_') . '.' . $uzanti;
                $hedef_yol = 'assets/uploads/' . $yeni_ad;

                // Klasör yoksa oluştur
                if (!is_dir('assets/uploads')) {
                    mkdir('assets/uploads', 0777, true);
                }

                if (move_uploaded_file($_FILES['kapak']['tmp_name'], $hedef_yol)) {
                    // Yeni dosya yüklendi, eskisi varsa sunucudan sil
                    if (!empty($mevcut_kapak) && file_exists('assets/uploads/' . $mevcut_kapak)) {
                        unlink('assets/uploads/' . $mevcut_kapak);
                    }
                    $kapak_adi = $yeni_ad;
                } else {
                    $hata_mesaji = "Görsel yüklenirken bir hata oluştu.";
                }
            } else {
                $hata_mesaji = "Sadece JPG, PNG ve WEBP formatları desteklenir.";
            }
        }

        // 2. Veritabanına Kayıt/Güncelleme
        if (empty($hata_mesaji)) {
            try {
                $pdo->beginTransaction(); // İşlemleri garantiye almak için Transaction başlat

                if ($kitap_id > 0) {
                    // GÜNCELLEME
                    $stmt = $pdo->prepare("UPDATE kitaplar SET durum_id=?, kitap_tipi_id=?, baslik=?, yazar=?, kapak=?, sayfa=?, baslangic_sayfa=?, bitis_sayfa=?, sesli_toplam_saniye=? WHERE id=? AND user_id=?");
                    $stmt->execute([$durum_id, $kitap_tipi_id, $baslik, $yazar, $kapak_adi, $sayfa, $baslangic_sayfa, $bitis_sayfa, $sesli_toplam_saniye, $kitap_id, $user_id]);
                    $islem_yapilan_kitap_id = $kitap_id;
                    $basari_mesaji = "Kitap başarıyla güncellendi.";
                } else {
                    // YENİ KAYIT
                    $stmt = $pdo->prepare("INSERT INTO kitaplar (user_id, durum_id, kitap_tipi_id, baslik, yazar, kapak, sayfa, baslangic_sayfa, bitis_sayfa, sesli_toplam_saniye) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $durum_id, $kitap_tipi_id, $baslik, $yazar, $kapak_adi, $sayfa, $baslangic_sayfa, $bitis_sayfa, $sesli_toplam_saniye]);
                    $islem_yapilan_kitap_id = $pdo->lastInsertId();
                    $basari_mesaji = "Kitap başarıyla eklendi.";
                }

                // 3. Rafları Güncelleme (Çoktan Çoğa İlişki)
                // Önce bu kitaba ait eski raf kayıtlarını sil
                $delRafStmt = $pdo->prepare("DELETE FROM kitap_raf WHERE kitap_id = ?");
                $delRafStmt->execute([$islem_yapilan_kitap_id]);

                // Sonra seçili rafları yeniden ekle
                if (!empty($gelen_raflar)) {
                    $insRafStmt = $pdo->prepare("INSERT INTO kitap_raf (kitap_id, raf_id) VALUES (?, ?)");
                    foreach ($gelen_raflar as $r_id) {
                        $insRafStmt->execute([$islem_yapilan_kitap_id, (int)$r_id]);
                    }
                }

                $pdo->commit(); // Tüm işlemleri onayla ve veritabanına yaz
                
                // Başarılı işlem sonrası: düzenlemede kitap sayfasında kal, yeni eklemede ana sayfa
                if ($islem_yapilan_kitap_id > 0) {
                    header("Location: kitap.php?id=" . $islem_yapilan_kitap_id);
                } else {
                    header("Location: index.php");
                }
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack(); // Hata varsa hiçbir işlemi yapma, geri al
                $hata_mesaji = "Kayıt hatası: " . $e->getMessage();
            }
        }
    }
}

// Form için Durumları Çek
$durumlar = $pdo->query("SELECT * FROM durum WHERE aktif = 1")->fetchAll();
// Form için Kitap Tiplerini Çek (Basılı, Elektronik, Sesli)
$kitap_tipleri = [];
try {
    $kitap_tipleri = $pdo->query("SELECT * FROM kitap_tipleri ORDER BY sira")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Migration henüz uygulanmamışsa boş kalır; formda tip seçeneği gizlenir veya default 1
}
if (empty($kitap_tipleri)) {
    $kitap_tipleri = [['id' => 1, 'ad' => 'Basılı', 'sira' => 1]];
}

// Form için Kullanıcıya Ait Rafları Çek
$stmtRaflar = $pdo->prepare("SELECT * FROM raflar WHERE user_id = :user_id ORDER BY etiket ASC");
$stmtRaflar->execute(['user_id' => $user_id]);
$kullanici_raflari = $stmtRaflar->fetchAll();

// Alıntı/düşünce akışı (sadece düzenleme modunda)
$alintilar = [];
$dusunceler = [];
$akis = []; // kronolojik: ['tip' => 'alinti'|'dusunce', 'kayit' => ts, 'veri' => ...]
if ($kitap_id > 0) {
    $alintilar = alintilar_for_kitap($pdo, $kitap_id);
    $dusunceler = dusunceler_for_kitap($pdo, $kitap_id);
    $dusunceler_by_alinti = [];
    $standalone_sayfasiz = [];  // sayfa numarası yok, alıntıya bağlı değil → tarih ASC
    $standalone_sayfali = [];  // sayfa var, alıntıya bağlı değil → sayfa ASC
    foreach ($dusunceler as $d) {
        if (!empty($d['alinti_id'])) {
            $dusunceler_by_alinti[(int)$d['alinti_id']][] = $d;
        } else {
            $sb = isset($d['sayfa_baslangic']) && $d['sayfa_baslangic'] !== '' && $d['sayfa_baslangic'] !== null ? (int)$d['sayfa_baslangic'] : null;
            $sbit = isset($d['sayfa_bitis']) && $d['sayfa_bitis'] !== '' && $d['sayfa_bitis'] !== null ? (int)$d['sayfa_bitis'] : null;
            if ($sb === null && $sbit === null) {
                $standalone_sayfasiz[] = $d;
            } else {
                $standalone_sayfali[] = $d;
            }
        }
    }
    usort($standalone_sayfasiz, function ($x, $y) { return strcmp($x['kayit'], $y['kayit']); });
    usort($standalone_sayfali, function ($x, $y) {
        $sx = (int)($x['sayfa_baslangic'] ?? 0) ?: (int)($x['sayfa_bitis'] ?? 0);
        $sy = (int)($y['sayfa_baslangic'] ?? 0) ?: (int)($y['sayfa_bitis'] ?? 0);
        return $sx - $sy;
    });
    // İşlenmemiş alıntılar: foto var ve metin placeholder/boş (mobilden yüklenip henüz Gemini ile doldurulmamış)
    $alinti_placeholder = '[Fotoğraf – metin sonra eklenecek]';
    $alintilar_islenmemis = [];
    $alintilar_islenmis = [];
    foreach ($alintilar as $a) {
        $alinti_metin = trim($a['alinti'] ?? '');
        $foto_var = !empty($a['foto']);
        $islenmemis = $foto_var && ($alinti_metin === '' || $alinti_metin === $alinti_placeholder);
        if ($islenmemis) {
            $alintilar_islenmemis[] = $a;
        } else {
            $alintilar_islenmis[] = $a;
        }
    }
    usort($alintilar_islenmemis, function ($a, $b) { return strcmp($a['kayit'], $b['kayit']); });
    usort($alintilar_islenmis, function ($a, $b) {
        $sa = (int)($a['sayfa_baslangic'] ?? 0) ?: (int)($a['sayfa_bitis'] ?? 0) ?: 999999;
        $sb = (int)($b['sayfa_baslangic'] ?? 0) ?: (int)($b['sayfa_bitis'] ?? 0) ?: 999999;
        return $sa - $sb;
    });
    foreach ($dusunceler_by_alinti as $aid => $list) {
        usort($dusunceler_by_alinti[$aid], function ($x, $y) { return strcmp($x['kayit'], $y['kayit']); });
    }
    // Önce işlenmemiş alıntılar (tepede), sonra sayfasız/sayfalı düşünceler, en sonda işlenmiş alıntılar (sayfa sırasına göre)
    foreach ($alintilar_islenmemis as $a) {
        $akis[] = ['tip' => 'alinti', 'kayit' => strtotime($a['kayit']), 'veri' => $a];
    }
    foreach ($standalone_sayfasiz as $d) {
        $akis[] = ['tip' => 'dusunce', 'kayit' => strtotime($d['kayit']), 'veri' => $d];
    }
    foreach ($standalone_sayfali as $d) {
        $akis[] = ['tip' => 'dusunce', 'kayit' => strtotime($d['kayit']), 'veri' => $d];
    }
    foreach ($alintilar_islenmis as $a) {
        $akis[] = ['tip' => 'alinti', 'kayit' => strtotime($a['kayit']), 'veri' => $a];
    }

    // Bu kitaba ait okuma seansları (tek tablo, gruplama yok)
    $kitap_okumalari = [];
    $stmtOku = $pdo->prepare("
        SELECT o.id, o.book_id, o.baslama, o.bitis, o.sure_saniye, o.baslama_sayfasi, o.bitis_sayfasi,
               o.baslama_yuzde, o.bitis_yuzde, o.baslama_sure_saniye, o.bitis_sure_saniye
        FROM okumalar o
        WHERE o.user_id = :user_id AND o.book_id = :book_id
        ORDER BY o.baslama DESC
    ");
    $stmtOku->execute(['user_id' => $user_id, 'book_id' => $kitap_id]);
    $kitap_okumalari = $stmtOku->fetchAll(PDO::FETCH_ASSOC);

    $kitap_tip_oku = (int)($kitap['kitap_tipi_id'] ?? 1);
    $toplam_saniye_kitap = 0;
    $toplam_okunan_sayfa = 0;
    $tahmini_saniye = 0;
    $tahmini_kalan_goster = false;
    $kalan_sayfa = 0;
    $saniye_per_sayfa = 0;
    $bitis_sayfa_kitap = (isset($kitap['bitis_sayfa']) && $kitap['bitis_sayfa'] !== null && $kitap['bitis_sayfa'] !== '') ? (int)$kitap['bitis_sayfa'] : (int)$kitap['sayfa'];
    $son_sayfa = isset($kitap['baslangic_sayfa']) && (int)$kitap['baslangic_sayfa'] > 0 ? (int)$kitap['baslangic_sayfa'] : 1;

    if ($kitap_tip_oku === 1) {
        foreach ($kitap_okumalari as $o) {
            $toplam_saniye_kitap += (int) $o['sure_saniye'];
            $adet = (int)($o['bitis_sayfasi'] ?? 0) - (int)$o['baslama_sayfasi'] + 1;
            if ($adet > 0) $toplam_okunan_sayfa += $adet;
        }
        $saniye_per_sayfa = $toplam_okunan_sayfa > 0 ? $toplam_saniye_kitap / $toplam_okunan_sayfa : 0;
        if (count($kitap_okumalari) > 0) {
            $son_sayfa = (int)($kitap_okumalari[0]['bitis_sayfasi'] ?? 0);
            if ($son_sayfa < 1) $son_sayfa = (int)$kitap_okumalari[0]['baslama_sayfasi'];
        }
        $kalan_sayfa = max(0, $bitis_sayfa_kitap - $son_sayfa);
        $tahmini_saniye = $toplam_okunan_sayfa > 0 && $kalan_sayfa > 0 ? (int) round($kalan_sayfa * $saniye_per_sayfa) : 0;
        $tahmini_kalan_goster = $toplam_okunan_sayfa > 0;
    } elseif ($kitap_tip_oku === 2) {
        $toplam_yuzde_delta = 0;
        $son_yuzde = 0;
        foreach ($kitap_okumalari as $o) {
            $toplam_saniye_kitap += (int)($o['sure_saniye'] ?? 0);
            $by = (float)($o['baslama_yuzde'] ?? 0);
            $bit = (float)($o['bitis_yuzde'] ?? 0);
            $d = $bit - $by;
            if ($d > 0) {
                $toplam_yuzde_delta += $d;
            }
        }
        if (count($kitap_okumalari) > 0) {
            $son_yuzde = (float)($kitap_okumalari[0]['bitis_yuzde'] ?? 0);
        }
        $kalan_yuzde = max(0, 100 - $son_yuzde);
        if ($kalan_yuzde > 0 && $son_yuzde > 0 && $toplam_saniye_kitap > 0) {
            $tahmini_saniye = (int) round($toplam_saniye_kitap / $son_yuzde * $kalan_yuzde);
        }
        $tahmini_kalan_goster = $toplam_yuzde_delta > 0 && $kalan_yuzde > 0 && (int)($kitap['durum_id'] ?? 0) === 2;
    } elseif ($kitap_tip_oku === 3) {
        foreach ($kitap_okumalari as $o) {
            $toplam_saniye_kitap += (int)($o['sure_saniye'] ?? 0);
        }
        $sesli_top = (int)($kitap['sesli_toplam_saniye'] ?? 0);
        $son_sure_k = 0;
        if (count($kitap_okumalari) > 0 && $kitap_okumalari[0]['bitis_sure_saniye'] !== null && $kitap_okumalari[0]['bitis_sure_saniye'] !== '') {
            $son_sure_k = (int)$kitap_okumalari[0]['bitis_sure_saniye'];
        }
        $kalan_sure_k = max(0, $sesli_top - $son_sure_k);
        $tahmini_saniye = $kalan_sure_k;
        $tahmini_kalan_goster = $sesli_top > 0 && $kalan_sure_k > 0 && (int)($kitap['durum_id'] ?? 0) === 2;
    }
} else {
    $kitap_okumalari = [];
    $toplam_saniye_kitap = 0;
    $toplam_okunan_sayfa = 0;
    $tahmini_kalan_goster = false;
    $tahmini_saniye = 0;
    $kalan_sayfa = 0;
}
function sure_format_ssddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d:%02d', (int) floor($s / 3600), (int) floor(fmod($s / 60, 60)), $s % 60);
}
function sure_format_ddss($saniye) {
    $s = (int) $saniye;
    return sprintf('%02d:%02d', (int) floor($s / 60), $s % 60);
}
function seans_sure_zamandan($baslama, $bitis, $fallback = 0) {
    if (!empty($baslama) && !empty($bitis)) {
        $t1 = strtotime((string)$baslama);
        $t2 = strtotime((string)$bitis);
        if ($t1 !== false && $t2 !== false && $t2 >= $t1) {
            return (int)($t2 - $t1);
        }
    }
    return max(0, (int)$fallback);
}
$alinti_hata = isset($_GET['alinti_hata']) ? trim($_GET['alinti_hata']) : '';

/** Rich text çıktı için güvenli HTML (sadece izin verilen etiketler) */
function kitap_richtext_html($html) {
    if ($html === null || $html === '') return '';
    $html = strip_tags($html, '<p><br><b><i><u><strong><em><mark><span>');
    $html = preg_replace_callback('/<span\s+([^>]*)>/', function ($m) {
        if (preg_match('/class=["\'](fs-small|fs-large|fs-normal)["\']/', $m[1], $c)) {
            return '<span class="' . $c[1] . '">';
        }
        return '<span>';
    }, $html);
    return $html;
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $kitap_id > 0 ? 'Kitap Düzenle' : 'Yeni Kitap Ekle' ?> - Reading App</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f9fafb; margin: 0; padding: 0; color: #1f2937; }
        .main-wrap { display: flex; gap: 1.5rem; max-width: 1140px; margin: 2rem auto; padding: 0 1.5rem; align-items: flex-start; }
        .main-wrap .container { flex: 1; min-width: 0; margin: 0; }
        .container { max-width: 900px; margin: 2rem auto; padding: 1.5rem; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.08); overflow: hidden; }
        .toc-sidebar {
            width: 200px; flex-shrink: 0; padding: 1rem;
            background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: sticky; top: 2rem; max-height: calc(100vh - 4rem); overflow-y: auto;
        }
        .toc-sidebar .toc-title { font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem; }
        .toc-sidebar ul { list-style: none; margin: 0; padding: 0; }
        .toc-sidebar li { margin-bottom: 0.35rem; }
        .toc-sidebar li.toc-sub { margin-left: 0.75rem; margin-bottom: 0.25rem; }
        .toc-sidebar a { font-size: 0.8rem; color: #2563eb; text-decoration: none; display: block; padding: 0.2rem 0; border-radius: 4px; }
        .toc-sidebar a:hover { background: #e0e7ff; color: #1d4ed8; }
        @media (max-width: 768px) {
            .main-wrap { flex-direction: column; padding: 0 0.75rem; margin: 1rem auto; }
            .main-wrap .container { width: 100%; }
            .toc-sidebar { width: 100%; max-width: 100%; position: static; max-height: none; margin-top: 1rem; }
            .kitap-bilgi-wrap { flex-direction: column; }
            .kitap-kapak-wrap { width: 100%; max-width: 280px; margin: 0 auto; }
            .kitap-form-grid { grid-template-columns: 1fr; }
        }
        @media print { .toc-sidebar { display: none !important; } .main-wrap .container { margin: 0 auto !important; } }
        .kitap-bilgi-wrap { display: flex; gap: 1.5rem; padding: 1.5rem; align-items: flex-start; }
        .kitap-kapak-wrap { flex-shrink: 0; width: 280px; }
        .kitap-kapak-wrap img { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: block; }
        .kitap-kapak-placeholder { width: 100%; aspect-ratio: 2/3; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.85rem; text-align: center; }
        .kitap-form-right { flex: 1; min-width: 0; }
        .kitap-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1.5rem; }
        .kitap-form-grid .form-group { margin-bottom: 1rem; }
        .kitap-form-grid .form-group.full-width { grid-column: 1 / -1; }
        h2 { margin: 0 0 1rem 0; color: #1e293b; font-size: 1.25rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.35rem; color: #4b5563; font-size: 0.85rem; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 0.5rem 0.65rem; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; font-size: 0.95rem; }
        .checkbox-group { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; padding: 0.5rem 0; }
        .checkbox-group label { font-weight: normal; margin: 0; display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-size: 0.9rem; }
        .form-actions { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-primary { background-color: #2563eb; color: white; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .btn-cancel { background-color: #64748b; color: white; }
        .btn-danger { background-color: #dc2626; color: white; margin-left: auto; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-error { background-color: #fef2f2; color: #b91c1c; }
        .kapak-upload-wrap { margin-top: 0.5rem; }
        .kapak-upload-wrap input[type="file"] { font-size: 0.85rem; }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.9rem; }
        .btn-secondary { background-color: #10b981; color: white; }
        .btn-secondary:hover { background-color: #059669; }
        .akis-listesi { margin-top: 1rem; line-height: 1.5; }
        .akis-item { margin-bottom: 1.5rem; padding: 1rem; border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; }
        .akis-item.dusunce-standalone { background: #f3f4f6; }
        .akis-meta { font-size: 0.85rem; color: #6b7280; }
        .sayfa-aralik { font-size: 0.85rem; color: #4b5563; }
        .alinti-metin, .dusunce-metin { white-space: pre-wrap; margin: 0.5rem 0; line-height: 1.5; }
        .alinti-foto-wrap { margin-top: 0.5rem; }
        .alinti-foto-thumb { max-width: 160px; max-height: 200px; object-fit: cover; border-radius: 4px; }
        .dusunce-alt { margin-top: 0.75rem; margin-left: 1rem; padding: 0.75rem; background: #f9fafb; border-radius: 6px; border-left: 3px solid #2563eb; line-height: 1.5; }
        .akis-actions-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .akis-actions { display: inline-flex; align-items: center; gap: 0.25rem; flex-wrap: wrap; }
        .akis-meta-right { font-size: 0.85rem; color: #6b7280; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .btn-link { background: none; border: none; color: #2563eb; cursor: pointer; text-decoration: none; font-size: 0.9rem; padding: 0 0.5rem 0 0; }
        .btn-link:hover { text-decoration: underline; }
        .btn-link-danger { color: #dc2626; }
        .modal { position: fixed; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: #fff; padding: 1.5rem; border-radius: 8px; max-width: 520px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative; }
        #modal-alinti .modal-content { width: 60%; min-width: 60%; max-width: 1200px; }
        .modal-alinti-wrap { display: flex; gap: 1.5rem; align-items: flex-start; }
        .modal-alinti-photo-col { flex-shrink: 0; width: 320px; max-width: 40%; }
        .modal-alinti-photo-col.hidden { display: none; }
        .modal-alinti-photo-col img { width: 100%; height: auto; border-radius: 8px; border: 1px solid #e5e7eb; display: block; }
        .modal-alinti-gemini-btn { margin-top: 0.5rem; width: 100%; padding: 0.6rem 0.75rem; font-size: 0.9rem; background: #6366f1; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .modal-alinti-gemini-btn:hover { background: #4f46e5; }
        .modal-alinti-gemini-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .modal-alinti-form-col { flex: 1; min-width: 280px; }
        .modal-close { position: absolute; right: 1rem; top: 0.5rem; font-size: 1.5rem; cursor: pointer; color: #6b7280; }
        .modal-close:hover { color: #111; }
        .modal-dusunce-content { max-width: 560px; }
        .modal-dusunce-voice-row { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
        .modal-dusunce-btn-mic { min-width: 44px; min-height: 44px; padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6b7280; color: white; }
        .modal-dusunce-btn-mic.recording { background: #dc2626; }
        .modal-dusunce-btn-mic.stopped { background: #059669; }
        .modal-dusunce-btn-gemini-duzelt { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #6366f1; color: white; }
        .modal-dusunce-btn-gemini-duzelt:hover { background: #4f46e5; }
        .modal-dusunce-btn-gemini-duzelt:disabled { opacity: 0.6; cursor: not-allowed; }
        .modal-dusunce-transcribe { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; font-family: inherit; min-height: 80px; resize: vertical; }
        .modal-dusunce-alinti-block { margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb; }
        .modal-dusunce-alinti-block strong { display: block; margin-bottom: 0.35rem; color: #374151; }
        .alinti-onay { font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem; line-height: 1.4; max-height: 180px; overflow-y: auto; }
        .modal-dusunce-alinti-foto-wrap { max-width: 450px; margin-top: 0.5rem; }
        .modal-dusunce-alinti-foto-wrap img { max-width: 100%; height: auto; border-radius: 6px; display: block; }
        textarea { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        .fs-small { font-size: 0.85em; }
        .fs-large { font-size: 1.15em; }
        .fs-normal { font-size: 1em; }
        .rich-toolbar { display: flex; gap: 2px; padding: 4px 0; flex-wrap: wrap; }
        .rich-toolbar button { padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; cursor: pointer; font-size: 0.85rem; }
        .rich-toolbar button:hover { background: #e5e7eb; }
        .rich-editor { min-height: 100px; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; font-family: inherit; font-size: 1rem; overflow-y: auto; }
        .rich-editor:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
        /* Kitap dizgisi: okunaklı serif */
        body, .container { font-family: Georgia, 'Times New Roman', 'Liberation Serif', serif; }
        .navbar, .navbar a, .form-group label, .btn, .akis-meta, .sayfa-aralik, .btn-link { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .alinti-metin { font-style: italic; }
        /* Okuma seansları accordion */
        .accordion-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            padding: 0.75rem 1rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: #334155;
            text-align: left;
        }
        .accordion-head:hover { background: #e2e8f0; }
        .accordion-head .accordion-toggle { font-size: 0.9rem; color: #64748b; }
        .accordion-body { padding: 1rem 0; display: none; }
        .accordion-body.open { display: block; }
        .tahmini-kalan {
            background-color: #1e40af;
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 1rem;
            font-variant-numeric: tabular-nums;
        }
        .tahmini-kalan strong { font-weight: 700; }
        .table-container { overflow-x: auto; }
        .table-container table { width: 100%; border-collapse: collapse; text-align: left; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .table-container th, .table-container td { padding: 0.6rem 0.75rem; border-bottom: 1px solid #e5e7eb; }
        .table-container th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; }
        .table-container tr:hover { background-color: #f9fafb; }
        .cover-thumb { width: 48px; height: 72px; object-fit: cover; border-radius: 4px; background-color: #e5e7eb; }
        .cover-thumb-placeholder { width: 48px; height: 72px; border-radius: 4px; background: #e5e7eb; font-size: 0.6rem; color: #9ca3af; display: flex; align-items: center; justify-content: center; }
        /* PDF modal */
        .section-header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .section-header-row h3 { margin: 0; color: #374151; }
        .btn-pdf { padding: 0.4rem 0.9rem; background: #64748b; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-pdf:hover { background: #475569; color: white; }
        #modal-pdf .modal-content { max-width: 640px; }
        .pdf-header { display: flex; gap: 1.25rem; align-items: flex-start; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb; }
        .pdf-header-cover { flex-shrink: 0; width: 80px; }
        .pdf-header-cover img { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 6px; }
        .pdf-header-cover-placeholder { width: 80px; aspect-ratio: 2/3; background: #e5e7eb; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #9ca3af; text-align: center; }
        .pdf-header-info { flex: 1; min-width: 0; }
        .pdf-header-info h4 { margin: 0 0 0.25rem 0; font-size: 1.1rem; color: #1f2937; }
        .pdf-header-info .pdf-meta { font-size: 0.9rem; color: #6b7280; }
        .pdf-print-body { padding: 0 0 1.5rem 0; line-height: 1.5; }
        .pdf-print-body .akis-item { margin-bottom: 1.25rem; padding: 0.75rem; border-radius: 6px; border: 1px solid #e5e7eb; }
        .pdf-print-body .alinti-metin { font-style: italic; }
        .pdf-print-body .akis-meta-row { display: flex; justify-content: flex-end; margin-top: 0.35rem; font-size: 0.8rem; color: #6b7280; }
        .pdf-print-body .dusunce-alt { line-height: 1.5; }
        .pdf-print-body .dusunce-alt .akis-meta-row { margin-top: 0.25rem; }
        @media print {
            body * { visibility: hidden; }
            #modal-pdf, #modal-pdf * { visibility: visible; }
            #modal-pdf { position: absolute; left: 0; top: 0; width: 100%; min-height: 100%; background: white; padding: 0; margin: 0; overflow: visible !important; display: block !important; }
            #modal-pdf .modal-content { display: block !important; max-width: none !important; max-height: none !important; width: 100%; min-height: auto; overflow: visible !important; box-shadow: none; border: none; padding: 0; }
            #modal-pdf .modal-close, #modal-pdf .btn-yazdir-wrap { display: none !important; }
            #modal-pdf .pdf-print-area { width: 100%; max-width: 210mm; margin: 0 auto; padding: 12mm 15mm 18mm; box-sizing: border-box; font-size: 11pt; line-height: 1.5; overflow: visible !important; }
            #modal-pdf .pdf-print-area .pdf-header { margin-bottom: 1.2rem; padding-bottom: 0.75rem; }
            #modal-pdf .pdf-print-area .pdf-header-info h4 { font-size: 12pt; }
            #modal-pdf .pdf-print-area .pdf-print-body .akis-item { margin-bottom: 1rem; padding: 0.6rem 0; border: none; border-bottom: 1px solid #e5e7eb; page-break-inside: auto; }
            #modal-pdf .pdf-print-area .alinti-metin, #modal-pdf .pdf-print-area .dusunce-metin { font-size: 11pt; line-height: 1.5; margin: 0.25rem 0; }
            #modal-pdf .pdf-print-area .dusunce-alt { margin-top: 0.5rem; padding: 0.5rem 0 0 0.75rem; border-left: 2px solid #94a3b8; }
        }
        @page {
            size: A4;
            margin: 15mm;
            margin-bottom: 20mm;
            @bottom-center {
                content: "Sayfa " counter(page) " / " counter(pages);
                font-size: 10pt;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                color: #6b7280;
            }
        }
    </style>
</head>
<body>

<?php $nav_current = ''; include __DIR__ . '/includes/nav.php'; ?>

<?php if ($kitap_id > 0 && count($akis) > 0): ?>
<div class="main-wrap">
<?php endif; ?>
<div class="container">
    <h2><?= $kitap_id > 0 ? 'Kitap Düzenle' : 'Yeni Kitap Ekle' ?></h2>

    <?php if ($hata_mesaji): ?>
        <div class="alert alert-error"><?= $hata_mesaji ?></div>
    <?php endif; ?>
    <?php if ($alinti_hata): ?>
        <div class="alert alert-error"><?= htmlspecialchars($alinti_hata) ?></div>
    <?php endif; ?>

    <form method="POST" action="kitap.php" enctype="multipart/form-data">
        <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
        <div class="kitap-bilgi-wrap">
            <div class="kitap-kapak-wrap">
                <?php if (!empty($mevcut_kapak)): ?>
                    <img src="assets/uploads/<?= htmlspecialchars($mevcut_kapak) ?>" alt="Kapak" id="kitap-kapak-preview">
                <?php else: ?>
                    <div class="kitap-kapak-placeholder" id="kitap-kapak-preview">Kapak yok</div>
                <?php endif; ?>
                <div class="kapak-upload-wrap">
                    <label for="kapak" style="font-size:0.8rem; color:#6b7280;">Kapak</label>
                    <input type="file" id="kapak" name="kapak" accept="image/jpeg, image/png, image/webp">
                </div>
            </div>
            <div class="kitap-form-right">
                <div class="kitap-form-grid">
                    <div class="form-group full-width">
                        <label for="baslik">Kitap Adı *</label>
                        <input type="text" id="baslik" name="baslik" value="<?= htmlspecialchars($baslik) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="yazar">Yazar *</label>
                        <input type="text" id="yazar" name="yazar" value="<?= htmlspecialchars($yazar) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="durum_id">Durum</label>
                        <select id="durum_id" name="durum_id">
                            <?php foreach ($durumlar as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $durum_id == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['durum']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="kitap_tipi_id">Kitap tipi</label>
                        <select id="kitap_tipi_id" name="kitap_tipi_id">
                            <?php foreach ($kitap_tipleri as $kt): ?>
                                <option value="<?= (int)$kt['id'] ?>" <?= $kitap_tipi_id == (int)$kt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($kt['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="kitap-basili-alanlar" class="kitap-tip-alanlar" style="<?= $kitap_tipi_id !== 1 ? 'display:none;' : '' ?>">
                        <div class="form-group">
                            <label for="sayfa">Sayfa</label>
                            <input type="number" id="sayfa" name="sayfa" value="<?= (int)$sayfa ?>" min="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="baslangic_sayfa">Başlangıç sayfa</label>
                            <input type="number" id="baslangic_sayfa" name="baslangic_sayfa" value="<?= $baslangic_sayfa ?>" min="1" placeholder="1">
                        </div>
                        <div class="form-group">
                            <label for="bitis_sayfa">Bitiş sayfa</label>
                            <input type="number" id="bitis_sayfa" name="bitis_sayfa" value="<?= $bitis_sayfa !== null ? (int)$bitis_sayfa : '' ?>" min="1" placeholder="Boş">
                        </div>
                    </div>
                    <div id="kitap-sesli-alanlar" class="kitap-tip-alanlar" style="<?= $kitap_tipi_id !== 3 ? 'display:none;' : '' ?>">
                        <div class="form-group full-width">
                            <label>Toplam süre (ss:dd:ss)</label>
                            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <input type="number" name="sesli_saat" id="sesli_saat" value="<?= $sesli_saat ?>" min="0" max="999" placeholder="Saat" style="width:5rem;">
                                <span>:</span>
                                <input type="number" name="sesli_dakika" id="sesli_dakika" value="<?= $sesli_dakika ?>" min="0" max="59" placeholder="Dk" style="width:4rem;">
                                <span>:</span>
                                <input type="number" name="sesli_saniye" id="sesli_saniye" value="<?= $sesli_saniye ?>" min="0" max="59" placeholder="Sn" style="width:4rem;">
                            </div>
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label>Raflar</label>
                        <?php if (count($kullanici_raflari) > 0): ?>
                            <div class="checkbox-group">
                                <?php foreach ($kullanici_raflari as $raf): ?>
                                    <label>
                                        <input type="checkbox" name="raflar[]" value="<?= $raf['id'] ?>" <?= in_array($raf['id'], $secili_raflar) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($raf['etiket']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#6b7280; font-size:0.85rem;">Raf yok. <a href="raf.php">Raf Ekle</a></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                    <a href="index.php" class="btn btn-cancel">İptal</a>
                    <?php if ($kitap_id > 0): ?>
                        <a href="kitap.php?delete_id=<?= $kitap_id ?>" class="btn btn-danger" onclick="return confirm('Bu kitabı tamamen silmek istediğinize emin misiniz?');">Sil</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <?php if ($kitap_id > 0): ?>
    <hr style="border:0; border-top:1px solid #e5e7eb; margin: 2rem 0;">
    <?php if (count($kitap_okumalari) > 0 && $tahmini_kalan_goster): ?>
    <div class="tahmini-kalan" style="margin-bottom: 1rem;">
        <?php if ((int)($kitap['kitap_tipi_id'] ?? 1) === 1): ?>
            <?php if ($kalan_sayfa > 0): ?>
            <strong>Tahmini kalan okuma süresi:</strong> <?= sure_format_ssddss($tahmini_saniye) ?> (<?= $kalan_sayfa ?> sayfa)
            <?php else: ?>
            <strong>Kitabı bitirdiniz.</strong> Ortalama okuma hızınız: sayfa başı <?= $toplam_okunan_sayfa > 0 ? sure_format_ddss((int)round($toplam_saniye_kitap / $toplam_okunan_sayfa)) : '—' ?>
            <?php endif; ?>
        <?php elseif ((int)($kitap['kitap_tipi_id'] ?? 1) === 2): ?>
            <strong>Tahmini kalan okuma süresi (e-kitap):</strong> <?= sure_format_ssddss($tahmini_saniye) ?>
        <?php else: ?>
            <strong>Kalan dinleme süresi:</strong> <?= sure_format_ssddss($tahmini_saniye) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (count($kitap_okumalari) > 0): ?>
    <div class="accordion-wrap" style="margin-bottom: 1.5rem;">
        <button type="button" class="accordion-head" id="accordion-okuma-head" aria-expanded="false" onclick="toggleAccordion('accordion-okuma')">
            <span>Okuma seansları (<?= count($kitap_okumalari) ?> seans)</span>
            <span class="accordion-toggle" id="accordion-okuma-toggle">▼</span>
        </button>
        <div class="accordion-body" id="accordion-okuma-body">
            <div class="table-container">
                <table>
                    <?php $kt_tablo = (int)($kitap['kitap_tipi_id'] ?? 1); ?>
                    <thead>
                        <tr>
                            <?php if ($kt_tablo === 1): ?>
                            <th>Seans başlangıç</th>
                            <th>Seans bitiş</th>
                            <th>Başlama sayfası</th>
                            <th>Bitiş sayfası</th>
                            <th>Okunan sayfa</th>
                            <th>Süre</th>
                            <th>Sayfa başı</th>
                            <?php elseif ($kt_tablo === 2): ?>
                            <th>Seans başlangıç</th>
                            <th>Seans bitiş</th>
                            <th>Başlangıç %</th>
                            <th>Bitiş %</th>
                            <th>Süre</th>
                            <?php else: ?>
                            <th>Seans başlangıç</th>
                            <th>Seans bitiş</th>
                            <th>Konum (baş)</th>
                            <th>Konum (bit)</th>
                            <th>Oynatılan</th>
                            <th>Seans süresi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $toplam_seans_tablo_saniye = 0;
                        $genel_ortalama_ddss = '—';
                        foreach ($kitap_okumalari as $o):
                            $seans_sure_row = seans_sure_zamandan($o['baslama'] ?? null, $o['bitis'] ?? null, $o['sure_saniye'] ?? 0);
                            $toplam_seans_tablo_saniye += $seans_sure_row;
                            if ($kt_tablo === 1) {
                                $sayfa_adedi = (int)($o['bitis_sayfasi'] ?? 0) - (int)$o['baslama_sayfasi'] + 1;
                                if ($sayfa_adedi < 1) $sayfa_adedi = 1;
                                $saniye_per_sayfa_row = (int) round($seans_sure_row / $sayfa_adedi);
                                $sayfa_basi_ddss = sure_format_ddss($saniye_per_sayfa_row);
                            }
                        ?>
                        <tr>
                            <?php if ($kt_tablo === 1): ?>
                            <td><?= date('d.m.Y H:i', strtotime($o['baslama'])) ?></td>
                            <td><?= !empty($o['bitis']) ? date('d.m.Y H:i', strtotime($o['bitis'])) : '—' ?></td>
                            <td><?= (int) $o['baslama_sayfasi'] ?></td>
                            <td><?= $o['bitis_sayfasi'] !== null ? (int) $o['bitis_sayfasi'] : '—' ?></td>
                            <td><?= $sayfa_adedi ?></td>
                            <td><?= sure_format_ssddss($seans_sure_row) ?></td>
                            <td><?= $sayfa_basi_ddss ?></td>
                            <?php elseif ($kt_tablo === 2): ?>
                            <td><?= date('d.m.Y H:i', strtotime($o['baslama'])) ?></td>
                            <td><?= !empty($o['bitis']) ? date('d.m.Y H:i', strtotime($o['bitis'])) : '—' ?></td>
                            <td><?= isset($o['baslama_yuzde']) && $o['baslama_yuzde'] !== null ? number_format((float)$o['baslama_yuzde'], 1) . '%' : '—' ?></td>
                            <td><?= isset($o['bitis_yuzde']) && $o['bitis_yuzde'] !== null ? number_format((float)$o['bitis_yuzde'], 1) . '%' : '—' ?></td>
                            <td><?= sure_format_ssddss($seans_sure_row) ?></td>
                            <?php else:
                                $bs = (int)($o['baslama_sure_saniye'] ?? 0);
                                $bt = (int)($o['bitis_sure_saniye'] ?? 0);
                                $delta_k = max(0, $bt - $bs);
                            ?>
                            <td><?= date('d.m.Y H:i', strtotime($o['baslama'])) ?></td>
                            <td><?= !empty($o['bitis']) ? date('d.m.Y H:i', strtotime($o['bitis'])) : '—' ?></td>
                            <td><?= sure_format_ssddss($bs) ?></td>
                            <td><?= sure_format_ssddss($bt) ?></td>
                            <td><?= sure_format_ssddss($delta_k) ?></td>
                            <td><?= sure_format_ssddss($seans_sure_row) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($kt_tablo === 1 && $toplam_okunan_sayfa > 0): ?>
                            <?php $genel_ortalama_ddss = sure_format_ddss((int) round($toplam_seans_tablo_saniye / $toplam_okunan_sayfa)); ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 600; background-color: #f9fafb;">
                            <?php if ($kt_tablo === 1): ?>
                            <td colspan="2">Toplam</td>
                            <td colspan="2"></td>
                            <td><?= $toplam_okunan_sayfa > 0 ? (int) $toplam_okunan_sayfa : '—' ?></td>
                            <td><?= sure_format_ssddss($toplam_seans_tablo_saniye) ?></td>
                            <td><?= $genel_ortalama_ddss ?></td>
                            <?php elseif ($kt_tablo === 2): ?>
                            <td colspan="4">Toplam</td>
                            <td><?= sure_format_ssddss($toplam_seans_tablo_saniye) ?></td>
                            <?php else: ?>
                            <td colspan="5">Toplam (seans süreleri)</td>
                            <td><?= sure_format_ssddss($toplam_seans_tablo_saniye) ?></td>
                            <?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="section-header-row">
        <h3>Alıntılar ve Düşünceler</h3>
        <button type="button" class="btn-pdf" onclick="openPdfModal()">PDF</button>
    </div>
    <p style="margin-bottom: 1rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="openDusunceModal(null)">+ Düşünce</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAlintiModal()" style="margin-left: 0.5rem;">+ Alıntı</button>
        <span style="color:#6b7280; font-size:0.9rem; margin-left:0.5rem;">Kitaba özel düşünce veya alıntı ekle</span>
    </p>
    <div class="akis-listesi">
        <?php foreach ($akis as $item):
            if ($item['tip'] === 'alinti'):
                $a = $item['veri'];
                $aid = (int)$a['id'];
                $alt_dusunceler = $dusunceler_by_alinti[$aid] ?? [];
        ?>
        <div class="akis-item alinti-item" id="alinti-<?= $aid ?>">
            <div class="alinti-metin"><?= kitap_richtext_html($a['alinti']) ?></div>
            <?php if (!empty($a['foto'])): ?>
                <div class="alinti-foto-wrap"><img src="alintilar/uploads/<?= htmlspecialchars($a['foto']) ?>" alt="Alıntı" class="alinti-foto-thumb"></div>
            <?php endif; ?>
            <div class="akis-actions-row">
                <div class="akis-actions">
                    <button type="button" class="btn-link" data-alinti="<?= htmlspecialchars(json_encode(['alinti' => $a['alinti'], 'alinti_display' => kitap_richtext_html($a['alinti']), 'foto' => $a['foto'] ?? ''], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" onclick="openDusunceModal(<?= $aid ?>, this)">+ Düşünce</button>
                    <a href="#" class="btn-link" data-alinti-json="<?= htmlspecialchars(json_encode($a, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" onclick="openAlintiEditModal(this); return false;">Düzenle</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Bu alıntıyı silmek istediğinize emin misiniz?');">
                        <input type="hidden" name="action" value="alinti_sil">
                        <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
                        <input type="hidden" name="alinti_id" value="<?= $aid ?>">
                        <button type="submit" class="btn-link btn-link-danger">Sil</button>
                    </form>
                </div>
                <div class="akis-meta-right">
                    <?= date('d.m.Y H:i', $item['kayit']) ?>
                    <?php if (!empty($a['sayfa_baslangic']) || !empty($a['sayfa_bitis'])): ?>
                        · Sayfa <?= (int)$a['sayfa_baslangic'] ?><?= !empty($a['sayfa_bitis']) && $a['sayfa_bitis'] != $a['sayfa_baslangic'] ? '–' . (int)$a['sayfa_bitis'] : '' ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php foreach ($alt_dusunceler as $d): ?>
            <div class="dusunce-alt" id="dusunce-<?= (int)$d['id'] ?>">
                <div class="dusunce-metin"><?= kitap_richtext_html($d['dusunce']) ?></div>
                <div class="akis-actions-row">
                    <div class="akis-actions">
                        <a href="#" class="btn-link" data-dusunce-json="<?= htmlspecialchars(json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" onclick="openDusunceEditModal(this); return false;">Düzenle</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Bu düşünceyi silmek istediğinize emin misiniz?');">
                            <input type="hidden" name="action" value="dusunce_sil">
                            <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
                            <input type="hidden" name="dusunce_id" value="<?= (int)$d['id'] ?>">
                            <button type="submit" class="btn-link btn-link-danger">Sil</button>
                        </form>
                    </div>
                    <div class="akis-meta-right">
                        <?= date('d.m.Y H:i', strtotime($d['kayit'] ?? '')) ?>
                        <?php if (!empty($d['sayfa_baslangic']) || !empty($d['sayfa_bitis'])): ?>
                            · Sayfa <?= (int)($d['sayfa_baslangic'] ?? '') ?><?= !empty($d['sayfa_bitis']) ? '–' . (int)$d['sayfa_bitis'] : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else:
            $d = $item['veri'];
        ?>
        <div class="akis-item dusunce-standalone" id="dusunce-<?= (int)$d['id'] ?>">
            <div class="dusunce-metin"><?= kitap_richtext_html($d['dusunce']) ?></div>
            <div class="akis-actions-row">
                <div class="akis-actions">
                    <a href="#" class="btn-link" data-dusunce-json="<?= htmlspecialchars(json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" onclick="openDusunceEditModal(this); return false;">Düzenle</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Bu düşünceyi silmek istediğinize emin misiniz?');">
                        <input type="hidden" name="action" value="dusunce_sil">
                        <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
                        <input type="hidden" name="dusunce_id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="btn-link btn-link-danger">Sil</button>
                    </form>
                </div>
                <div class="akis-meta-right">
                    <?= date('d.m.Y H:i', $item['kayit']) ?>
                    <?php if (!empty($d['sayfa_baslangic']) || !empty($d['sayfa_bitis'])): ?>
                        · Sayfa <?= (int)($d['sayfa_baslangic'] ?? '') ?><?= !empty($d['sayfa_bitis']) ? '–' . (int)$d['sayfa_bitis'] : '' ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; endforeach; ?>
        <?php if (count($akis) === 0): ?>
            <p style="color:#6b7280;">Henüz alıntı veya düşünce yok. Yukarıdaki butonlarla ekleyebilirsiniz.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php if ($kitap_id > 0 && count($akis) > 0): ?>
<nav class="toc-sidebar" aria-label="İçindekiler">
    <div class="toc-title">İçindekiler</div>
    <ul>
        <?php
        $alinti_no = 0;
        foreach ($akis as $item):
            if ($item['tip'] === 'alinti'):
                $a = $item['veri'];
                $aid = (int)$a['id'];
                $alinti_no++;
                $sayfa_a = (int)($a['sayfa_baslangic'] ?? 0) ?: (int)($a['sayfa_bitis'] ?? 0);
                $alt_dusunceler = $dusunceler_by_alinti[$aid] ?? [];
        ?>
        <li>
            <a href="#alinti-<?= $aid ?>"><?= $sayfa_a ? 'Alıntı ' . $alinti_no . ' · Sayfa ' . $sayfa_a : 'Alıntı ' . $alinti_no ?></a>
        </li>
            <?php foreach ($alt_dusunceler as $d):
                $sayfa_d = (int)($d['sayfa_baslangic'] ?? 0) ?: (int)($d['sayfa_bitis'] ?? 0);
            ?>
        <li class="toc-sub">
            <a href="#dusunce-<?= (int)$d['id'] ?>"><?= $sayfa_d ? 'Sayfa ' . $sayfa_d : 'Düşünce' ?></a>
        </li>
            <?php endforeach; ?>
        <?php else:
            $d = $item['veri'];
            $sayfa_d = (int)($d['sayfa_baslangic'] ?? 0) ?: (int)($d['sayfa_bitis'] ?? 0);
        ?>
        <li>
            <a href="#dusunce-<?= (int)$d['id'] ?>"><?= $sayfa_d ? 'Sayfa ' . $sayfa_d : 'Düşünce' ?></a>
        </li>
        <?php endif; endforeach; ?>
    </ul>
</nav>
</div>
<?php endif; ?>

<?php if ($kitap_id > 0): ?>
<!-- Modal: Alıntı Ekle -->
<div id="modal-alinti" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('modal-alinti')">&times;</span>
        <div class="modal-alinti-wrap">
            <div class="modal-alinti-photo-col hidden" id="modal-alinti-photo-col">
                <img id="modal-alinti-photo-img" src="" alt="">
                <button type="button" class="modal-alinti-gemini-btn" id="modal-alinti-gemini-btn">Gemini ile metne çevir</button>
            </div>
            <div class="modal-alinti-form-col">
        <h3 id="modal-alinti-title">Alıntı Ekle</h3>
        <form method="post" action="kitap.php" enctype="multipart/form-data" id="form-alinti" onsubmit="return syncRichToHidden('modal-alinti-editor','modal-alinti-hidden');">
            <input type="hidden" name="action" value="alinti_ekle">
            <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
            <input type="hidden" name="alinti_id" id="alinti_id_edit" value="">
            <input type="hidden" name="mevcut_foto" id="mevcut_foto" value="">
            <div class="form-group">
                <label>Alıntı metni *</label>
                <div class="rich-toolbar" id="toolbar-alinti">
                    <button type="button" onclick="richCmd('modal-alinti-editor','bold')" title="Kalın">B</button>
                    <button type="button" onclick="richCmd('modal-alinti-editor','italic')" title="İtalik">İ</button>
                    <button type="button" onclick="richCmd('modal-alinti-editor','underline')" title="Altı çizili">U</button>
                    <button type="button" onclick="richCmd('modal-alinti-editor','hiliteColor', false, '#fef08a')" title="Vurgula">Vurgula</button>
                    <select onchange="var v=this.value; if(v) richCmd('modal-alinti-editor','fontSize', false, v); this.selectedIndex=0;" title="Yazı boyutu">
                        <option value="">Boyut</option>
                        <option value="1">Küçük</option>
                        <option value="3">Normal</option>
                        <option value="5">Büyük</option>
                    </select>
                </div>
                <div id="modal-alinti-editor" class="rich-editor" contenteditable="true" data-hidden-name="alinti"></div>
                <input type="hidden" name="alinti" id="modal-alinti-hidden">
            </div>
            <?php $kitap_tipi_basili = isset($kitap) && (int)($kitap['kitap_tipi_id'] ?? 1) === 1; ?>
            <div id="modal-alinti-sayfa-wrap" class="modal-sayfa-alanlari" style="<?= $kitap_tipi_basili ? '' : 'display:none;' ?>">
                <div class="form-group">
                    <label>Sayfa başlangıç</label>
                    <input type="number" name="sayfa_baslangic" id="modal-alinti-sb" min="1" placeholder="">
                </div>
                <div class="form-group">
                    <label>Sayfa bitiş</label>
                    <input type="number" name="sayfa_bitis" id="modal-alinti-sbit" min="1" placeholder="">
                </div>
            </div>
            <div class="form-group" id="modal-alinti-foto-wrap">
                <label>Sayfa fotoğrafı</label>
                <input type="file" name="foto" accept="image/jpeg, image/png, image/webp">
                <div id="modal-alinti-foto-preview"></div>
            </div>
            <button type="submit" class="btn btn-primary">Kaydet</button>
            <button type="button" class="btn btn-cancel" onclick="closeModal('modal-alinti')">İptal</button>
        </form>
            </div>
        </div>
    </div>
</div>
<!-- Modal: Düşünce Ekle (alıntıya bağlı veya kitaba özel) -->
<div id="modal-dusunce" class="modal" style="display:none;">
    <div class="modal-content modal-dusunce-content">
        <span class="modal-close" onclick="closeModal('modal-dusunce')">&times;</span>
        <h3 id="modal-dusunce-title">Düşünce Ekle</h3>
        <div id="modal-dusunce-alinti-panel" class="modal-dusunce-alinti-block" style="display:none;">
            <strong>Alıntı:</strong>
            <div id="modal-dusunce-alinti-text" class="alinti-onay"></div>
            <div id="modal-dusunce-alinti-foto" class="modal-dusunce-alinti-foto-wrap"></div>
        </div>
        <form method="post" action="kitap.php" id="form-dusunce" onsubmit="return syncRichToHidden('modal-dusunce-editor','modal-dusunce-hidden');">
            <input type="hidden" name="action" value="dusunce_ekle">
            <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
            <input type="hidden" name="alinti_id" id="dusunce_alinti_id" value="">
            <div class="form-group">
                <label>Sesle kaydet</label>
                <div class="modal-dusunce-voice-row">
                    <button type="button" class="modal-dusunce-btn-mic" id="modal-dusunce-mic-btn">🎤 Mikrofon</button>
                    <button type="button" class="modal-dusunce-btn-gemini-duzelt" id="modal-dusunce-gemini-duzelt-btn">Gemini ile düzelt</button>
                </div>
                <textarea id="modal-dusunce-transcribe" class="modal-dusunce-transcribe" placeholder="Ses kaydı metni buraya yazılacak veya elle yazıp düzeltebilirsiniz..."></textarea>
            </div>
            <div class="form-group">
                <label>Düşünce *</label>
                <div class="rich-toolbar" id="toolbar-dusunce">
                    <button type="button" onclick="richCmd('modal-dusunce-editor','bold')" title="Kalın">B</button>
                    <button type="button" onclick="richCmd('modal-dusunce-editor','italic')" title="İtalik">İ</button>
                    <button type="button" onclick="richCmd('modal-dusunce-editor','underline')" title="Altı çizili">U</button>
                    <button type="button" onclick="richCmd('modal-dusunce-editor','hiliteColor', false, '#fef08a')" title="Vurgula">Vurgula</button>
                    <select onchange="var v=this.value; if(v) richCmd('modal-dusunce-editor','fontSize', false, v); this.selectedIndex=0;" title="Yazı boyutu">
                        <option value="">Boyut</option>
                        <option value="1">Küçük</option>
                        <option value="3">Normal</option>
                        <option value="5">Büyük</option>
                    </select>
                </div>
                <div id="modal-dusunce-editor" class="rich-editor" contenteditable="true" data-hidden-name="dusunce"></div>
                <input type="hidden" name="dusunce" id="modal-dusunce-hidden">
            </div>
            <div id="modal-dusunce-sayfa-wrap" class="modal-sayfa-alanlari" style="<?= $kitap_tipi_basili ? '' : 'display:none;' ?>">
                <div class="form-group">
                    <label>Sayfa başlangıç</label>
                    <input type="number" name="sayfa_baslangic" id="modal-dusunce-sb" min="1" placeholder="">
                </div>
                <div class="form-group">
                    <label>Sayfa bitiş</label>
                    <input type="number" name="sayfa_bitis" id="modal-dusunce-sbit" min="1" placeholder="">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Kaydet</button>
            <button type="button" class="btn btn-cancel" onclick="closeModal('modal-dusunce')">İptal</button>
        </form>
    </div>
</div>
<!-- Modal: Düşünce Düzenle -->
<div id="modal-dusunce-edit" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('modal-dusunce-edit')">&times;</span>
        <h3>Düşünce Düzenle</h3>
        <form method="post" action="kitap.php" id="form-dusunce-edit" onsubmit="return syncRichToHidden('modal-dusunce-edit-editor','modal-dusunce-edit-hidden');">
            <input type="hidden" name="action" value="dusunce_guncelle">
            <input type="hidden" name="kitap_id" value="<?= $kitap_id ?>">
            <input type="hidden" name="dusunce_id" id="dusunce_edit_id" value="">
            <div class="form-group">
                <label>Düşünce *</label>
                <div class="rich-toolbar">
                    <button type="button" onclick="richCmd('modal-dusunce-edit-editor','bold')">B</button>
                    <button type="button" onclick="richCmd('modal-dusunce-edit-editor','italic')">İ</button>
                    <button type="button" onclick="richCmd('modal-dusunce-edit-editor','underline')">U</button>
                    <button type="button" onclick="richCmd('modal-dusunce-edit-editor','hiliteColor', false, '#fef08a')">Vurgula</button>
                    <select onchange="var v=this.value; if(v) richCmd('modal-dusunce-edit-editor','fontSize', false, v); this.selectedIndex=0;">
                        <option value="">Boyut</option>
                        <option value="1">Küçük</option>
                        <option value="3">Normal</option>
                        <option value="5">Büyük</option>
                    </select>
                </div>
                <div id="modal-dusunce-edit-editor" class="rich-editor" contenteditable="true"></div>
                <input type="hidden" name="dusunce" id="modal-dusunce-edit-hidden">
            </div>
            <div id="modal-dusunce-edit-sayfa-wrap" class="modal-sayfa-alanlari" style="<?= $kitap_tipi_basili ? '' : 'display:none;' ?>">
                <div class="form-group">
                    <label>Sayfa başlangıç</label>
                    <input type="number" name="sayfa_baslangic" id="modal-dusunce-edit-sb" min="1" placeholder="">
                </div>
                <div class="form-group">
                    <label>Sayfa bitiş</label>
                    <input type="number" name="sayfa_bitis" id="modal-dusunce-edit-sbit" min="1" placeholder="">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Güncelle</button>
            <button type="button" class="btn btn-cancel" onclick="closeModal('modal-dusunce-edit')">İptal</button>
        </form>
    </div>
</div>
<!-- Modal: PDF / Yazdır (alıntılar ve düşünceler) -->
<div id="modal-pdf" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('modal-pdf')">&times;</span>
        <div class="pdf-print-area">
            <div class="pdf-header">
                <div class="pdf-header-cover">
                    <?php if (!empty($mevcut_kapak)): ?>
                        <img src="assets/uploads/<?= htmlspecialchars($mevcut_kapak) ?>" alt="">
                    <?php else: ?>
                        <div class="pdf-header-cover-placeholder">Kapak yok</div>
                    <?php endif; ?>
                </div>
                <div class="pdf-header-info">
                    <h4><?= htmlspecialchars($baslik) ?></h4>
                    <div class="pdf-meta">Yazar: <?= htmlspecialchars($yazar) ?></div>
                    <div class="pdf-meta"><?= (int)$sayfa ? (int)$sayfa . ' sayfa' : '—' ?></div>
                </div>
            </div>
            <h3 style="margin: 0 0 0.75rem 0; font-size: 1rem; color: #374151;">Alıntılar ve Düşünceler</h3>
            <div class="pdf-print-body" id="pdf-print-body">
                <?php foreach ($akis as $item):
                    if ($item['tip'] === 'alinti'):
                        $a = $item['veri'];
                        $aid = (int)$a['id'];
                        $alt_dusunceler = $dusunceler_by_alinti[$aid] ?? [];
                ?>
                <div class="akis-item alinti-item" id="alinti-<?= $aid ?>">
                    <div class="alinti-metin"><?= kitap_richtext_html($a['alinti']) ?></div>
                    <?php if (!empty($a['foto'])): ?>
                        <div class="alinti-foto-wrap"><img src="alintilar/uploads/<?= htmlspecialchars($a['foto']) ?>" alt="Alıntı" class="alinti-foto-thumb" style="max-width:200px;"></div>
                    <?php endif; ?>
                    <div class="akis-meta-row">
                        <?= date('d.m.Y H:i', $item['kayit']) ?>
                        <?php if (!empty($a['sayfa_baslangic']) || !empty($a['sayfa_bitis'])): ?>
                            · Sayfa <?= (int)$a['sayfa_baslangic'] ?><?= !empty($a['sayfa_bitis']) && $a['sayfa_bitis'] != $a['sayfa_baslangic'] ? '–' . (int)$a['sayfa_bitis'] : '' ?>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($alt_dusunceler as $d): ?>
                    <div class="dusunce-alt" id="dusunce-<?= (int)$d['id'] ?>">
                        <div class="dusunce-metin"><?= kitap_richtext_html($d['dusunce']) ?></div>
                        <div class="akis-meta-row">
                            <?= date('d.m.Y H:i', strtotime($d['kayit'] ?? '')) ?>
                            <?php if (!empty($d['sayfa_baslangic']) || !empty($d['sayfa_bitis'])): ?>
                                · Sayfa <?= (int)($d['sayfa_baslangic'] ?? '') ?><?= !empty($d['sayfa_bitis']) ? '–' . (int)$d['sayfa_bitis'] : '' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else:
                    $d = $item['veri'];
                ?>
                <div class="akis-item dusunce-standalone" id="dusunce-<?= (int)$d['id'] ?>">
                    <div class="dusunce-metin"><?= kitap_richtext_html($d['dusunce']) ?></div>
                    <div class="akis-meta-row">
                        <?= date('d.m.Y H:i', $item['kayit']) ?>
                        <?php if (!empty($d['sayfa_baslangic']) || !empty($d['sayfa_bitis'])): ?>
                            · Sayfa <?= (int)($d['sayfa_baslangic'] ?? '') ?><?= !empty($d['sayfa_bitis']) ? '–' . (int)$d['sayfa_bitis'] : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; endforeach; ?>
                <?php if (count($akis) === 0): ?>
                <p style="color:#6b7280;">Henüz alıntı veya düşünce yok.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="btn-yazdir-wrap" style="margin-top: 1rem;">
            <button type="button" class="btn btn-primary" onclick="window.print()">Yazdır</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
(function() {
    var tipSelect = document.getElementById('kitap_tipi_id');
    var basili = document.getElementById('kitap-basili-alanlar');
    var sesli = document.getElementById('kitap-sesli-alanlar');
    if (tipSelect && basili && sesli) {
        function toggleKitapTipAlanlar() {
            var v = parseInt(tipSelect.value, 10);
            basili.style.display = (v === 1) ? '' : 'none';
            sesli.style.display = (v === 3) ? '' : 'none';
        }
        tipSelect.addEventListener('change', toggleKitapTipAlanlar);
    }
})();
function toggleAccordion(id) {
    var body = document.getElementById(id + '-body');
    var toggle = document.getElementById(id + '-toggle');
    var head = document.getElementById(id + '-head');
    if (body && toggle) {
        var isOpen = body.classList.contains('open');
        body.classList.toggle('open', !isOpen);
        head.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
        toggle.textContent = body.classList.contains('open') ? '▲' : '▼';
    }
}
function openPdfModal() {
    openModal('modal-pdf');
}
function richCmd(editorId, cmd, ui, value) {
    var el = document.getElementById(editorId);
    if (el) { el.focus(); document.execCommand(cmd, ui || false, value || null); }
}
function syncRichToHidden(editorId, hiddenId) {
    var ed = document.getElementById(editorId);
    var hid = document.getElementById(hiddenId);
    if (!ed || !hid) return true;
    var html = ed.innerHTML.trim();
    if (html === '' || html === '<br>') { alert('Metin alanı boş olamaz.'); return false; }
    hid.value = html;
    return true;
}
function openAlintiModal() {
    document.getElementById('modal-alinti-title').textContent = 'Alıntı Ekle';
    document.getElementById('form-alinti').querySelector('[name="action"]').value = 'alinti_ekle';
    document.getElementById('alinti_id_edit').value = '';
    document.getElementById('mevcut_foto').value = '';
    document.getElementById('modal-alinti-editor').innerHTML = '';
    document.getElementById('modal-alinti-sb').value = '';
    document.getElementById('modal-alinti-sbit').value = '';
    document.getElementById('modal-alinti-foto-preview').innerHTML = '';
    document.getElementById('form-alinti').querySelector('input[name="foto"]').value = '';
    document.getElementById('modal-alinti-photo-col').classList.add('hidden');
    openModal('modal-alinti');
}
function openAlintiEditModal(el) {
    var a = JSON.parse(el.getAttribute('data-alinti-json'));
    document.getElementById('modal-alinti-title').textContent = 'Alıntı Düzenle';
    document.getElementById('form-alinti').querySelector('[name="action"]').value = 'alinti_guncelle';
    document.getElementById('alinti_id_edit').value = a.id;
    document.getElementById('mevcut_foto').value = a.foto || '';
    document.getElementById('modal-alinti-editor').innerHTML = a.alinti || '';
    document.getElementById('modal-alinti-sb').value = a.sayfa_baslangic || '';
    document.getElementById('modal-alinti-sbit').value = a.sayfa_bitis || '';
    var preview = document.getElementById('modal-alinti-foto-preview');
    if (a.foto) {
        preview.innerHTML = '<img src="alintilar/uploads/' + (a.foto || '') + '" alt="" style="max-width:120px; margin-top:8px;">';
    } else { preview.innerHTML = ''; }
    var photoCol = document.getElementById('modal-alinti-photo-col');
    if (a.foto) {
        photoCol.classList.remove('hidden');
        document.getElementById('modal-alinti-photo-img').src = 'alintilar/uploads/' + a.foto;
    } else {
        photoCol.classList.add('hidden');
    }
    openModal('modal-alinti');
}
document.getElementById('modal-alinti-gemini-btn').addEventListener('click', function() {
    var alintiId = document.getElementById('alinti_id_edit').value;
    if (!alintiId) return;
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'İşleniyor...';
    fetch('gemini_ocr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ alinti_id: parseInt(alintiId, 10) })
    }).then(function(r) { return r.json(); }).then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Gemini ile metne çevir';
        if (data.error && !data.text) {
            alert(data.error);
            return;
        }
        var text = (data.text || '').trim();
        var editor = document.getElementById('modal-alinti-editor');
        editor.innerHTML = text.replace(/\n/g, '<br>');
        if (data.sayfa != null) {
            document.getElementById('modal-alinti-sb').value = data.sayfa;
        }
    }).catch(function() {
        btn.disabled = false;
        btn.textContent = 'Gemini ile metne çevir';
        alert('İstek başarısız.');
    });
});
function openDusunceModal(alintiId, btn) {
    var alintiData = btn && btn.getAttribute('data-alinti') ? JSON.parse(btn.getAttribute('data-alinti')) : null;
    document.getElementById('modal-dusunce-title').textContent = alintiId ? 'Alıntıya düşünce ekle' : 'Düşünce Ekle';
    document.getElementById('dusunce_alinti_id').value = alintiId || '';
    document.getElementById('modal-dusunce-editor').innerHTML = '';
    document.getElementById('modal-dusunce-sb').value = '';
    document.getElementById('modal-dusunce-sbit').value = '';
    var transcribeEl = document.getElementById('modal-dusunce-transcribe');
    if (transcribeEl) { transcribeEl.value = ''; transcribeEl.placeholder = 'Ses kaydı metni buraya yazılacak veya elle yazıp düzeltebilirsiniz...'; }
    var micBtn = document.getElementById('modal-dusunce-mic-btn');
    if (micBtn) { micBtn.classList.remove('recording', 'stopped'); micBtn.textContent = '🎤 Mikrofon'; }
    var panel = document.getElementById('modal-dusunce-alinti-panel');
    if (alintiId && alintiData) {
        panel.style.display = 'block';
        document.getElementById('modal-dusunce-alinti-text').innerHTML = alintiData.alinti_display || (function(t){ return (t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>'); })(alintiData.alinti);
        var fotoDiv = document.getElementById('modal-dusunce-alinti-foto');
        if (alintiData.foto) {
            fotoDiv.innerHTML = '<img src="alintilar/uploads/' + alintiData.foto + '" alt="" style="max-width:140px; margin-top:8px;">';
        } else { fotoDiv.innerHTML = ''; }
    } else {
        panel.style.display = 'none';
    }
    openModal('modal-dusunce');
}
(function() {
    var micBtn = document.getElementById('modal-dusunce-mic-btn');
    var geminiDuzeltBtn = document.getElementById('modal-dusunce-gemini-duzelt-btn');
    var transcribeEl = document.getElementById('modal-dusunce-transcribe');
    var editorEl = document.getElementById('modal-dusunce-editor');
    if (!micBtn || !transcribeEl) return;
    var mediaRecorder = null, audioChunks = [];
    micBtn.addEventListener('click', function() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            micBtn.classList.remove('recording');
            micBtn.classList.add('stopped');
            micBtn.textContent = '✓ Durduruldu';
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Bu tarayıcı mikrofon kaydını desteklemiyor.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            var mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm' : 'audio/ogg';
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            mediaRecorder.ondataavailable = function(e) { if (e.data.size > 0) audioChunks.push(e.data); };
            mediaRecorder.onstop = function() {
                stream.getTracks().forEach(function(t) { t.stop(); });
                if (audioChunks.length === 0) return;
                var blob = new Blob(audioChunks, { type: mime });
                var fd = new FormData();
                fd.append('action', 'transcribe');
                fd.append('audio', blob, 'audio.webm');
                transcribeEl.placeholder = 'Dönüştürülüyor...';
                fetch('gemini_dusunce.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        transcribeEl.placeholder = 'Ses kaydı metni buraya yazılacak veya elle yazıp düzeltebilirsiniz...';
                        if (data.error) { alert(data.error); return; }
                        var prev = transcribeEl.value;
                        transcribeEl.value = prev ? prev + '\n' + (data.text || '') : (data.text || '');
                    })
                    .catch(function() {
                        transcribeEl.placeholder = 'Ses kaydı metni buraya yazılacak veya elle yazıp düzeltebilirsiniz...';
                        alert('Ses dönüştürme başarısız.');
                    });
            };
            mediaRecorder.start();
            micBtn.classList.add('recording');
            micBtn.classList.remove('stopped');
            micBtn.textContent = '⏹ Kaydı Durdur';
        }).catch(function() { alert('Mikrofon erişimi verilmedi.'); });
    });
    if (geminiDuzeltBtn && editorEl) {
        geminiDuzeltBtn.addEventListener('click', function() {
            var ham = transcribeEl.value.trim();
            if (ham === '') {
                alert('Önce ses kaydı yapıp metne dönüştürün veya yukarıdaki alana metin yazın.');
                return;
            }
            geminiDuzeltBtn.disabled = true;
            geminiDuzeltBtn.textContent = 'Düzeltiliyor...';
            var fd = new FormData();
            fd.append('action', 'duzelt');
            fd.append('metin', ham);
            fetch('gemini_dusunce.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    geminiDuzeltBtn.disabled = false;
                    geminiDuzeltBtn.textContent = 'Gemini ile düzelt';
                    if (data.error) { alert(data.error); return; }
                    var txt = (data.text || '').trim();
                    if (txt) {
                        editorEl.focus();
                        var sel = window.getSelection();
                        var range = document.createRange();
                        if (editorEl.childNodes.length) {
                            range.setStart(editorEl, editorEl.childNodes.length);
                            range.collapse(true);
                        } else {
                            range.setStart(editorEl, 0);
                            range.collapse(true);
                        }
                        sel.removeAllRanges();
                        sel.addRange(range);
                        document.execCommand('insertText', false, txt);
                    }
                })
                .catch(function() {
                    geminiDuzeltBtn.disabled = false;
                    geminiDuzeltBtn.textContent = 'Gemini ile düzelt';
                    alert('Düzeltme isteği başarısız.');
                });
        });
    }
})();
function openDusunceEditModal(el) {
    var d = JSON.parse(el.getAttribute('data-dusunce-json'));
    document.getElementById('dusunce_edit_id').value = d.id;
    document.getElementById('modal-dusunce-edit-editor').innerHTML = d.dusunce || '';
    document.getElementById('modal-dusunce-edit-sb').value = d.sayfa_baslangic || '';
    document.getElementById('modal-dusunce-edit-sbit').value = d.sayfa_bitis || '';
    openModal('modal-dusunce-edit');
}
document.getElementById('kapak') && document.getElementById('kapak').addEventListener('change', function(e) {
    var f = e.target.files[0];
    var wrap = document.querySelector('.kitap-kapak-wrap');
    if (!wrap || !f) return;
    var r = new FileReader();
    r.onload = function() {
        var prev = document.getElementById('kitap-kapak-preview');
        if (prev) prev.outerHTML = '<img src="' + r.result + '" alt="Kapak" id="kitap-kapak-preview">';
    };
    r.readAsDataURL(f);
});
</script>

</body>
</html>