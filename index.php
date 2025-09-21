<?php
session_start();
require_once 'db_config.php';

// بررسی کوکی کاربر
$user_id = null;
if (isset($_COOKIE['user_id'])) {
    $user_id = $_COOKIE['user_id'];
    
    // بررسی وجود کاربر در دیتابیس
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['current_step'] = $user['current_step'];
        header('Location: form.php');
        exit;
    }
}

// پردازش فرم ثبت اطلاعات کاربر
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = $_POST['fullname'];
    $phone = $_POST['phone'];
    $position = $_POST['position'];
    
    $stmt = $pdo->prepare("INSERT INTO users (fullname, phone, position) VALUES (?, ?, ?)");
    $stmt->execute([$fullname, $phone, $position]);
    
    $user_id = $pdo->lastInsertId();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['current_step'] = 1;
    
    // به روزرسانی مرحله کاربر
    $stmt = $pdo->prepare("UPDATE users SET current_step = 1 WHERE id = ?");
    $stmt->execute([$user_id]);
    
    // تنظیم کوکی برای 30 روز
    setcookie('user_id', $user_id, time() + (30 * 24 * 60 * 60), '/');
    
    header('Location: form.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم وزن‌دهی معیارهای پروژه عمرانی</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">سیستم وزن‌دهی ماتریکس‌ها</a>
            <div class="navbar-nav">
                <a class="nav-link" href="admin_login.php">ورود ادمین</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h2 class="text-center">سیستم وزن‌دهی معیارهای پروژه عمرانی</h2>
                    </div>
                    <div class="card-body">
                        <p class="lead text-center">لطفاً اطلاعات خود را وارد کنید تا فرآیند وزن‌دهی معیارها آغاز شود.
                        </p>

                        <form method="post">
                            <div class="mb-3">
                                <label for="fullname" class="form-label">نام و نام خانوادگی:</label>
                                <input type="text" class="form-control" id="fullname" name="fullname" required>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">شماره همراه:</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required>
                            </div>
                            <div class="mb-3">
                                <label for="position" class="form-label">سمت یا جایگاه:</label>
                                <input type="text" class="form-control" id="position" name="position" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">شروع فرآیند وزن‌دهی</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>