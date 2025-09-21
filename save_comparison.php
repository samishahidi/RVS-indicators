<?php
session_start();
require_once 'db_config.php';

// بررسی وجود کاربر
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matrix_id = $_POST['matrix_id'];
    $comparisons = $_POST['comparisons'];
    
    foreach ($comparisons as $comp) {
        $criterion1_id = $comp['criterion1'];
        $criterion2_id = $comp['criterion2'];
        $value = $comp['value'];
        
        // بررسی وجود مقایسه قبلی
        $stmt = $pdo->prepare("SELECT id FROM comparisons WHERE user_id = ? AND criterion1_id = ? AND criterion2_id = ? AND matrix_id = ?");
        $stmt->execute([$user_id, $criterion1_id, $criterion2_id, $matrix_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // به روزرسانی مقایسه موجود
            $stmt = $pdo->prepare("UPDATE comparisons SET value = ? WHERE id = ?");
            $stmt->execute([$value, $existing['id']]);
        } else {
            // درج مقایسه جدید
            $stmt = $pdo->prepare("INSERT INTO comparisons (user_id, criterion1_id, criterion2_id, value, matrix_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $criterion1_id, $criterion2_id, $value, $matrix_id]);
        }
    }
    
    echo 'success';
} else {
    http_response_code(400);
    echo 'invalid request';
}
?>