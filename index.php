<?php
// index.php - Entry point untuk Railway
header('Content-Type: text/plain');
echo "Kimtim Backend API is running!\n";
echo "PHP Version: " . phpversion() . "\n";

// Redirect ke api_backend.php jika ada parameter action
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $apiFile = __DIR__ . '/api_backend.php';
    if (file_exists($apiFile)) {
        require_once $apiFile;
        exit;
    }
}

// Tampilkan info
echo "\nAvailable endpoints:\n";
echo "- /api_backend.php?action=popup-stats\n";
echo "- /api_backend.php?action=validate\n";
echo "- /api_backend.php?action=hit\n";
echo "- /telegram-webhook.php?token=YOUR_TOKEN\n";
echo "- /proxy_check.php?proxy=host:port:user:pass\n";
?>
