<?php
session_start();
require_once 'db_config.php';

// بررسی احراز هویت ادمین
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// دریافت لیست کاربران
$users_stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// اگر کاربر خاصی انتخاب شده باشد
$selected_user = null;
$user_results = [];
$consistency_data = [];

if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    // دریافت اطلاعات کاربر انتخاب شده
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_user) {
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

        // دریافت مقایسه‌های کاربر
        $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
        $stmt->execute([$user_id, $main_matrix['id']]);
        $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ایجاد آرایه مقایسه‌ها
        $comparisons_array = [];
        foreach ($comparisons as $comp) {
            $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
            $comparisons_array[$key] = $comp['value'];
        }

        // تعداد معیارها
        $n = count($criteria);
        $comparisons_needed = ($n * ($n - 1)) / 2;
        $comparisons_done = count($comparisons);
        $is_complete = $comparisons_done >= $comparisons_needed;

        // تابع محاسبه وزن‌ها و سازگاری
        function calculateWeightsAndConsistency($criteria, $comparisons_array) {
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

            // محاسبه وزن‌ها
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

            // محاسبه سازگاری
            $weighted_sum_vector = [];
            for ($i = 0; $i < $n; $i++) {
                $sum = 0;
                for ($j = 0; $j < $n; $j++) {
                    $sum += $pairwise_matrix[$i][$j] * $weights[$j];
                }
                $weighted_sum_vector[$i] = $sum;
            }

            $lambda_sum = 0;
            for ($i = 0; $i < $n; $i++) {
                $lambda_sum += $weighted_sum_vector[$i] / $weights[$i];
            }
            
            $lambda_max = $lambda_sum / $n;
            $ci = ($lambda_max - $n) / ($n - 1);
            
            $ri_values = [
                1 => 0.00, 2 => 0.00, 3 => 0.58, 4 => 0.90, 5 => 1.12,
                6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49,
                11 => 1.51, 12 => 1.48, 13 => 1.56, 14 => 1.57, 15 => 1.59
            ];
            
            $ri = $ri_values[$n] ?? 1.60;
            $cr = $ci / $ri;
            
            return [
                'weights' => $weights,
                'consistency' => [
                    'lambda_max' => round($lambda_max, 4),
                    'ci' => round($ci, 4),
                    'ri' => round($ri, 4),
                    'cr' => round($cr, 4),
                    'is_consistent' => $cr <= 0.1
                ]
            ];
        }

        // محاسبه نتایج برای کاربر
        if ($is_complete && $n > 0) {
            $user_results = calculateUserWeightsAndConsistency($criteria, $comparisons_array);
            $consistency_data = $user_results['consistency'];
        }

        // ایجاد ماتریس نمایش
        $display_matrix = getUserComparisonMatrixForDisplay($criteria, $comparisons_array);

        $user_results_data = [
            'criteria' => $criteria,
            'comparisons' => $comparisons_array,
            'display_matrix' => $display_matrix,
            'weights' => $user_results['weights'] ?? [],
            'consistency' => $consistency_data ?? [],
            'is_complete' => $is_complete,
            'comparisons_done' => $comparisons_done,
            'comparisons_needed' => $comparisons_needed
        ];
    }
}

if (isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    // حذف مقایسه‌های کاربر
    $stmt = $pdo->prepare("DELETE FROM comparisons WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    // حذف خود کاربر
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    
    header('Location: admin_results.php');
    exit;
}


// تابع دریافت ماتریس مقایسه‌های کاربر برای نمایش
function getUserComparisonMatrixForDisplay($criteria, $comparisons_array) {
    $n = count($criteria);
    $display_matrix = [];
    
    // مقداردهی اولیه ماتریس نمایش
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i == $j) {
                $display_matrix[$i][$j] = ['value' => 1, 'type' => 'diagonal'];
            } else {
                $criterion1_id = $criteria[$i]['id'];
                $criterion2_id = $criteria[$j]['id'];
                
                $key1 = $criterion1_id . '_' . $criterion2_id;
                $key2 = $criterion2_id . '_' . $criterion1_id;
                
                if (isset($comparisons_array[$key1])) {
                    $display_matrix[$i][$j] = [
                        'value' => $comparisons_array[$key1], 
                        'type' => 'direct'
                    ];
                } elseif (isset($comparisons_array[$key2])) {
                    $display_matrix[$i][$j] = [
                        'value' => round(1 / $comparisons_array[$key2], 2), 
                        'type' => 'inverse'
                    ];
                } else {
                    $display_matrix[$i][$j] = [
                        'value' => '-', 
                        'type' => 'empty'
                    ];
                }
            }
        }
    }
    
    return $display_matrix;
}

// تابع محاسبه وزن‌ها و سازگاری (همان تابع قبلی اما با نام متفاوت برای جلوگیری از تداخل)
function calculateUserWeightsAndConsistency($criteria, $comparisons_array) {
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

    // محاسبه وزن‌ها
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

    // محاسبه سازگاری
    $weighted_sum_vector = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0;
        for ($j = 0; $j < $n; $j++) {
            $sum += $pairwise_matrix[$i][$j] * $weights[$j];
        }
        $weighted_sum_vector[$i] = $sum;
    }

    $lambda_sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $lambda_sum += $weighted_sum_vector[$i] / $weights[$i];
    }
    
    $lambda_max = $lambda_sum / $n;
    $ci = ($lambda_max - $n) / ($n - 1);
    
    $ri_values = [
        1 => 0.00, 2 => 0.00, 3 => 0.58, 4 => 0.90, 5 => 1.12,
        6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49,
        11 => 1.51, 12 => 1.48, 13 => 1.56, 14 => 1.57, 15 => 1.59
    ];
    
    $ri = $ri_values[$n] ?? 1.60;
    $cr = $ci / $ri;
    
    return [
        'weights' => $weights,
        'consistency' => [
            'lambda_max' => round($lambda_max, 4),
            'ci' => round($ci, 4),
            'ri' => round($ri, 4),
            'cr' => round($cr, 4),
            'is_consistent' => $cr <= 0.1
        ]
    ];
}

?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت نتایج کاربران</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .matrix-table {
        border-collapse: collapse;
        width: 100%;
        margin-bottom: 30px;
        font-size: 0.9rem;
    }

    .matrix-table th,
    .matrix-table td {
        border: 1px solid #dee2e6;
        padding: 8px;
        text-align: center;
    }

    .matrix-table th {
        background-color: #f8f9fa;
        font-weight: bold;
    }

    .diagonal {
        background-color: #e9ecef;
        font-weight: bold;
    }

    .consistency-good {
        color: #198754;
        font-weight: bold;
    }

    .consistency-bad {
        color: #dc3545;
        font-weight: bold;
    }

    .weight-bar {
        height: 20px;
        background: linear-gradient(90deg, #007bff, #0056b3);
        border-radius: 5px;
    }

    .user-list {
        max-height: 600px;
        overflow-y: auto;
    }

    .active-user {
        background-color: #e3f2fd !important;
        border-left: 4px solid #0d6efd;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(45deg, #007bff, #0056b3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 1rem;
    }

    .completion-badge {
        font-size: 0.75rem;
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

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- سایدبار لیست کاربران -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title">
                            <i class="bi bi-people-fill me-2"></i>
                            لیست کاربران
                        </h5>
                    </div>
                    <div class="card-body user-list">
                        <?php if (empty($users)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-person-x display-4 text-muted"></i>
                            <p class="text-muted mt-3">هیچ کاربری ثبت نشده است.</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($users as $user): 
                                // محاسبه پیشرفت کاربر
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM comparisons WHERE user_id = ?");
                                $stmt->execute([$user['id']]);
                                $comparisons_count = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                $stmt = $pdo->query("SELECT COUNT(*) as total FROM criteria");
                                $criteria_count = $stmt->fetch(PDO::FETCH_ASSOC);
                                $total_comparisons = ($criteria_count['total'] * ($criteria_count['total'] - 1)) / 2;
                                
                                $completion_percentage = $total_comparisons > 0 ? 
                                    round(($comparisons_count['count'] / $total_comparisons) * 100) : 0;
                            ?>
                            <a href="?user_id=<?= $user['id'] ?>"
                                class="list-group-item list-group-item-action <?= isset($_GET['user_id']) && $_GET['user_id'] == $user['id'] ? 'active-user' : '' ?>">
                                <div class="d-flex align-items-center">
                                    <div class="user-avatar me-3">
                                        <?= substr($user['fullname'], 0, 1) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?= htmlspecialchars($user['fullname']) ?></h6>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($user['position']) ?>
                                            <?php if (!empty($user['education'])): ?>
                                            - <?= htmlspecialchars($user['education']) ?>
                                            <?php endif; ?>
                                        </small>
                                        <div class="progress mt-1" style="height: 8px;">
                                            <div class="progress-bar <?= $completion_percentage == 100 ? 'bg-success' : 'bg-warning' ?>"
                                                role="progressbar" style="width: <?= $completion_percentage ?>%;"
                                                aria-valuenow="<?= $completion_percentage ?>" aria-valuemin="0"
                                                aria-valuemax="100">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?= $completion_percentage ?>% تکمیل
                                        </small>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- بخش اصلی نتایج -->
            <div class="col-md-9">
                <?php if ($selected_user): ?>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-badge me-2"></i>
                                نتایج کاربر: <?= htmlspecialchars($selected_user['fullname']) ?>
                            </h5>
                            <a href="admin_results.php" class="btn btn-light btn-sm">
                                <i class="bi bi-arrow-left me-1"></i>
                                بازگشت به لیست
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- دکمه حذف کاربر -->
                        <div class="mb-4">
                            <form method="post" class="d-inline"
                                onsubmit="return confirm('آیا از حذف این کاربر و تمام داده‌های مربوطه اطمینان دارید؟ این عمل غیرقابل بازگشت است.');">
                                <input type="hidden" name="user_id" value="<?= $selected_user['id'] ?>">
                                <button type="submit" name="delete_user" class="btn btn-danger">
                                    <i class="bi bi-trash me-1"></i>
                                    حذف کاربر و تمام داده‌ها
                                </button>
                            </form>
                        </div>

                        <!-- اطلاعات کاربر -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-info-circle me-2"></i>
                                            اطلاعات کاربر
                                        </h6>
                                        <p><strong>نام و نام خانوادگی:</strong>
                                            <?= htmlspecialchars($selected_user['fullname']) ?></p>
                                        <p><strong>شماره همراه:</strong>
                                            <?= htmlspecialchars($selected_user['phone']) ?></p>
                                        <p><strong>سمت یا جایگاه:</strong>
                                            <?= htmlspecialchars($selected_user['position']) ?></p>
                                        <?php if (!empty($selected_user['education'])): ?>
                                        <p><strong>تحصیلات:</strong>
                                            <?= htmlspecialchars($selected_user['education']) ?></p>
                                        <?php endif; ?>
                                        <p><strong>تاریخ ثبت:</strong>
                                            <?= date('Y/m/d H:i', strtotime($selected_user['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-speedometer2 me-2"></i>
                                            وضعیت تکمیل
                                        </h6>
                                        <p><strong>مقایسه‌های انجام شده:</strong>
                                            <?= $user_results_data['comparisons_done'] ?> از
                                            <?= $user_results_data['comparisons_needed'] ?></p>
                                        <div class="progress mt-2" style="height: 20px;">
                                            <div class="progress-bar <?= $user_results_data['is_complete'] ? 'bg-success' : 'bg-warning' ?>"
                                                role="progressbar"
                                                style="width: <?= $user_results_data['comparisons_needed'] > 0 ? round(($user_results_data['comparisons_done'] / $user_results_data['comparisons_needed']) * 100) : 0 ?>%;"
                                                aria-valuenow="<?= $user_results_data['comparisons_done'] ?>"
                                                aria-valuemin="0"
                                                aria-valuemax="<?= $user_results_data['comparisons_needed'] ?>">
                                                <?= $user_results_data['comparisons_needed'] > 0 ? round(($user_results_data['comparisons_done'] / $user_results_data['comparisons_needed']) * 100) : 0 ?>%
                                            </div>
                                        </div>
                                        <p class="mt-2">
                                            <span
                                                class="badge <?= $user_results_data['is_complete'] ? 'bg-success' : 'bg-warning' ?>">
                                                <?= $user_results_data['is_complete'] ? 'تکمیل شده' : 'در حال انجام' ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($user_results_data['is_complete']): ?>
                        <!-- وضعیت سازگاری -->
                        <div
                            class="alert <?= $consistency_data['is_consistent'] ? 'alert-success' : 'alert-danger' ?> mb-4">
                            <h6 class="alert-heading">
                                <i
                                    class="bi <?= $consistency_data['is_consistent'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
                                وضعیت سازگاری ماتریس
                            </h6>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <strong>لامبدا ماکزیمم (λmax):</strong><br>
                                    <span
                                        class="<?= $consistency_data['is_consistent'] ? 'consistency-good' : 'consistency-bad' ?>">
                                        <?= $consistency_data['lambda_max'] ?>
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <strong>شاخص سازگاری (CI):</strong><br>
                                    <span
                                        class="<?= $consistency_data['is_consistent'] ? 'consistency-good' : 'consistency-bad' ?>">
                                        <?= $consistency_data['ci'] ?>
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <strong>شاخص تصادفی (RI):</strong><br>
                                    <?= $consistency_data['ri'] ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>نسبت سازگاری (CR):</strong><br>
                                    <span
                                        class="<?= $consistency_data['is_consistent'] ? 'consistency-good' : 'consistency-bad' ?>">
                                        <?= $consistency_data['cr'] * 100 ?>%
                                    </span>
                                </div>
                            </div>
                            <hr>
                            <p class="mb-0">
                                <strong>وضعیت:</strong>
                                <?php if ($consistency_data['is_consistent']): ?>
                                <span class="consistency-good">ماتریس سازگار است (CR ≤ 0.1)</span>
                                <?php else: ?>
                                <span class="consistency-bad">ماتریس ناسازگار است! (CR > 0.1)</span>
                                <?php endif; ?>
                            </p>
                        </div>

                        <!-- ماتریس مقایسه‌ها -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-table me-2"></i>
                                    ماتریس مقایسه‌های زوجی
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if ($user_results_data['comparisons_done'] > 0): ?>
                                <div class="table-responsive">
                                    <table class="matrix-table table table-bordered">
                                        <thead>
                                            <tr>
                                                <th style="width: 150px">معیارها</th>
                                                <?php foreach ($user_results_data['criteria'] as $criterion): ?>
                                                <th class="text-center"><?= htmlspecialchars($criterion['name']) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($user_results_data['criteria'] as $i => $criterion1): ?>
                                            <tr>
                                                <th><?= htmlspecialchars($criterion1['name']) ?></th>
                                                <?php foreach ($user_results_data['criteria'] as $j => $criterion2): 
                            $cell = $user_results_data['display_matrix'][$i][$j];
                        ?>
                                                <td class="text-center <?= $cell['type'] == 'diagonal' ? 'diagonal' : '' ?> 
                                  <?= $cell['type'] == 'direct' ? 'bg-light' : '' ?>"
                                                    style="font-weight: <?= $cell['type'] == 'direct' ? 'bold' : 'normal' ?>;">
                                                    <?php if ($cell['type'] == 'diagonal'): ?>
                                                    <strong>1</strong>
                                                    <?php elseif ($cell['type'] == 'direct'): ?>
                                                    <span class="text-primary"><?= $cell['value'] ?></span>
                                                    <?php elseif ($cell['type'] == 'inverse'): ?>
                                                    <span class="text-success"><?= $cell['value'] ?></span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- راهنمای ماتریس -->
                                <div class="alert alert-light mt-3">
                                    <h6 class="mb-2"><i class="bi bi-info-circle"></i> راهنمای ماتریس:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <span class="badge bg-primary">مقادیر مستقیم کاربر</span>
                                                <span class="badge bg-success">مقادیر معکوس محاسبه شده</span>
                                                <span class="badge bg-secondary">قطر اصلی (1)</span>
                                                <span class="badge bg-light text-dark">مقایسه انجام نشده</span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">
                                                <strong>تعداد مقایسه‌های وارد شده:</strong>
                                                <?= $user_results_data['comparisons_done'] ?> از
                                                <?= $user_results_data['comparisons_needed'] ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> کاربر هنوز هیچ مقایسه‌ای انجام نداده است.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- وزن‌های نهایی -->
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="bi bi-graph-up me-2"></i>
                                    وزن‌های نهایی معیارها
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>رتبه</th>
                                                <th>معیار</th>
                                                <th>وزن</th>
                                                <th>درصد</th>
                                                <th>نمودار</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // مرتب کردن معیارها بر اساس وزن
                                            $sorted_weights = [];
                                            foreach ($user_results_data['criteria'] as $i => $criterion) {
                                                $sorted_weights[] = [
                                                    'criterion' => $criterion,
                                                    'weight' => $user_results_data['weights'][$i],
                                                    'index' => $i
                                                ];
                                            }
                                            
                                            usort($sorted_weights, function($a, $b) {
                                                return $b['weight'] <=> $a['weight'];
                                            });
                                            
                                            $max_weight = max($user_results_data['weights']);
                                            foreach ($sorted_weights as $rank => $item): 
                                                $criterion = $item['criterion'];
                                                $weight = $item['weight'];
                                                $percentage = round($weight * 100, 2);
                                                $bar_width = $max_weight > 0 ? ($weight / $max_weight * 100) : 0;
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $rank + 1 ?></span></td>
                                                <td><?= htmlspecialchars($criterion['name']) ?></td>
                                                <td><?= round($weight, 4) ?></td>
                                                <td><?= $percentage ?>%</td>
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
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-exclamation-triangle me-2"></i> ماتریس تکمیل نشده است</h6>
                            <p class="mb-0">این کاربر هنوز ماتریس را به طور کامل پر نکرده است.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- صفحه پیش‌فرض وقتی کاربری انتخاب نشده -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title">
                            <i class="bi bi-people-fill me-2"></i>
                            مدیریت نتایج کاربران
                        </h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <i class="bi bi-person-lines-fill" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-4 text-muted">برای مشاهده نتایج، یک کاربر از لیست سمت راست انتخاب کنید</h4>
                        <p class="text-muted">با انتخاب هر کاربر، می‌توانید نتایج کامل وزن‌دهی و وضعیت سازگاری ماتریس او
                            را مشاهده کنید</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // اسکرول به کاربر انتخاب شده
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_GET['user_id'])): ?>
        const activeUser = document.querySelector('.active-user');
        if (activeUser) {
            setTimeout(() => {
                activeUser.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }, 100);
        }
        <?php endif; ?>
    });
    </script>
</body>

</html>