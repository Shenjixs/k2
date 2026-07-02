<?php
header('Content-Type: application/json');

// ============= AMBIL DARI ENVIRONMENT VARIABLE =============
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '8892880847:AAEndKJ4ZpnFs3M5bu5_Rqo54pmw7pmemJs';

// Security: Verify token in query string
$token = $_GET['token'] ?? '';
if ($token && $token !== $bot_token) {
    http_response_code(403);
    exit('Invalid token');
}

define('TELEGRAM_BOT_TOKEN', $bot_token);
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');

// Database config dari environment
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'kimtim_tokens';

// Get webhook data
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(400);
    exit('Invalid input');
}

// Handle message
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';

    // Handle /start command
    if ($text === '/start') {
        $response_text = "👋 Welcome to HirakoX!\n\n" .
            "Automated Stripe checkout hitter extension.\n\n" .
            "To use the extension:\n" .
            "1. Install the extension in Chrome\n" .
            "2. When prompted, enter your Telegram username\n" .
            "3. We'll send you a token here\n" .
            "4. Paste the token in the extension\n\n" .
            "Need help? /help";
        
        storeUser($chat_id, $username, $first_name);
        sendTelegramMessage($chat_id, $response_text);
    } 
    // Handle /help command
    else if ($text === '/help') {
        $help_text = "❓ Help & Support\n\n" .
            "• Check status: /status\n" .
            "• View your token: /token\n" .
            "• Report bug: /report [message]\n\n" .
            "For support: @kimtim";
        
        sendTelegramMessage($chat_id, $help_text);
    }
    // Handle /status command
    else if ($text === '/status') {
        $db = connectDb($db_host, $db_user, $db_pass, $db_name);
        if (!$db) {
            sendTelegramMessage($chat_id, "❌ Database connection failed");
            return;
        }
        $stmt = $db->prepare("SELECT token, created_at FROM tokens WHERE telegram = ? OR chat_id = ?");
        $stmt->bind_param('ss', $username, $chat_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $status_text = "✅ Status: Active\n\n" .
                "Token: Registered\n" .
                "Since: " . date('Y-m-d H:i:s', strtotime($row['created_at']));
            sendTelegramMessage($chat_id, $status_text);
        } else {
            $status_text = "❌ Status: Not Registered\n\n" .
                "You haven't generated a token yet.\n" .
                "Install the extension and click 'Get Token'";
            sendTelegramMessage($chat_id, $status_text);
        }
        $stmt->close();
        $db->close();
    }
    // Handle /token command
    else if ($text === '/token') {
        $db = connectDb($db_host, $db_user, $db_pass, $db_name);
        if (!$db) {
            sendTelegramMessage($chat_id, "❌ Database connection failed");
            return;
        }
        $stmt = $db->prepare("SELECT token FROM tokens WHERE telegram = ? OR chat_id = ? LIMIT 1");
        $stmt->bind_param('ss', $username, $chat_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $token_text = "🔐 Your Token:\n\n" .
                "`" . $row['token'] . "`\n\n" .
                "Keep this secret! Don't share with anyone.";
            sendTelegramMessage($chat_id, $token_text, true);
        } else {
            sendTelegramMessage($chat_id, "❌ No token found. Please generate one from the extension.");
        }
        $stmt->close();
        $db->close();
    }
    // Handle /report command
    else if (strpos($text, '/report') === 0) {
        $report_msg = substr($text, 8);
        logReport($db_host, $db_user, $db_pass, $db_name, $chat_id, $username, $report_msg);
        sendTelegramMessage($chat_id, "✅ Report received! Our team will review it.");
    }
}

function sendTelegramMessage($chat_id, $text, $markdown = false) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, TELEGRAM_API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    
    $params = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    
    if ($markdown) {
        $params['parse_mode'] = 'Markdown';
    }
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function connectDb($host, $user, $pass, $name) {
    $conn = new mysqli($host, $user, $pass, $name);
    if ($conn->connect_error) {
        error_log('DB Connection failed: ' . $conn->connect_error);
        return null;
    }
    return $conn;
}

function storeUser($chat_id, $username, $first_name) {
    global $db_host, $db_user, $db_pass, $db_name;
    $db = connectDb($db_host, $db_user, $db_pass, $db_name);
    if (!$db) return;
    
    $stmt = $db->prepare(
        "INSERT INTO telegram_users (chat_id, telegram_username, first_name) 
         VALUES (?, ?, ?) 
         ON DUPLICATE KEY UPDATE telegram_username = ?, first_name = ?"
    );
    $stmt->bind_param('sssss', $chat_id, $username, $first_name, $username, $first_name);
    $stmt->execute();
    $stmt->close();
    $db->close();
}

function logReport($host, $user, $pass, $name, $chat_id, $username, $message) {
    $db = connectDb($host, $user, $pass, $name);
    if (!$db) return;
    
    $stmt = $db->prepare(
        "INSERT INTO reports (chat_id, username, message, created_at) 
         VALUES (?, ?, ?, NOW())"
    );
    $stmt->bind_param('sss', $chat_id, $username, $message);
    $stmt->execute();
    $stmt->close();
    $db->close();
}
?>