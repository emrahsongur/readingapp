<?php
/**
 * Alıntı fotoğrafından Gemini ile metin ve sayfa numarası çıkarır.
 * POST: alinti_id (ve isteğe bağlı crop için image_base64 + mime_type)
 * Yanıt: JSON { "text": "...", "sayfa": N veya null }
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Oturum gerekli.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST gerekli.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$alinti_id = isset($input['alinti_id']) ? (int) $input['alinti_id'] : 0;
if ($alinti_id < 1) {
    echo json_encode(['error' => 'Geçersiz alıntı.', 'text' => '', 'sayfa' => null]);
    exit;
}

// Kullanıcının Gemini API anahtarını al
$stmtUser = $pdo->prepare("SELECT gemini_api_key FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (empty($userRow['gemini_api_key'])) {
    echo json_encode(['error' => 'Gemini API anahtarı tanımlı değil. Ayarlardan ekleyin.', 'text' => '', 'sayfa' => null]);
    exit;
}
$api_key = trim($userRow['gemini_api_key']);

// Alıntıyı ve foto yolunu al (kullanıcının kitabı olmalı)
$stmtAlinti = $pdo->prepare("
    SELECT a.id, a.kitap_id, a.foto FROM alintilar a
    INNER JOIN kitaplar k ON a.kitap_id = k.id
    WHERE a.id = ? AND k.user_id = ?
");
$stmtAlinti->execute([$alinti_id, $user_id]);
$alintiRow = $stmtAlinti->fetch(PDO::FETCH_ASSOC);
if (!$alintiRow || empty($alintiRow['foto'])) {
    echo json_encode(['error' => 'Alıntı veya fotoğraf bulunamadı.', 'text' => '', 'sayfa' => null]);
    exit;
}

$foto_path = __DIR__ . '/alintilar/uploads/' . $alintiRow['foto'];
if (!is_file($foto_path) || !is_readable($foto_path)) {
    echo json_encode(['error' => 'Fotoğraf dosyası okunamadı.', 'text' => '', 'sayfa' => null]);
    exit;
}

$image_data = base64_encode(file_get_contents($foto_path));
$mime = 'image/jpeg';
$ext = strtolower(pathinfo($alintiRow['foto'], PATHINFO_EXTENSION));
if ($ext === 'png') $mime = 'image/png';
elseif ($ext === 'webp') $mime = 'image/webp';

// İstemci kırpılmış görsel gönderdiyse onu kullan
if (!empty($input['image_base64'])) {
    $image_data = $input['image_base64'];
    if (is_string($image_data) && preg_match('/^data:([^;]+);base64,(.+)$/', $image_data, $m)) {
        $mime = $m[1];
        $image_data = $m[2];
    }
}
if (!empty($input['mime_type'])) {
    $mime = $input['mime_type'];
}

$prompt = <<<'PROMPT'
# Rol
Sen bir "Görselden Metne Dönüştürücü" asistanısın. Kullanıcının paylaştığı kitap sayfası veya görseldeki metinleri yüksek doğrulukla dijital metne dönüştürürsün.

# Görev ve Kurallar
1. **Doğrudan Yanıt:** Karşılama mesajı, giriş cümlesi ("İşte metniniz:" gibi) veya sonuç cümlesi yazma.
2. **Format:** Yanıtı sadece ve sadece kod bloğu içinde sun: ```text ... ```
3. **Paragraf düzeni:** Kitaptaki gibi satır satır bölme. Her paragrafı tek blok halinde, kesintisiz yaz (satır sonlarını paragraf içinde boşluk veya satır atlaması yapma). Paragraflar arasında tek satır atlaması kullan. Yani metin akışı: paragraf1 metni bitişik, sonra satır atla, paragraf2 metni bitişik, vb.
4. **Sadakat:** İmla ve noktalamayı görseldeki gibi koru. Ek analiz veya yorum yazma.
5. **İşaretlenen yer (öncelik):** Sayfada fosforlu kalem veya renkli marker ile işaretlenmiş bir bölge varsa, SADECE o işaretli bölgenin metnini çıkar; işaret yoksa veya tüm sayfa işaretliyse tüm sayfa metnini çıkar.
6. **Sayfa numarası:** Kitap sayfa numarası görünüyorsa metnin sonuna (Sayfa xx) veya (Sayfa xx-xx) yaz.

Yanıtını sadece kod bloğu içinde ver.
PROMPT;

$payload = [
    'contents' => [
        [
            'parts' => [
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data' => $image_data
                    ]
                ],
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 2048,
    ]
];

// Desteklenen modeller: gemini-2.5-flash, gemini-2.5-pro, gemini-3-flash-preview, gemini-3-pro-preview (ListModels ile kontrol edin)
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . urlencode($api_key);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    echo json_encode(['error' => 'Gemini isteği gönderilemedi.', 'text' => '', 'sayfa' => null]);
    exit;
}

$json = json_decode($response, true);
if ($http_code !== 200 || !is_array($json)) {
    $err = isset($json['error']['message']) ? $json['error']['message'] : 'Gemini yanıt hatası.';
    echo json_encode(['error' => $err, 'text' => '', 'sayfa' => null]);
    exit;
}

$text = '';
$sayfa = null;

if (!empty($json['candidates'][0]['content']['parts'][0]['text'])) {
    $raw = trim($json['candidates'][0]['content']['parts'][0]['text']);
    // Kod bloğu içeriğini çıkar (```text ... ``` veya ``` ... ```)
    if (preg_match('/```(?:text)?\s*\n?(.*?)\n?```/s', $raw, $block)) {
        $text = trim($block[1]);
    } else {
        $text = $raw;
    }
    // (Sayfa xx) veya (Sayfa xx-xx) formatında sayfa numarası
    if (preg_match('/\s*\(Sayfa\s+(\d+)(?:\s*-\s*(\d+))?\)\s*$/u', $text, $m)) {
        $sayfa = (int) $m[1];
        $text = trim(preg_replace('/\s*\(Sayfa\s+\d+(?:\s*-\s*\d+)?\)\s*$/u', '', $text));
    } elseif (preg_match('/\(Sayfa\s+(\d+)/u', $text, $m)) {
        $sayfa = (int) $m[1];
    }
}

echo json_encode(['text' => $text, 'sayfa' => $sayfa, 'error' => null]);
