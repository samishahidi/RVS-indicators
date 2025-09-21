<?php
session_start();
require_once 'db_config.php';

// بررسی وجود کاربر
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// دریافت اطلاعات کاربر
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

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
$completion_percentage = $comparisons_needed > 0 ? round(($comparisons_done / $comparisons_needed) * 100) : 0;
$is_complete = $comparisons_done >= $comparisons_needed;

// ایجاد ماتریس مقایسه‌های زوجی
$pairwise_matrix = [];
$consistency_data = [
    'lambda_max' => 0,
    'ci' => 0,
    'ri' => 0,
    'cr' => 0,
    'is_consistent' => false
];

if ($is_complete && $n > 0) {
    // مقداردهی اولیه ماتریس
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i == $j) {
                $pairwise_matrix[$i][$j] = 1;
            } else {
                $pairwise_matrix[$i][$j] = 0;
            }
        }
    }

    // پر کردن ماتریس با مقادیر وارد شده توسط کاربر
    foreach ($comparisons_array as $key => $value) {
        list($criterion1_id, $criterion2_id) = explode('_', $key);
        
        // یافتن اندیس معیارها
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

    // محاسبه مجموع هر ستون
    $column_sums = array_fill(0, $n, 0);
    for ($j = 0; $j < $n; $j++) {
        for ($i = 0; $i < $n; $i++) {
            $column_sums[$j] += $pairwise_matrix[$i][$j];
        }
    }

    // نرمال‌سازی ماتریس
    $normalized_matrix = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $normalized_matrix[$i][$j] = $pairwise_matrix[$i][$j] / $column_sums[$j];
        }
    }

    // محاسبه میانگین هر سطر (وزن نهایی)
    $weights = [];
    for ($i = 0; $i < $n; $i++) {
        $row_sum = 0;
        for ($j = 0; $j < $n; $j++) {
            $row_sum += $normalized_matrix[$i][$j];
        }
        $weights[$i] = $row_sum / $n;
    }

    // محاسبه لامبدا ماکزیمم و ناسازگاری
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
    
    // مقادیر RI بر اساس اندازه ماتریس
    $ri_values = [
        1 => 0.00, 2 => 0.00, 3 => 0.58, 4 => 0.90, 5 => 1.12,
        6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49,
        11 => 1.51, 12 => 1.48, 13 => 1.56, 14 => 1.57, 15 => 1.59
    ];
    
    $ri = $ri_values[$n] ?? 1.60;
    $cr = $ci / $ri;
    
    $consistency_data = [
        'lambda_max' => round($lambda_max, 4),
        'ci' => round($ci, 4),
        'ri' => round($ri, 4),
        'cr' => round($cr, 4),
        'is_consistent' => $cr <= 0.1
    ];
}



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



// دریافت لیست تمام کاربران
$stmt = $pdo->query("SELECT * FROM users WHERE completed = TRUE ORDER BY created_at DESC");
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تابع دریافت نتایج هر کاربر
function getUserResults($user_id, $main_matrix_id, $criteria) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
    $stmt->execute([$user_id, $main_matrix_id]);
    $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $comparisons_array = [];
    foreach ($comparisons as $comp) {
        $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
        $comparisons_array[$key] = $comp['value'];
    }
    
    return calculateWeightsAndConsistency($criteria, $comparisons_array);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتایج وزن‌دهی ماتریس</title>
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

    .card-header {
        background: linear-gradient(45deg, #007bff, #0056b3);
    }

    .user-card {
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 10px;
    }

    .user-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    .collapse-content {
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
    }

    .user-avatar {
        width: 50px;
        height: 50px;
        background: linear-gradient(45deg, #007bff, #0056b3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 1.2rem;
    }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header text-white">
                <h4 class="card-title mb-0">
                    <i class="bi bi-bar-chart-fill me-2"></i>
                    نتایج وزن‌دهی ماتریس معیارها
                </h4>
            </div>
            <div class="card-body">
                <!-- اطلاعات کاربر جاری -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="bi bi-person-circle me-2"></i>
                                    اطلاعات کاربر
                                </h5>
                                <p><strong>نام و نام خانوادگی:</strong> <?= htmlspecialchars($user['fullname']) ?></p>
                                <p><strong>شماره همراه:</strong> <?= htmlspecialchars($user['phone']) ?></p>
                                <p><strong>سمت یا جایگاه:</strong> <?= htmlspecialchars($user['position']) ?></p>
                                <?php if (!empty($user['education'])): ?>
                                <p><strong>تحصیلات:</strong> <?= htmlspecialchars($user['education']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="bi bi-speedometer2 me-2"></i>
                                    وضعیت پیشرفت
                                </h5>
                                <p><strong>پیشرفت کلی:</strong> <?= $completion_percentage ?>%</p>
                                <div class="progress mt-2" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar"
                                        style="width: <?= $completion_percentage ?>%;"
                                        aria-valuenow="<?= $completion_percentage ?>" aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?= $completion_percentage ?>%
                                    </div>
                                </div>
                                <p class="mt-2"><small>تکمیل شده: <?= $comparisons_done ?> از
                                        <?= $comparisons_needed ?> مقایسه</small></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- وضعیت سازگاری -->
                <?php if ($is_complete): ?>
                <div
                    class="alert <?= $consistency_data['is_consistent'] ? 'alert-success complete-alert' : 'alert-danger' ?>">
                    <h5 class="alert-heading">
                        <i
                            class="bi <?= $consistency_data['is_consistent'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
                        وضعیت سازگاری ماتریس
                    </h5>
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
                <?php endif; ?>

                <!-- ماتریس مقایسه‌ها -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-table me-2"></i>
                            ماتریس مقایسه‌های زوجی
                            <span class="badge <?= $is_complete ? 'bg-success' : 'bg-warning' ?> ms-2">
                                <?= $is_complete ? 'تکمیل شده' : $completion_percentage . '% تکمیل' ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($comparisons_done > 0): ?>
                        <div class="table-responsive">
                            <table class="matrix-table table table-bordered">
                                <thead>
                                    <tr>
                                        <th style="width: 150px">معیارها</th>
                                        <?php foreach ($criteria as $criterion): ?>
                                        <th><?= htmlspecialchars($criterion['name']) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($criteria as $i => $criterion1): ?>
                                    <tr>
                                        <th><?= htmlspecialchars($criterion1['name']) ?></th>
                                        <?php foreach ($criteria as $j => $criterion2): ?>
                                        <td class="<?= $i == $j ? 'diagonal' : '' ?>">
                                            <?php if ($i == $j): ?>
                                            1
                                            <?php elseif ($i < $j): ?>
                                            <?php
                                            $key = $criterion1['id'] . '_' . $criterion2['id'];
                                            $value = $comparisons_array[$key] ?? '';
                                            echo $value ?: '-';
                                            ?>
                                            <?php else: ?>
                                            <?php
                                            $key = $criterion2['id'] . '_' . $criterion1['id'];
                                            $value = isset($comparisons_array[$key]) ? (1 / $comparisons_array[$key]) : '';
                                            echo $value ? round($value, 2) : '-';
                                            ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> هنوز هیچ داده‌ای برای ماتریس وارد نشده است.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- وزن‌های نهایی -->
                <?php if ($is_complete && isset($weights)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up me-2"></i>
                            وزن‌های نهایی معیارها
                        </h5>
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
                                    foreach ($criteria as $i => $criterion) {
                                        $sorted_weights[] = [
                                            'criterion' => $criterion,
                                            'weight' => $weights[$i],
                                            'index' => $i
                                        ];
                                    }
                                    
                                    usort($sorted_weights, function($a, $b) {
                                        return $b['weight'] <=> $a['weight'];
                                    });
                                    
                                    $max_weight = max($weights);
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
                <?php elseif (!$is_complete && $comparisons_done > 0): ?>
                <div class="alert alert-warning mt-4">
                    <h5><i class="bi bi-exclamation-triangle"></i> ماتریس به طور کامل پر نشده است</h5>
                    <p>برای محاسبه وزن‌های نهایی، لطفاً <a href="form.php" class="alert-link">فرم مربوطه</a> را تکمیل
                        کنید.</p>
                </div>
                <?php endif; ?>

                <!-- دکمه‌های ناوبری -->
                <div class="mt-4 text-center">
                    <?php if (!$is_complete): ?>
                    <a href="form.php?row=1" class="btn btn-primary btn-lg me-3">
                        <i class="bi bi-pencil-fill me-2"></i>
                        ادامه تکمیل ماتریس
                    </a>
                    <?php endif; ?>

                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-house-fill me-2"></i>
                        بازگشت به صفحه اصلی
                    </a>
                </div>
            </div>
        </div>


        <!-- بخش نتایج سایر کاربران -->
        <div class="card shadow mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-people-fill me-2"></i>
                    نتایج سایر کاربران
                </h5>
            </div>
            <div class="card-body">
                <div class="accordion" id="usersAccordion">
                    <?php foreach ($all_users as $index => $other_user): ?>
                    <?php if ($other_user['id'] != $user_id): ?>
                    <div class="card user-card">
                        <div class="card-header" id="heading<?= $index ?>">
                            <h6 class="mb-0">
                                <button class="btn btn-link w-100 text-start text-decoration-none" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#collapse<?= $index ?>"
                                    aria-expanded="false" aria-controls="collapse<?= $index ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-3">
                                            <?= substr($other_user['fullname'], 0, 1) ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <strong
                                                class="text-white"><?= htmlspecialchars($other_user['fullname']) ?></strong>
                                            <br>
                                            <small class="text-white">
                                                <?= htmlspecialchars($other_user['position']) ?>
                                                <?php if (!empty($other_user['education'])): ?>
                                                - <?= htmlspecialchars($other_user['education']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="ms-auto">
                                            <i class="bi bi-chevron-down"></i>
                                        </div>
                                    </div>
                                </button>
                            </h6>
                        </div>

                        <div id="collapse<?= $index ?>" class="collapse" aria-labelledby="heading<?= $index ?>"
                            data-bs-parent="#usersAccordion">
                            <div class="card-body collapse-content">
                                <?php
                                    // دریافت نتایج کاربر
                                    $user_results = getUserResults($other_user['id'], $main_matrix['id'], $criteria);
                                    ?>

                                <?php if (!empty($user_results)): ?>
                                <!-- وضعیت سازگاری -->
                                <div
                                    class="alert <?= $user_results['consistency']['is_consistent'] ? 'alert-success' : 'alert-danger' ?> mb-3">
                                    <h6>وضعیت سازگاری:</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>CR:</strong>
                                            <span
                                                class="<?= $user_results['consistency']['is_consistent'] ? 'consistency-good' : 'consistency-bad' ?>">
                                                <?= $user_results['consistency']['cr'] * 100 ?>%
                                            </span>
                                        </div>
                                        <div class="col-md-9">
                                            <strong>وضعیت:</strong>
                                            <?php if ($user_results['consistency']['is_consistent']): ?>
                                            <span class="consistency-good">سازگار</span>
                                            <?php else: ?>
                                            <span class="consistency-bad">ناسازگار</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- وزن‌های نهایی -->
                                <h6>وزن‌های نهایی:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>معیار</th>
                                                <th>وزن</th>
                                                <th>رتبه</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                                    // مرتب کردن وزن‌ها
                                                    $sorted_weights = [];
                                                    foreach ($criteria as $i => $criterion) {
                                                        $sorted_weights[] = [
                                                            'criterion' => $criterion,
                                                            'weight' => $user_results['weights'][$i]
                                                        ];
                                                    }
                                                    usort($sorted_weights, function($a, $b) {
                                                        return $b['weight'] <=> $a['weight'];
                                                    });
                                                    
                                                    foreach ($sorted_weights as $rank => $item): 
                                                    ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['criterion']['name']) ?></td>
                                                <td><?= round($item['weight'], 4) ?></td>
                                                <td><span class="badge bg-primary"><?= $rank + 1 ?></span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    این کاربر هنوز ماتریس را تکمیل نکرده است.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if (count($all_users) <= 1): ?>
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle"></i>
                    هنوز کاربر دیگری نتایج خود را ثبت نکرده است.
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php include('footer.php'); ?>




    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // مدیریت accordion
    document.addEventListener('DOMContentLoaded', function() {
        const accordionItems = document.querySelectorAll('.user-card');

        accordionItems.forEach(item => {
            item.addEventListener('click', function() {
                const collapseElement = this.querySelector('.collapse');
                const bsCollapse = new bootstrap.Collapse(collapseElement);
                bsCollapse.toggle();
            });
        });
    });
    </script>
</body>

</html>