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
    $education = $_POST['education'];
    
    $stmt = $pdo->prepare("INSERT INTO users (fullname, phone, position, education) VALUES (?, ?, ?, ?)");
    $stmt->execute([$fullname, $phone, $position, $education]);
    
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
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h2 class="text-center">ورود به فرآیند مقایسه و وزن دهی معیارها</h2>
                    </div>
                    <div class="card-body">
                        <p class="lead text-center">لطفاً اطلاعات خود را وارد کنید تا فرآیند مقایسه زوجی آغاز شود.
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
                                <label for="education" class="form-label">تحصیلات:</label>
                                <select class="form-select" id="education" name="education" required>
                                    <option value="">-- انتخاب کنید --</option>
                                    <option value="دیپلم">دیپلم</option>
                                    <option value="فوق دیپلم">فوق دیپلم</option>
                                    <option value="لیسانس">لیسانس</option>
                                    <option value="فوق لیسانس">فوق لیسانس</option>
                                    <option value="دکتری">دکتری</option>
                                    <option value="سایر">سایر</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="position" class="form-label">جایگاه سازمانی:</label>
                                <input type="text" class="form-control" id="position" name="position" required>
                            </div>

                            <a href="Help.pdf" class="btn btn-link" style="text-decoration:none">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                    <circle cx="12" cy="11" r="3" />
                                    <line x1="12" y1="7" x2="12" y2="9" />
                                    <line x1="12" y1="13" x2="12" y2="14" />
                                </svg>
                                برای آموزش نحوه تکمیل فرآیند اینجا کلیک کنید
                            </a>

                            <button type="submit" class="btn btn-primary w-100 mt-3">شروع</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>