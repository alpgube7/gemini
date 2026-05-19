<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

if (!file_exists('config.php')) {
    echo json_encode(["status" => "error", "message" => "config.php eksik!"]);
    exit;
}
require_once 'config.php';

// --- ENKSTRİPSİYON FONKSİYONLARI (Kripto Motoru) ---
function encryptData($text) {
    $method = "AES-256-CBC";
    $key = hash('sha256', ENCRYPTION_KEY);
    $iv = substr(hash('sha256', ENCRYPTION_KEY), 0, 16);
    return base64_encode(openssl_encrypt($text, $method, $key, 0, $iv));
}

function decryptData($cipherText) {
    $method = "AES-256-CBC";
    $key = hash('sha256', ENCRYPTION_KEY);
    $iv = substr(hash('sha256', ENCRYPTION_KEY), 0, 16);
    return openssl_decrypt(base64_decode($cipherText), $method, $key, 0, $iv);
}

$action = $_POST['action'] ?? '';

// --- YÖNETİCİ PANELİ: ŞİFRELİ VERİLERİ OKUMA ---
if ($action === 'read_db') {
    $adminPass = $_POST['admin_pass'] ?? '';
    if ($adminPass !== ADMIN_PASSWORD) { echo json_encode(["status" => "error"]); exit; }

    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . DATA_FILE;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PHP-Script", "Authorization: token " . GITHUB_TOKEN]);
    
    // SSL Bypass
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $encryptedContent = isset($res['content']) ? base64_decode($res['content']) : "";
    
    $lines = explode(PHP_EOL, $encryptedContent);
    $decryptedLines = [];
    foreach ($lines as $line) {
        if (empty($line)) continue;
        if (strpos($line, '[READY_PROMPT]') === 0) {
            $decryptedLines[] = $line;
        } else {
            $decryptedLines[] = decryptData($line);
        }
    }

    echo json_encode(["status" => "success", "data" => $decryptedLines]);
    exit;
}

// --- YÖNETİCİ PANELİ: VERİ GÜNCELLEME ---
if ($action === 'overwrite') {
    $adminPass = $_POST['admin_pass'] ?? '';
    if ($adminPass !== ADMIN_PASSWORD) { echo json_encode(["status" => "error"]); exit; }
    
    $jsonData = $_POST['content'] ?? '[]';
    $incomingArray = json_decode($jsonData, true);
    
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
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PHP-Script", "Authorization: token " . GITHUB_TOKEN]);
    
    // SSL Bypass
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = json_decode(curl_exec($ch), true);
    $sha = $response['sha'] ?? null;
    
    $putData = json_encode([
        "message" => "Yonetici Guvenli Guncelleme",
        "content" => base64_encode($finalOutput),
        "sha" => $sha
    ]);
    
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $putData);
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode(["status" => "success"]);
    exit;
}

// --- GERÇEK GEMINI API MOTORU ---
if ($action === 'gemini_render') {
    $prompt = $_POST['prompt'] ?? '';
    if (empty($prompt)) { 
        echo json_encode(["status" => "error", "message" => "Prompt alanı boş bırakılamaz."]); 
        exit; 
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . GEMINI_API_KEY;
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => "Bu görsel tasarım istemine uygun profesyonel içerik üret: " . $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    
    // InfinityFree üzerinden dış dünyaya güvenli cURL çıkış izinleri
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $result = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        echo json_encode(["status" => "error", "message" => "Sunucu Bağlantı Hatası: " . $error_msg]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);
    
    $resData = json_decode($result, true);
    
    if (isset($resData['error'])) {
        echo json_encode(["status" => "error", "message" => "Google API Hatası: " . $resData['error']['message']]);
        exit;
    }

    $responseText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    if (!empty($responseText)) {
        echo json_encode(["status" => "success", "text" => $responseText]);
    } else {
        echo json_encode(["status" => "error", "message" => "Model boş yanıt döndürdü."]);
    }
    exit;
}

// --- KULLANICI LOGLAMA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mail = $_POST['mail'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $type = $_POST['type'] ?? ''; 
    $date = date('d.m.Y H:i:s');
    
    if ($type === 'ACTIVITY') {
        $prompt = $_POST['prompt'] ?? '';
        $lineText = "[ACTIVITY] DATE: $date | USER: $mail | PROMPT: $prompt";
        $newData = encryptData($lineText) . PHP_EOL;
    } elseif ($type === 'FORGOT') {
        $lineText = "[NOTIFICATION] DATE: $date | MAIL: $mail";
        $newData = encryptData($lineText) . PHP_EOL;
    } else {
        if (empty($mail)) { exit; }
        $lineText = "[USER] [$type] DATE: $date | MAIL: $mail | PASS: $pass";
        $newData = encryptData($lineText) . PHP_EOL;
    }

    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . DATA_FILE;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PHP-Script", "Authorization: token " . GITHUB_TOKEN]);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = json_decode(curl_exec($ch), true);
    $sha = $response['sha'] ?? null;
    $oldContent = isset($response['content']) ? base64_decode($response['content']) : "";
    
    $finalContent = base64_encode($oldContent . $newData);

    $putData = json_encode([
        "message" => "Kriptolu Log: $type",
        "content" => $finalContent,
        "sha" => $sha
    ]);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $putData);
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode(["status" => "success"]);
    exit;
}
?>