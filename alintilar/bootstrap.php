<?php
/**
 * Alıntı ve Düşünce modülü – yardımcı sabit ve CRUD fonksiyonları.
 * Çağıran sayfa (kitap.php) config ve session kontrolünü yapmış olmalı.
 */

if (!defined('ALINTI_UPLOAD_DIR')) {
    define('ALINTI_UPLOAD_DIR', __DIR__ . '/uploads');
}

$izin_verilen_uzantilar = ['jpg', 'jpeg', 'png', 'webp'];

/**
 * Kitaba ait alıntıları kayit tarihine göre getirir.
 */
function alintilar_for_kitap(PDO $pdo, $kitap_id) {
    $stmt = $pdo->prepare("SELECT * FROM alintilar WHERE kitap_id = :kitap_id ORDER BY kayit ASC");
    $stmt->execute(['kitap_id' => (int) $kitap_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kitaba ait tüm düşünceleri getirir (alıntıya bağlı ve bağımsız).
 */
function dusunceler_for_kitap(PDO $pdo, $kitap_id) {
    $stmt = $pdo->prepare("SELECT * FROM dusunceler WHERE kitap_id = :kitap_id ORDER BY kayit ASC");
    $stmt->execute(['kitap_id' => (int) $kitap_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kitabın kullanıcıya ait olduğunu doğrular.
 */
function kitap_kullaniciya_ait(PDO $pdo, $kitap_id, $user_id) {
    $stmt = $pdo->prepare("SELECT 1 FROM kitaplar WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => (int) $kitap_id, 'user_id' => (int) $user_id]);
    return (bool) $stmt->fetch();
}

/**
 * Alıntı fotoğrafı yükler; başarıda dosya adı, yoksa null döner.
 */
function alinti_foto_yukle(array $file) {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $info = pathinfo($file['name']);
    $uzanti = strtolower($info['extension'] ?? '');
    if (!in_array($uzanti, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return null;
    }
    $dir = ALINTI_UPLOAD_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $yeni_ad = uniqid('alinti_') . '.' . $uzanti;
    $hedef = $dir . '/' . $yeni_ad;
    if (move_uploaded_file($file['tmp_name'], $hedef)) {
        return $yeni_ad;
    }
    return null;
}

/**
 * Alıntı ekler. $foto_file = $_FILES['foto'] (opsiyonel).
 * Hata durumunda hata mesajı döner, başarıda null.
 */
function alinti_ekle(PDO $pdo, $user_id, $kitap_id, $alinti, $sayfa_baslangic, $sayfa_bitis, $foto_file = null) {
    if (!kitap_kullaniciya_ait($pdo, $kitap_id, $user_id)) {
        return 'Kitap bulunamadı veya yetkiniz yok.';
    }
    $alinti = trim($alinti);
    $foto_adi = null;
    if (!empty($foto_file['tmp_name']) && $foto_file['error'] === UPLOAD_ERR_OK) {
        $foto_adi = alinti_foto_yukle($foto_file);
    }
    if ($alinti === '' && empty($foto_adi)) {
        return 'Alıntı metni veya fotoğraf zorunludur.';
    }
    if ($alinti === '' && !empty($foto_adi)) {
        $alinti = '[Fotoğraf – metin sonra eklenecek]';
    }
    $stmt = $pdo->prepare("INSERT INTO alintilar (kitap_id, alinti, sayfa_baslangic, sayfa_bitis, foto) VALUES (:kitap_id, :alinti, :sayfa_baslangic, :sayfa_bitis, :foto)");
    $stmt->execute([
        'kitap_id' => (int) $kitap_id,
        'alinti' => $alinti,
        'sayfa_baslangic' => $sayfa_baslangic === '' || $sayfa_baslangic === null ? null : (int) $sayfa_baslangic,
        'sayfa_bitis' => $sayfa_bitis === '' || $sayfa_bitis === null ? null : (int) $sayfa_bitis,
        'foto' => $foto_adi,
    ]);
    return null;
}

/**
 * Alıntı günceller. $foto_file = $_FILES['foto'] (opsiyonel). Yeni yüklenmezse mevcut korunur.
 */
function alinti_guncelle(PDO $pdo, $user_id, $alinti_id, $kitap_id, $alinti, $sayfa_baslangic, $sayfa_bitis, $mevcut_foto = '', $foto_file = null) {
    if (!kitap_kullaniciya_ait($pdo, $kitap_id, $user_id)) {
        return 'Kitap bulunamadı veya yetkiniz yok.';
    }
    $stmt = $pdo->prepare("SELECT id, foto FROM alintilar WHERE id = :id AND kitap_id = :kitap_id");
    $stmt->execute(['id' => (int) $alinti_id, 'kitap_id' => (int) $kitap_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Alıntı bulunamadı.';
    }
    $alinti = trim($alinti);
    if ($alinti === '') {
        return 'Alıntı metni zorunludur.';
    }
    $foto_adi = $row['foto'] ?? $mevcut_foto;
    if (!empty($foto_file['tmp_name']) && $foto_file['error'] === UPLOAD_ERR_OK) {
        if (!empty($foto_adi) && file_exists(ALINTI_UPLOAD_DIR . '/' . $foto_adi)) {
            unlink(ALINTI_UPLOAD_DIR . '/' . $foto_adi);
        }
        $foto_adi = alinti_foto_yukle($foto_file);
    }
    $stmt = $pdo->prepare("UPDATE alintilar SET alinti = :alinti, sayfa_baslangic = :sayfa_baslangic, sayfa_bitis = :sayfa_bitis, foto = :foto WHERE id = :id AND kitap_id = :kitap_id");
    $stmt->execute([
        'alinti' => $alinti,
        'sayfa_baslangic' => $sayfa_baslangic === '' || $sayfa_baslangic === null ? null : (int) $sayfa_baslangic,
        'sayfa_bitis' => $sayfa_bitis === '' || $sayfa_bitis === null ? null : (int) $sayfa_bitis,
        'foto' => $foto_adi ?: null,
        'id' => (int) $alinti_id,
        'kitap_id' => (int) $kitap_id,
    ]);
    return null;
}

/**
 * Alıntı siler; foto varsa dosyayı da siler.
 */
function alinti_sil(PDO $pdo, $user_id, $alinti_id) {
    $stmt = $pdo->prepare("SELECT a.id, a.kitap_id, a.foto FROM alintilar a INNER JOIN kitaplar k ON a.kitap_id = k.id WHERE a.id = :id AND k.user_id = :user_id");
    $stmt->execute(['id' => (int) $alinti_id, 'user_id' => (int) $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Alıntı bulunamadı veya yetkiniz yok.';
    }
    if (!empty($row['foto']) && file_exists(ALINTI_UPLOAD_DIR . '/' . $row['foto'])) {
        unlink(ALINTI_UPLOAD_DIR . '/' . $row['foto']);
    }
    $del = $pdo->prepare("DELETE FROM alintilar WHERE id = :id");
    $del->execute(['id' => (int) $alinti_id]);
    return null;
}

/**
 * Düşünce ekler. $alinti_id null ise kitaba özel bağımsız düşünce.
 */
function dusunce_ekle(PDO $pdo, $user_id, $kitap_id, $dusunce, $sayfa_baslangic, $sayfa_bitis, $alinti_id = null) {
    if (!kitap_kullaniciya_ait($pdo, $kitap_id, $user_id)) {
        return 'Kitap bulunamadı veya yetkiniz yok.';
    }
    $dusunce = trim($dusunce);
    if ($dusunce === '') {
        return 'Düşünce metni zorunludur.';
    }
    if ($alinti_id !== null && $alinti_id !== '') {
        $alinti_id = (int) $alinti_id;
        $chk = $pdo->prepare("SELECT 1 FROM alintilar WHERE id = :id AND kitap_id = :kitap_id");
        $chk->execute(['id' => $alinti_id, 'kitap_id' => (int) $kitap_id]);
        if (!$chk->fetch()) {
            return 'Alıntı bulunamadı.';
        }
    } else {
        $alinti_id = null;
    }
    $stmt = $pdo->prepare("INSERT INTO dusunceler (kitap_id, alinti_id, dusunce, sayfa_baslangic, sayfa_bitis) VALUES (:kitap_id, :alinti_id, :dusunce, :sayfa_baslangic, :sayfa_bitis)");
    $stmt->execute([
        'kitap_id' => (int) $kitap_id,
        'alinti_id' => $alinti_id,
        'dusunce' => $dusunce,
        'sayfa_baslangic' => $sayfa_baslangic === '' || $sayfa_baslangic === null ? null : (int) $sayfa_baslangic,
        'sayfa_bitis' => $sayfa_bitis === '' || $sayfa_bitis === null ? null : (int) $sayfa_bitis,
    ]);
    return null;
}

/**
 * Düşünce günceller.
 */
function dusunce_guncelle(PDO $pdo, $user_id, $dusunce_id, $kitap_id, $dusunce, $sayfa_baslangic, $sayfa_bitis) {
    if (!kitap_kullaniciya_ait($pdo, $kitap_id, $user_id)) {
        return 'Kitap bulunamadı veya yetkiniz yok.';
    }
    $dusunce = trim($dusunce);
    if ($dusunce === '') {
        return 'Düşünce metni zorunludur.';
    }
    $stmt = $pdo->prepare("UPDATE dusunceler SET dusunce = :dusunce, sayfa_baslangic = :sayfa_baslangic, sayfa_bitis = :sayfa_bitis WHERE id = :id AND kitap_id = :kitap_id");
    $stmt->execute([
        'dusunce' => $dusunce,
        'sayfa_baslangic' => $sayfa_baslangic === '' || $sayfa_baslangic === null ? null : (int) $sayfa_baslangic,
        'sayfa_bitis' => $sayfa_bitis === '' || $sayfa_bitis === null ? null : (int) $sayfa_bitis,
        'id' => (int) $dusunce_id,
        'kitap_id' => (int) $kitap_id,
    ]);
    if ($stmt->rowCount() === 0) {
        return 'Düşünce bulunamadı.';
    }
    return null;
}

/**
 * Düşünce siler.
 */
function dusunce_sil(PDO $pdo, $user_id, $dusunce_id) {
    $stmt = $pdo->prepare("SELECT d.id FROM dusunceler d INNER JOIN kitaplar k ON d.kitap_id = k.id WHERE d.id = :id AND k.user_id = :user_id");
    $stmt->execute(['id' => (int) $dusunce_id, 'user_id' => (int) $user_id]);
    if (!$stmt->fetch()) {
        return 'Düşünce bulunamadı veya yetkiniz yok.';
    }
    $pdo->prepare("DELETE FROM dusunceler WHERE id = :id")->execute(['id' => (int) $dusunce_id]);
    return null;
}

/**
 * Kitap silinmeden önce bu kitaba ait alıntı fotoğraflarını sunucudan siler.
 */
function alinti_fotolarini_sil_kitap(PDO $pdo, $kitap_id) {
    $stmt = $pdo->prepare("SELECT foto FROM alintilar WHERE kitap_id = :kitap_id AND foto IS NOT NULL AND foto != ''");
    $stmt->execute(['kitap_id' => (int) $kitap_id]);
    $dir = ALINTI_UPLOAD_DIR;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['foto']) && file_exists($dir . '/' . $row['foto'])) {
            unlink($dir . '/' . $row['foto']);
        }
    }
}
