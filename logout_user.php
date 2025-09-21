<?php
session_start();
require_once 'db_config.php';

// اگر کاربر لاگین کرده باشد، اطلاعاتش را پاک می‌کنیم
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // به روزرسانی مرحله کاربر به ۱ برای شروع مجدد
    $stmt = $pdo->prepare("UPDATE users SET current_step = 1 WHERE id = ?");
    $stmt->execute([$user_id]);
    
    // پاک کردن session
    unset($_SESSION['user_id']);
    unset($_SESSION['current_step']);
    
    // پاک کردن cookie
    setcookie('user_id', '', time() - 3600, '/');
}

// هدایت به صفحه اصلی
header('Location: index.php');
exit;
?>