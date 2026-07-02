<?php
// setup_db.php - Jalankan sekali lalu hapus
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'railway';

$conn = new mysqli($host, $user, $pass, $name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sqls = [
    "CREATE TABLE IF NOT EXISTS tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram VARCHAR(255) UNIQUE NOT NULL,
        token VARCHAR(32) UNIQUE NOT NULL,
        chat_id VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        status ENUM('active', 'inactive', 'banned') DEFAULT 'active'
    )",
    "CREATE TABLE IF NOT EXISTS telegram_users (
        chat_id VARCHAR(50) PRIMARY KEY,
        telegram_username VARCHAR(255),
        user_id VARCHAR(255),
        first_name VARCHAR(255),
        last_name VARCHAR(255),
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id VARCHAR(50),
        username VARCHAR(255),
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS hits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(32),
        full_card VARCHAR(32),
        amount VARCHAR(20),
        currency VARCHAR(10),
        merchant VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(32),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS bin_library (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site VARCHAR(255),
        bin VARCHAR(20),
        credit VARCHAR(100),
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        likes INT DEFAULT 0,
        dislikes INT DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS bin_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bin_id INT,
        user_id VARCHAR(50),
        user_name VARCHAR(100),
        vote ENUM('like', 'dislike'),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_vote (bin_id, user_id)
    )",
    "INSERT INTO bin_library (site, bin, credit) VALUES 
        ('example.com', '424242', 'HirakoX'),
        ('stripe.com', '453211', 'HirakoX')
        ON DUPLICATE KEY UPDATE site = VALUES(site)"
];

foreach ($sqls as $sql) {
    if ($conn->query($sql)) {
        echo "✅ " . substr($sql, 0, 50) . "...\n";
    } else {
        echo "❌ Error: " . $conn->error . "\n";
    }
}

$conn->close();
echo "\n✅ Database setup complete!";
?>
