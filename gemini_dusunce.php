<?php
/**
 * Düşünce modalı için Gemini: ses kaydını metne çevirme (transcribe) ve metni düzeltme (duzelt).
 * Alıntı/Gemini ile aynı kullanıcı API anahtarı (users.gemini_api_key) kullanılır.
 * POST action=transcribe: audio (dosya veya base64) -> Türkçe metin
 * POST action=duzelt: metin -> düzeltilmiş metin
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

$stmtUser = $pdo->prepare("SELECT gemini_api_key FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (empty($userRow['gemini_api_key'])) {
    echo json_encode(['error' => 'Gemini API anahtarı tanımlı değil. Ayarlardan ekleyin.', 'text' => '']);
    exit;
}
$api_key = trim($userRow['gemini_api_key']);

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

if ($action === 'transcribe') {
    $audio_data = null;
    $mime_type = 'audio/webm';

    if (!empty($_FILES['audio']['tmp_name']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $audio_data = file_get_contents($_FILES['audio']['tmp_name']);
        $fn = $_FILES['audio']['name'];
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if ($ext === 'ogg' || $ext === 'oga') $mime_type = 'audio/ogg';
        elseif ($ext === 'mp3' || $ext === 'mpeg') $mime_type = 'audio/mp3';
        elseif ($ext === 'wav') $mime_type = 'audio/wav';
    } elseif (!empty($_POST['audio_base64'])) {
        $b64 = $_POST['audio_base64'];
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $b64, $m)) {
            $mime_type = $m[1];
            $audio_data = base64_decode($m[2], true);
        } else {
            $audio_data = base64_decode($b64, true);
        }
        if (!empty($_POST['mime_type'])) $mime_type = trim($_POST['mime_type']);
    }

    if (empty($audio_data) || strlen($audio_data) < 100) {
        echo json_encode(['error' => 'Ses verisi alınamadı veya çok kısa.', 'text' => '']);
        exit;
    }

    $prompt = 'Bu ses kaydını Türkçe metne dönüştür. Sadece çevrilen metni yaz, başka açıklama ekleme.';
    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mime_type,
                            'data' => base64_encode($audio_data)
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
        echo json_encode(['error' => 'Gemini isteği gönderilemedi.', 'text' => '']);
        exit;
    }
    $json = json_decode($response, true);
    if ($http_code !== 200 || !is_array($json)) {
        $err = isset($json['error']['message']) ? $json['error']['message'] : 'Gemini yanıt hatası.';
        echo json_encode(['error' => $err, 'text' => '']);
        exit;
    }

    $text = '';
    if (!empty($json['candidates'][0]['content']['parts'][0]['text'])) {
        $text = trim($json['candidates'][0]['content']['parts'][0]['text']);
    }
    echo json_encode(['text' => $text, 'error' => null]);
    exit;
}

if ($action === 'duzelt') {
    $ham_metin = isset($_POST['metin']) ? trim((string) $_POST['metin']) : '';
    if ($ham_metin === '') {
        echo json_encode(['error' => 'Düzeltilecek metin boş.', 'text' => '']);
        exit;
    }

    $prompt = <<<PROMPT
Aşağıdaki metin bir kitap hakkında sözlü olarak söylenmiş düşünce notudur. Görevin:
1. Yazım ve imla hatalarını düzelt.
2. Cümleleri akıcı ve okunaklı hale getir.
3. Sadece düzeltilmiş metni döndür; ek açıklama, başlık veya yorum yazma.

Metin:
---
{$ham_metin}
---
PROMPT;

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 2048,
        ]
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        echo json_encode(['error' => 'Gemini isteği gönderilemedi.', 'text' => '']);
        exit;
    }
    $json = json_decode($response, true);
    if ($http_code !== 200 || !is_array($json)) {
        $err = isset($json['error']['message']) ? $json['error']['message'] : 'Gemini yanıt hatası.';
        echo json_encode(['error' => $err, 'text' => '']);
        exit;
    }

    $text = '';
    if (!empty($json['candidates'][0]['content']['parts'][0]['text'])) {
        $text = trim($json['candidates'][0]['content']['parts'][0]['text']);
    }
    echo json_encode(['text' => $text, 'error' => null]);
    exit;
}

echo json_encode(['error' => 'Geçersiz action.', 'text' => '']);
