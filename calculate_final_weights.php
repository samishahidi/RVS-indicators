<?php
session_start();
require_once 'db_config.php';

// بررسی احراز هویت ادمین
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// دریافت ماتریس معیارهای اصلی
$stmt = $pdo->query("SELECT * FROM matrices WHERE is_criteria_matrix = TRUE");
$main_matrix = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$main_matrix) {
    die("هیچ معیاری تعریف نشده است.");
}

// دریافت معیارهای اصلی
$stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? ORDER BY sort_order");
$stmt->execute([$main_matrix['id']]);
$criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($criteria)) {
    die("هیچ معیاری تعریف نشده است.");
}

// دریافت تمام کاربرانی که ماتریس را تکمیل کرده‌اند
$stmt = $pdo->query("SELECT * FROM users WHERE completed = TRUE ORDER BY created_at DESC");
$completed_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تابع محاسبه وزن‌ها و سازگاری برای یک کاربر
function calculateUserWeights($user_id, $main_matrix_id, $criteria) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
    $stmt->execute([$user_id, $main_matrix_id]);
    $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $comparisons_array = [];
    foreach ($comparisons as $comp) {
        $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
        $comparisons_array[$key] = $comp['value'];
    }
    
    $n = count($criteria);
    $pairwise_matrix = [];
    
    // مقداردهی اولیه ماتریس
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $pairwise_matrix[$i][$j] = ($i == $j) ? 1 : 0;
        }
    }

    // پر کردن ماتریس
    foreach ($comparisons_array as $key => $value) {
        list($criterion1_id, $criterion2_id) = explode('_', $key);
        
        $index1 = -1;
        $index2 = -1;
        
        foreach ($criteria as $idx => $criterion) {
            if ($criterion['id'] == $criterion1_id) $index1 = $idx;
            if ($criterion['id'] == $criterion2_id) $index2 = $idx;
        }
        
        if ($index1 >= 0 && $index2 >= 0) {
            $pairwise_matrix[$index1][$index2] = $value;
            $pairwise_matrix[$index2][$index1] = 1 / $value;
        }
    }

    // محاسبه وزن‌ها با روش AHP
    $column_sums = array_fill(0, $n, 0);
    for ($j = 0; $j < $n; $j++) {
        for ($i = 0; $i < $n; $i++) {
            $column_sums[$j] += $pairwise_matrix[$i][$j];
        }
    }

    $normalized_matrix = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $normalized_matrix[$i][$j] = $pairwise_matrix[$i][$j] / $column_sums[$j];
        }
    }

    $weights = [];
    for ($i = 0; $i < $n; $i++) {
        $row_sum = 0;
        for ($j = 0; $j < $n; $j++) {
            $row_sum += $normalized_matrix[$i][$j];
        }
        $weights[$i] = $row_sum / $n;
    }
    
    return $weights;
}

// محاسبه وزن‌های هر کاربر
$user_weights = [];
$valid_users = 0;

foreach ($completed_users as $user) {
    $weights = calculateUserWeights($user['id'], $main_matrix['id'], $criteria);
    if (!empty($weights)) {
        $user_weights[$user['id']] = [
            'weights' => $weights,
            'user_info' => $user
        ];
        $valid_users++;
    }
}

// محاسبه وزن‌های نهایی با میانگین‌گیری
$final_weights = [];
$consistency_analysis = [];

if ($valid_users > 0) {
    $n = count($criteria);
    
    // مقداردهی اولیه آرایه وزن‌های نهایی
    for ($i = 0; $i < $n; $i++) {
        $final_weights[$i] = 0;
    }
    
    // جمع‌زنی وزن‌های همه کاربران
    foreach ($user_weights as $user_data) {
        for ($i = 0; $i < $n; $i++) {
            $final_weights[$i] += $user_data['weights'][$i];
        }
    }
    
    // محاسبه میانگین
    for ($i = 0; $i < $n; $i++) {
        $final_weights[$i] /= $valid_users;
    }
    
    // تحلیل سازگاری گروهی
    $weight_matrix = [];
    foreach ($user_weights as $user_data) {
        $weight_matrix[] = $user_data['weights'];
    }
    
    // محاسبه انحراف معیار برای هر معیار
    $std_dev = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0;
        $sum_sq = 0;
        
        foreach ($user_weights as $user_data) {
            $weight = $user_data['weights'][$i];
            $sum += $weight;
            $sum_sq += $weight * $weight;
        }
        
        $mean = $sum / $valid_users;
        $variance = ($sum_sq / $valid_users) - ($mean * $mean);
        $std_dev[$i] = sqrt($variance);
    }
    
    $consistency_analysis = [
        'std_dev' => $std_dev,
        'cv' => [] // ضریب تغییرات
    ];
    
    for ($i = 0; $i < $n; $i++) {
        if ($final_weights[$i] > 0) {
            $consistency_analysis['cv'][$i] = ($std_dev[$i] / $final_weights[$i]) * 100;
        } else {
            $consistency_analysis['cv'][$i] = 0;
        }
    }
}

// ذخیره وزن‌های نهایی در دیتابیس (اختیاری)
if (isset($_POST['save_weights']) && !empty($final_weights)) {
    // حذف وزن‌های قبلی
    $stmt = $pdo->prepare("DELETE FROM final_weights");
    $stmt->execute();
    
    // ذخیره وزن‌های جدید
    foreach ($criteria as $i => $criterion) {
        $stmt = $pdo->prepare("INSERT INTO final_weights (criterion_id, weight, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$criterion['id'], $final_weights[$i]]);
    }
    
    $_SESSION['success'] = "وزن‌های نهایی با موفقیت ذخیره شدند.";
    header('Location: calculate_final_weights.php');
    exit;
}

// ایجاد جدول final_weights اگر وجود ندارد
$pdo->exec("
    CREATE TABLE IF NOT EXISTS final_weights (
        id INT AUTO_INCREMENT PRIMARY KEY,
        criterion_id INT NOT NULL,
        weight FLOAT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (criterion_id) REFERENCES criteria(id)
    )
");
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محاسبه وزن‌های نهایی</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .card-header {
        background: linear-gradient(45deg, #007bff, #0056b3);
    }

    .weight-bar {
        height: 20px;
        background: linear-gradient(90deg, #28a745, #20c997);
        border-radius: 5px;
    }

    .consistency-good {
        color: #198754;
        font-weight: bold;
    }

    .consistency-medium {
        color: #fd7e14;
        font-weight: bold;
    }

    .consistency-bad {
        color: #dc3545;
        font-weight: bold;
    }

    .user-avatar {
        width: 35px;
        height: 35px;
        background: linear-gradient(45deg, #6c757d, #495057);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 0.9rem;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="admin.php">پنل مدیریت ماتریس ها</a>
            <div class="navbar-nav">
                <a class="nav-link" href="admin.php">داشبورد</a>
                <a class="nav-link" href="admin_results.php">نتایج کاربران</a>
                <a class="nav-link active" href="calculate_final_weights.php">وزن‌های نهایی</a>
                <a class="nav-link" href="index.php" target="_blank">مشاهده سایت</a>
                <a class="nav-link" href="logout.php">خروج</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header text-white">
                <h4 class="card-title mb-0">
                    <i class="bi bi-calculator-fill me-2"></i>
                    محاسبه وزن‌های نهایی AHP
                </h4>
            </div>
            <div class="card-body">
                <!-- آمار کلی -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-primary"><?= count($completed_users) ?></h5>
                                <p class="card-text">تعداد کاربران تکمیل‌شده</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-success"><?= $valid_users ?></h5>
                                <p class="card-text">کاربران معتبر برای محاسبه</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-info"><?= count($criteria) ?></h5>
                                <p class="card-text">تعداد معیارها</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if ($valid_users > 0): ?>
                <!-- دکمه ذخیره وزن‌ها -->
                <form method="post" class="mb-4">
                    <button type="submit" name="save_weights" class="btn btn-success btn-lg">
                        <i class="bi bi-save-fill me-2"></i>
                        ذخیره وزن‌های نهایی
                    </button>
                </form>

                <!-- وزن‌های نهایی -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up-arrow me-2"></i>
                            وزن‌های نهایی معیارها (میانگین AHP)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>رتبه</th>
                                        <th>معیار</th>
                                        <th>وزن نهایی</th>
                                        <th>درصد</th>
                                        <th>انحراف معیار</th>
                                        <th>ضریب تغییرات</th>
                                        <th>نمودار</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // مرتب کردن معیارها بر اساس وزن نهایی
                                    $sorted_weights = [];
                                    foreach ($criteria as $i => $criterion) {
                                        $sorted_weights[] = [
                                            'criterion' => $criterion,
                                            'weight' => $final_weights[$i],
                                            'std_dev' => $consistency_analysis['std_dev'][$i],
                                            'cv' => $consistency_analysis['cv'][$i],
                                            'index' => $i
                                        ];
                                    }
                                    
                                    usort($sorted_weights, function($a, $b) {
                                        return $b['weight'] <=> $a['weight'];
                                    });
                                    
                                    $max_weight = max($final_weights);
                                    foreach ($sorted_weights as $rank => $item): 
                                        $criterion = $item['criterion'];
                                        $weight = $item['weight'];
                                        $percentage = round($weight * 100, 2);
                                        $std_dev = round($item['std_dev'], 4);
                                        $cv = round($item['cv'], 2);
                                        $bar_width = $max_weight > 0 ? ($weight / $max_weight * 100) : 0;
                                        
                                        // تعیین وضعیت سازگاری بر اساس ضریب تغییرات
                                        $consistency_class = 'consistency-good';
                                        $consistency_text = 'پایدار';
                                        if ($cv > 30) {
                                            $consistency_class = 'consistency-bad';
                                            $consistency_text = 'ناپایدار';
                                        } elseif ($cv > 15) {
                                            $consistency_class = 'consistency-medium';
                                            $consistency_text = 'متوسط';
                                        }
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?= $rank + 1 ?></span></td>
                                        <td><?= htmlspecialchars($criterion['name']) ?></td>
                                        <td><?= round($weight, 4) ?></td>
                                        <td><?= $percentage ?>%</td>
                                        <td><?= $std_dev ?></td>
                                        <td class="<?= $consistency_class ?>">
                                            <?= $cv ?>%
                                            <br>
                                            <small>(<?= $consistency_text ?>)</small>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="weight-bar" role="progressbar"
                                                    style="width: <?= $bar_width ?>%;"
                                                    aria-valuenow="<?= $percentage ?>" aria-valuemin="0"
                                                    aria-valuemax="100">
                                                    <span class="ps-2 text-white"><?= $percentage ?>%</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- لیست کاربران مشارکت‌کننده -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-people-fill me-2"></i>
                            کاربران مشارکت‌کننده در محاسبه
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($user_weights as $user_id => $user_data): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="user-avatar me-3">
                                                <?= substr($user_data['user_info']['fullname'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <h6 class="mb-0">
                                                    <?= htmlspecialchars($user_data['user_info']['fullname']) ?></h6>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($user_data['user_info']['position']) ?>
                                                    <?php if (!empty($user_data['user_info']['education'])): ?>
                                                    - <?= htmlspecialchars($user_data['user_info']['education']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            تاریخ تکمیل:
                                            <?= date('Y/m/d', strtotime($user_data['user_info']['created_at'])) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <div class="alert alert-warning text-center">
                    <i class="bi bi-exclamation-triangle display-4"></i>
                    <h4 class="mt-3">داده‌ای برای محاسبه وجود ندارد</h4>
                    <p class="mb-0">هیچ کاربری ماتریس را به طور کامل تکمیل نکرده است.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>