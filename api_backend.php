<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============= AMBIL DARI ENVIRONMENT VARIABLE (RAILWAY) =============
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'kimtim_tokens';

// Telegram Bot Token dari environment
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '8892880847:AAEndKJ4ZpnFs3M5bu5_Rqo54pmw7pmemJs';

define('DB_HOST', $db_host);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_NAME', $db_name);
define('TELEGRAM_BOT_TOKEN', $bot_token);
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');

$action = $_GET['action'] ?? '';

switch($action) {
    case 'register-token':
        handleRegisterToken();
        break;
    case 'verify-token':
        handleVerifyToken();
        break;
    case 'validate':
        handleValidateToken();
        break;
    case 'hit':
        handleHit();
        break;
    case 'attempt':
        handleAttempt();
        break;
    case 'hit-counts':
        handleHitCounts();
        break;
    case 'popup-stats':
        handlePopupStats();
        break;
    case 'leaderboard':
        handleLeaderboard();
        break;
    case 'bin-library':
        handleBinLibrary();
        break;
    case 'bin-feedback':
        handleBinFeedback();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
    return $conn;
}

function handleRegisterToken() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['telegram']) || !isset($input['token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }

    $telegram = $input['telegram'];
    $token = $input['token'];
    $timestamp = $input['timestamp'] ?? time();

    $conn = getDbConnection();

    $stmt = $conn->prepare("
        INSERT INTO tokens (telegram, token, created_at, status) 
        VALUES (?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE token = ?, updated_at = NOW()
    ");
    
    $stmt->bind_param('ssss', $telegram, $token, $timestamp, $token);
    
    if ($stmt->execute()) {
        // Kirim token ke user via Telegram (gunakan chat_id dari database)
        sendTelegramMessageToUser($telegram, "🔐 Your kimtim token:\n\n`$token`\n\nPaste this in the extension to activate.");
        
        echo json_encode([
            'success' => true,
            'message' => 'Token sent to Telegram',
            'token' => $token
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to register token']);
    }

    $stmt->close();
    $conn->close();
}

function handleVerifyToken() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing token']);
        return;
    }

    $token = $input['token'];
    $conn = getDbConnection();

    $stmt = $conn->prepare("SELECT telegram, created_at FROM tokens WHERE token = ? AND status = 'active'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'valid' => true,
            'telegram' => $row['telegram'],
            'created_at' => $row['created_at']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'valid' => false, 'message' => 'Invalid token']);
    }

    $stmt->close();
    $conn->close();
}

function handleValidateToken() {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing token']);
        return;
    }
    
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("
        SELECT id, telegram, token, created_at, status 
        FROM tokens 
        WHERE token = ? AND status = 'active'
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'user_id' => $user['id'],
            'username' => $user['telegram'],
            'first_name' => $user['telegram'],
            'hits' => 0,
            'attempts' => 0,
            'global_hits' => 0
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
    }
    
    $stmt->close();
    $conn->close();
}

function handleHit() {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    $full_card = $input['full_card'] ?? '';
    $amount = $input['amount'] ?? '0';
    $currency = $input['currency'] ?? 'usd';
    $merchant = $input['merchant'] ?? 'N/A';
    
    if (empty($token) || empty($full_card)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    $conn = getDbConnection();
    
    // Update atau insert hit
    $stmt = $conn->prepare("
        INSERT INTO hits (token, full_card, amount, currency, merchant, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('sssss', $token, $full_card, $amount, $currency, $merchant);
    $stmt->execute();
    $stmt->close();
    
    // Hitung total hits user
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM hits WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $userHits = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    
    // Hitung global hits
    $result = $conn->query("SELECT COUNT(*) as total FROM hits");
    $globalHits = $result->fetch_assoc()['total'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'user_hits' => (int)$userHits,
        'global_hits' => (int)$globalHits,
        'hits' => (int)$userHits
    ]);
    
    $conn->close();
}

function handleAttempt() {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Missing token']);
        return;
    }
    
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("
        INSERT INTO attempts (token, created_at)
        VALUES (?, NOW())
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM attempts WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'attempts' => (int)$attempts
    ]);
    
    $conn->close();
}

function handleHitCounts() {
    $token = $_GET['token'] ?? '';
    $conn = getDbConnection();
    
    $result = $conn->query("SELECT COUNT(*) as total FROM hits");
    $globalHits = $result->fetch_assoc()['total'] ?? 0;
    
    $userHits = 0;
    if (!empty($token)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM hits WHERE token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $userHits = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
    }
    
    echo json_encode([
        'success' => true,
        'global_hits' => (int)$globalHits,
        'user_hits' => (int)$userHits
    ]);
    
    $conn->close();
}

function handlePopupStats() {
    $conn = getDbConnection();
    
    $result = $conn->query("SELECT COUNT(DISTINCT token) as total FROM tokens");
    $users = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM hits");
    $hits = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM hits WHERE DATE(created_at) = CURDATE()");
    $today = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM hits WHERE YEARWEEK(created_at) = YEARWEEK(NOW())");
    $week = $result->fetch_assoc()['total'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'total_users' => (int)$users,
        'total_hits' => (int)$hits,
        'hits_today' => (int)$today,
        'hits_week' => (int)$week
    ]);
    
    $conn->close();
}

function handleLeaderboard() {
    $limit = (int)($_GET['limit'] ?? 50);
    $userId = $_GET['user_id'] ?? '';
    
    $conn = getDbConnection();
    
    $query = "
        SELECT t.telegram as username, t.id as user_id, COUNT(h.id) as hits
        FROM tokens t
        LEFT JOIN hits h ON t.token = h.token
        GROUP BY t.id
        ORDER BY hits DESC
        LIMIT ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $leaderboard = [];
    while ($row = $result->fetch_assoc()) {
        $leaderboard[] = [
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'first_name' => $row['username'],
            'hits' => (int)$row['hits']
        ];
    }
    $stmt->close();
    
    $myRank = null;
    if (!empty($userId)) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) + 1 as rank
            FROM (
                SELECT t.id, COUNT(h.id) as hits
                FROM tokens t
                LEFT JOIN hits h ON t.token = h.token
                GROUP BY t.id
            ) as ranks
            WHERE hits > (
                SELECT COUNT(h.id) as hits
                FROM tokens t
                LEFT JOIN hits h ON t.token = h.token
                WHERE t.id = ?
            )
        ");
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $myRank = $result->fetch_assoc();
        $stmt->close();
    }
    
    echo json_encode([
        'success' => true,
        'leaderboard' => $leaderboard,
        'my_rank' => $myRank
    ]);
    
    $conn->close();
}

function handleBinLibrary() {
    $userId = $_GET['user_id'] ?? '';
    $conn = getDbConnection();
    
    $result = $conn->query("
        SELECT id, site, bin, credit, added_at, likes, dislikes
        FROM bin_library
        ORDER BY added_at DESC
    ");
    
    $bins = [];
    while ($row = $result->fetch_assoc()) {
        $userVote = null;
        if (!empty($userId)) {
            $stmt = $conn->prepare("SELECT vote FROM bin_votes WHERE bin_id = ? AND user_id = ?");
            $stmt->bind_param('is', $row['id'], $userId);
            $stmt->execute();
            $voteResult = $stmt->get_result();
            if ($voteResult->num_rows > 0) {
                $userVote = $voteResult->fetch_assoc()['vote'];
            }
            $stmt->close();
        }
        $bins[] = [
            'id' => $row['id'],
            'site' => $row['site'],
            'bin' => $row['bin'],
            'credit' => $row['credit'],
            'added_at' => $row['added_at'],
            'likes' => (int)$row['likes'],
            'dislikes' => (int)$row['dislikes'],
            'user_vote' => $userVote
        ];
    }
    
    echo json_encode([
        'success' => true,
        'bins' => $bins
    ]);
    
    $conn->close();
}

function handleBinFeedback() {
    $input = json_decode(file_get_contents('php://input'), true);
    $binId = (int)($input['id'] ?? 0);
    $vote = $input['vote'] ?? '';
    $userId = $input['user_id'] ?? '';
    $userName = $input['user_name'] ?? '';
    
    if (empty($binId) || empty($vote) || empty($userId)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    $conn = getDbConnection();
    
    // Cek apakah user sudah vote
    $stmt = $conn->prepare("SELECT vote FROM bin_votes WHERE bin_id = ? AND user_id = ?");
    $stmt->bind_param('is', $binId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        $oldVote = $existing['vote'];
        
        // Update vote
        $stmt = $conn->prepare("UPDATE bin_votes SET vote = ? WHERE bin_id = ? AND user_id = ?");
        $stmt->bind_param('sis', $vote, $binId, $userId);
        $stmt->execute();
        $stmt->close();
        
        // Update likes/dislikes di bin_library
        if ($oldVote === 'like') {
            $conn->query("UPDATE bin_library SET likes = likes - 1 WHERE id = $binId");
        } elseif ($oldVote === 'dislike') {
            $conn->query("UPDATE bin_library SET dislikes = dislikes - 1 WHERE id = $binId");
        }
        
        if ($vote === 'like') {
            $conn->query("UPDATE bin_library SET likes = likes + 1 WHERE id = $binId");
        } elseif ($vote === 'dislike') {
            $conn->query("UPDATE bin_library SET dislikes = dislikes + 1 WHERE id = $binId");
        }
    } else {
        // Insert vote baru
        $stmt = $conn->prepare("INSERT INTO bin_votes (bin_id, user_id, user_name, vote) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $binId, $userId, $userName, $vote);
        $stmt->execute();
        $stmt->close();
        
        if ($vote === 'like') {
            $conn->query("UPDATE bin_library SET likes = likes + 1 WHERE id = $binId");
        } elseif ($vote === 'dislike') {
            $conn->query("UPDATE bin_library SET dislikes = dislikes + 1 WHERE id = $binId");
        }
    }
    
    // Ambil data terbaru
    $result = $conn->query("SELECT likes, dislikes FROM bin_library WHERE id = $binId");
    $data = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'likes' => (int)$data['likes'],
        'dislikes' => (int)$data['dislikes'],
        'user_vote' => $vote
    ]);
    
    $conn->close();
}

function sendTelegramMessageToUser($userIdentifier, $message) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log("DB connection failed in sendTelegramMessageToUser");
        return false;
    }
    
    $chat_id = $userIdentifier;
    $stmt = $conn->prepare("SELECT chat_id FROM telegram_users WHERE telegram_username = ? LIMIT 1");
    $stmt->bind_param('s', $userIdentifier);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $chat_id = $row['chat_id'];
    }
    $stmt->close();
    $conn->close();
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, TELEGRAM_API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Telegram send failed: HTTP $httpCode");
    }
    
    return json_decode($response, true);
}
?>