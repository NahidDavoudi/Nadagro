<?php
// helpers.php
declare(strict_types=1);

function input($key, $default = null) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json) && array_key_exists($key, $json)) return $json[$key];
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, null, 'کاربر وارد نشده', 401);
    }
}

function requireAdmin() {
    requireAuth(); // First, ensure the user is logged in.
    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        jsonResponse(false, null, 'دسترسی غیر مجاز', 403);
    }
}


function generateReferralCode(string $phone): string {
    // کد ریفرال یکتا تولید میکنیم: 6 کاراکتر از md5(زمان+phone)
    return strtoupper(substr(md5($phone . microtime(true)), 0, 6));
}

function logError(string $message) {
    error_log($message);
}
function sendTelegramNotification(string $message): void {
    // توکن و آیدی خود را اینجا قرار دهید
    $botToken = '8426938024:AAFciQNunsvIqpXNl-jwWjisxteXDUeBOxg'; // <--- توکن ربات را اینجا کپی کنید
    $chatId   = '7958508995';         // <--- آیدی عددی خود را اینجا کپی کنید

    if ($botToken === 'YOUR_HTTP_API_TOKEN' || $chatId === 'YOUR_CHAT_ID') {
        logError("Telegram Bot Token or Chat ID is not configured.");
        return;
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $params = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}