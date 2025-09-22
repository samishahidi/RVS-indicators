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


function getUserComparisonMatrix($user_id, $main_matrix_id, $criteria) {
    global $pdo;
    
    // دریافت مقایسه‌های کاربر
    $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
    $stmt->execute([$user_id, $main_matrix_id]);
    $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ایجاد آرایه مقایسه‌ها
    $comparisons_array = [];
    foreach ($comparisons as $comp) {
        $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
        $comparisons_array[$key] = $comp['value'];
    }
    
    // ایجاد ماتریس نمایش
    $display_matrix = [];
    $n = count($criteria);
    
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
    
    // محاسبه اطلاعات تکمیل
    $comparisons_needed = ($n * ($n - 1)) / 2;
    $comparisons_done = count($comparisons);
    $completion_percentage = $comparisons_needed > 0 ? round(($comparisons_done / $comparisons_needed) * 100) : 0;
    $is_complete = $comparisons_done >= $comparisons_needed;
    
    return [
        'matrix' => $display_matrix,
        'comparisons_done' => $comparisons_done,
        'comparisons_needed' => $comparisons_needed,
        'completion_percentage' => $completion_percentage,
        'is_complete' => $is_complete,
        'comparisons_array' => $comparisons_array
    ];
}

// تابع گسترش یافته برای دریافت هم نتایج و هم ماتریس
function getUserFullResults($user_id, $main_matrix_id, $criteria) {
    global $pdo;
    
    // دریافت مقایسه‌ها
    $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
    $stmt->execute([$user_id, $main_matrix_id]);
    $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $comparisons_array = [];
    foreach ($comparisons as $comp) {
        $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
        $comparisons_array[$key] = $comp['value'];
    }
    
    // اطلاعات ماتریس
    $n = count($criteria);
    $comparisons_needed = ($n * ($n - 1)) / 2;
    $comparisons_done = count($comparisons);
    $completion_percentage = $comparisons_needed > 0 ? round(($comparisons_done / $comparisons_needed) * 100) : 0;
    $is_complete = $comparisons_done >= $comparisons_needed;
    
    // اگر ماتریس کامل نیست، فقط اطلاعات پایه را برگردان
    if (!$is_complete) {
        return [
            'weights' => null,
            'consistency' => null,
            'matrix_info' => [
                'comparisons_done' => $comparisons_done,
                'comparisons_needed' => $comparisons_needed,
                'completion_percentage' => $completion_percentage,
                'is_complete' => $is_complete
            ],
            'comparisons_array' => $comparisons_array
        ];
    }
    
    // اگر کامل است، وزن‌ها و سازگاری را محاسبه کن
    $results = calculateWeightsAndConsistency($criteria, $comparisons_array);
    $results['matrix_info'] = [
        'comparisons_done' => $comparisons_done,
        'comparisons_needed' => $comparisons_needed,
        'completion_percentage' => $completion_percentage,
        'is_complete' => $is_complete
    ];
    $results['comparisons_array'] = $comparisons_array;
    
    return $results;
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
    #downloadPdfBtn {
        background: linear-gradient(45deg, #28a745, #20c997);
        border: none;
        transition: all 0.3s ease;
    }

    #downloadPdfBtn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
    }

    #pdfContent table {
        font-family: Tahoma, Arial, sans-serif;
    }

    #pdfContent th,
    #pdfContent td {
        font-size: 12px;
    }

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

    .table-sm th,
    .table-sm td {
        padding: 4px 8px;
    }

    .diagonal {
        background-color: #e9ecef !important;
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

    .user-card .table {
        margin-bottom: 1rem;
    }

    .user-card .alert {
        margin-bottom: 1rem;
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
                                    <?php 
                    // ایجاد یک ماتریس کامل برای نمایش
                    $display_matrix = [];
                    
                    // مقداردهی اولیه ماتریس نمایش
                    foreach ($criteria as $i => $criterion1) {
                        foreach ($criteria as $j => $criterion2) {
                            if ($i == $j) {
                                $display_matrix[$i][$j] = ['value' => 1, 'type' => 'diagonal'];
                            } else {
                                $key1 = $criterion1['id'] . '_' . $criterion2['id'];
                                $key2 = $criterion2['id'] . '_' . $criterion1['id'];
                                
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
                    
                    // نمایش ماتریس
                    foreach ($criteria as $i => $criterion1): ?>
                                    <tr>
                                        <th><?= htmlspecialchars($criterion1['name']) ?></th>
                                        <?php foreach ($criteria as $j => $criterion2): 
                            $cell = $display_matrix[$i][$j];
                        ?>
                                        <td class="<?= $cell['type'] == 'diagonal' ? 'diagonal' : '' ?> 
                                  <?= $cell['type'] == 'direct' ? 'bg-light' : '' ?>">
                                            <?php if ($cell['type'] == 'diagonal'): ?>
                                            <strong>1</strong>
                                            <?php elseif ($cell['type'] == 'direct'): ?>
                                            <strong class="text-primary"><?= $cell['value'] ?></strong>
                                            <?php elseif ($cell['type'] == 'inverse'): ?>
                                            <span class="text-success"><?= $cell['value'] ?></span>
                                            <?php else: ?>
                                            <span class="text-muted"><?= $cell['value'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- راهنمای رنگ‌ها -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="alert alert-light">
                                    <h6><i class="bi bi-palette"></i> راهنمای رنگ‌ها:</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-primary">مقادیر مستقیم کاربر</span>
                                        <span class="badge bg-success">مقادیر معکوس محاسبه شده</span>
                                        <span class="badge bg-secondary">قطر اصلی (1)</span>
                                        <span class="badge bg-light text-dark">مقادیر خالی</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-calculator"></i> اطلاعات ماتریس:</h6>
                                    <ul class="mb-0 small">
                                        <li>تعداد مقایسه‌های انجام شده: <strong><?= $comparisons_done ?></strong></li>
                                        <li>تعداد مقایسه‌های مورد نیاز: <strong><?= $comparisons_needed ?></strong></li>
                                        <li>درصد تکمیل: <strong><?= $completion_percentage ?>%</strong></li>
                                    </ul>
                                </div>
                            </div>
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

                    <!-- دکمه جدید برای دانلود PDF -->
                    <button type="button" class="btn btn-success btn-lg me-3" id="downloadPdfBtn">
                        <i class="bi bi-file-earmark-pdf-fill me-2"></i>
                        دانلود PDF
                    </button>



                    <a href="index.php"
                        class="btn <?= $consistency_data['is_consistent'] ? 'btn-outline-secondary' : 'btn-danger' ?>">
                        <i class="bi bi-house-fill me-2"></i>
                        <?= $consistency_data['is_consistent'] ? 'بازگشت و اصلاح' : 'ماتریس ناسازگار است! جهت اصلاح کلیک کنید' ?>
                    </a>


                </div>
            </div>
        </div>


        <!-- بخش نتایج سایر کاربران -->
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
                        // دریافت نتایج کامل کاربر
                        $user_full_results = getUserFullResults($other_user['id'], $main_matrix['id'], $criteria);
                        $matrix_data = getUserComparisonMatrix($other_user['id'], $main_matrix['id'], $criteria);
                        ?>

                                <?php if ($matrix_data['comparisons_done'] > 0): ?>

                                <!-- وضعیت پیشرفت -->
                                <div
                                    class="alert <?= $matrix_data['is_complete'] ? 'alert-success' : 'alert-warning' ?> mb-3">
                                    <h6>وضعیت پیشرفت:</h6>
                                    <div class="row align-items-center">
                                        <div class="col-md-6">
                                            <strong>پیشرفت:</strong> <?= $matrix_data['completion_percentage'] ?>%
                                            <div class="progress mt-1" style="height: 10px;">
                                                <div class="progress-bar <?= $matrix_data['is_complete'] ? 'bg-success' : 'bg-warning' ?>"
                                                    role="progressbar"
                                                    style="width: <?= $matrix_data['completion_percentage'] ?>%;"
                                                    aria-valuenow="<?= $matrix_data['completion_percentage'] ?>"
                                                    aria-valuemin="0" aria-valuemax="100">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <small>تکمیل شده: <?= $matrix_data['comparisons_done'] ?> از
                                                <?= $matrix_data['comparisons_needed'] ?> مقایسه</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- وضعیت سازگاری (فقط اگر ماتریس کامل باشد) -->
                                <?php if ($matrix_data['is_complete'] && $user_full_results['consistency']): ?>
                                <div
                                    class="alert <?= $user_full_results['consistency']['is_consistent'] ? 'alert-success' : 'alert-danger' ?> mb-3">
                                    <h6>وضعیت سازگاری:</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>CR:</strong>
                                            <span
                                                class="<?= $user_full_results['consistency']['is_consistent'] ? 'consistency-good' : 'consistency-bad' ?>">
                                                <?= $user_full_results['consistency']['cr'] * 100 ?>%
                                            </span>
                                        </div>
                                        <div class="col-md-9">
                                            <strong>وضعیت:</strong>
                                            <?php if ($user_full_results['consistency']['is_consistent']): ?>
                                            <span class="consistency-good">سازگار</span>
                                            <?php else: ?>
                                            <span class="consistency-bad">ناسازگار</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- ماتریس مقایسه‌های زوجی -->
                                <h6>ماتریس مقایسه‌های زوجی:</h6>
                                <div class="table-responsive mb-3">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th style="width: 120px">معیارها</th>
                                                <?php foreach ($criteria as $criterion): ?>
                                                <th class="text-center"><?= htmlspecialchars($criterion['name']) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($criteria as $i => $criterion1): ?>
                                            <tr>
                                                <th><?= htmlspecialchars($criterion1['name']) ?></th>
                                                <?php foreach ($criteria as $j => $criterion2): 
                                            $cell = $matrix_data['matrix'][$i][$j];
                                        ?>
                                                <td class="text-center <?= $cell['type'] == 'diagonal' ? 'diagonal' : '' ?> 
                                                  <?= $cell['type'] == 'direct' ? 'bg-light' : '' ?>"
                                                    style="font-size: 0.85rem;">
                                                    <?php if ($cell['type'] == 'diagonal'): ?>
                                                    <strong>1</strong>
                                                    <?php elseif ($cell['type'] == 'direct'): ?>
                                                    <strong class="text-primary"><?= $cell['value'] ?></strong>
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
                                <div class="alert alert-light mb-3">
                                    <h6 class="mb-2"><i class="bi bi-info-circle"></i> راهنمای ماتریس:</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-primary">مقادیر مستقیم کاربر</span>
                                        <span class="badge bg-success">مقادیر معکوس محاسبه شده</span>
                                        <span class="badge bg-secondary">قطر اصلی</span>
                                        <span class="badge bg-light text-dark">مقایسه انجام نشده</span>
                                    </div>
                                </div>

                                <!-- وزن‌های نهایی (فقط اگر ماتریس کامل باشد) -->
                                <!-- <?php if ($matrix_data['is_complete'] && $user_full_results['weights']): ?>
                                <h6>وزن‌های نهایی:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>رتبه</th>
                                                <th>معیار</th>
                                                <th>وزن</th>
                                                <th>درصد</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                    // مرتب کردن وزن‌ها
                                    $sorted_weights = [];
                                    foreach ($criteria as $i => $criterion) {
                                        $sorted_weights[] = [
                                            'criterion' => $criterion,
                                            'weight' => $user_full_results['weights'][$i]
                                        ];
                                    }
                                    usort($sorted_weights, function($a, $b) {
                                        return $b['weight'] <=> $a['weight'];
                                    });
                                    
                                    foreach ($sorted_weights as $rank => $item): 
                                        $percentage = round($item['weight'] * 100, 2);
                                    ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $rank + 1 ?></span></td>
                                                <td><?= htmlspecialchars($item['criterion']['name']) ?></td>
                                                <td><?= round($item['weight'], 4) ?></td>
                                                <td><?= $percentage ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php elseif (!$matrix_data['is_complete']): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    برای مشاهده وزن‌های نهایی، کاربر باید ماتریس را کامل تکمیل کند.
                                </div>
                                <?php endif; ?> -->

                                <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    این کاربر هنوز هیچ مقایسه‌ای انجام نداده است.
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




    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <script>
    document.getElementById('downloadPdfBtn').addEventListener('click', async function() {
        const originalText = this.innerHTML;
        this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>در حال تولید PDF...';
        this.disabled = true;

        try {
            // ایجاد محتوای PDF با استفاده از innerHTML مستقیم
            const pdfContainer = document.createElement('div');
            pdfContainer.style.width = '210mm';
            pdfContainer.style.minHeight = '297mm';
            pdfContainer.style.padding = '0mm';
            pdfContainer.style.paddingTop = '0mm';

            pdfContainer.style.margin = '0';
            pdfContainer.style.fontFamily = 'Tahoma, Arial, sans-serif';
            pdfContainer.style.direction = 'rtl';
            pdfContainer.style.background = 'white';
            pdfContainer.style.boxSizing = 'border-box';

            // استفاده مستقیم از PHP variables در JavaScript
            pdfContainer.innerHTML = `
            <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px;">
                <h1 style="color: #007bff; margin: 0; font-size: 24px;">نتایج وزن‌دهی AHP</h1>
                <p style="color: #666; margin: 5px 0; font-size: 14px;">تاریخ تولید: ${new Date().toLocaleDateString('fa-IR')}</p>
            </div>

            <!-- اطلاعات کاربر -->
            <div style="margin-bottom: 30px; background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #ddd;">
                <h2 style="color: #333; border-right: 4px solid #007bff; padding-right: 10px; font-size: 18px; margin-top: 0;">
                    اطلاعات کاربر
                </h2>
                <table style="width: 100%; margin-top: 15px; font-size: 14px;">
                    <tr>
                        <td style="width: 30%; padding: 8px; font-weight: bold;">نام و نام خانوادگی:</td>
                        <td style="padding: 8px;"><?= htmlspecialchars($user['fullname']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">شماره همراه:</td>
                        <td style="padding: 8px;"><?= htmlspecialchars($user['phone']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">سمت یا جایگاه:</td>
                        <td style="padding: 8px;"><?= htmlspecialchars($user['position']) ?></td>
                    </tr>
                    <?php if (!empty($user['education'])): ?>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">تحصیلات:</td>
                        <td style="padding: 8px;"><?= htmlspecialchars($user['education']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($is_complete): ?>
            <!-- وضعیت سازگاری -->
            <!--div style="margin-bottom: 30px; padding: 15px; border: 2px solid <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; border-radius: 5px;">
                <h2 style="color: #333; border-right: 4px solid <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; padding-right: 10px; font-size: 18px; margin-top: 0;">
                    وضعیت سازگاری ماتریس
                </h2>
                <table style="width: 100%; margin-top: 15px; font-size: 14px;">
                    <tr>
                        <td style="width: 25%; padding: 8px; font-weight: bold;">لامبدا ماکزیمم (λmax):</td>
                        <td style="padding: 8px; color: <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; font-weight: bold;"><?= $consistency_data['lambda_max'] ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">شاخص سازگاری (CI):</td>
                        <td style="padding: 8px; color: <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; font-weight: bold;"><?= $consistency_data['ci'] ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">شاخص تصادفی (RI):</td>
                        <td style="padding: 8px;"><?= $consistency_data['ri'] ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">نسبت سازگاری (CR):</td>
                        <td style="padding: 8px; color: <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; font-weight: bold;"><?= round($consistency_data['cr'] * 100, 2) ?>%</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;">وضعیت:</td>
                        <td style="padding: 8px; color: <?= $consistency_data['is_consistent'] ? '#28a745' : '#dc3545' ?>; font-weight: bold;">
                            <?= $consistency_data['is_consistent'] ? '✓ ماتریس سازگار است (CR ≤ 0.1)' : '✗ ماتریس ناسازگار است! (CR > 0.1)' ?>
                        </td>
                    </tr>
                </table>
            </div-->
            <?php endif; ?>

            <!-- ماتریس مقایسه‌ها -->
            <div style="margin-bottom: 30px;">
                <h2 style="color: #333; border-right: 4px solid #007bff; padding-right: 10px; font-size: 18px; margin-top: 0;">
                    ماتریس مقایسه‌های زوجی
                </h2>
                <?php if ($comparisons_done > 0): ?>
                <div style="overflow-x: auto; margin-top: 15px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #ddd;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #ddd; padding: 10px; background-color: #f8f9fa; width: 150px; font-weight: bold;">معیارها</th>
                                <?php foreach ($criteria as $criterion): ?>
                                <th style="border: 1px solid #ddd; padding: 10px; background-color: #f8f9fa; font-weight: bold;"><?= htmlspecialchars($criterion['name']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($criteria as $i => $criterion1): ?>
                            <tr>
                                <th style="border: 1px solid #ddd; padding: 10px; background-color: #f8f9fa; font-weight: bold;"><?= htmlspecialchars($criterion1['name']) ?></th>
                                <?php foreach ($criteria as $j => $criterion2): ?>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: center; <?= $i == $j ? 'background-color: #e9ecef; font-weight: bold;' : '' ?>">
                                    <?php if ($i == $j): ?>
                                    <strong>1</strong>
                                    <?php else: ?>
                                    <?php
                                    $key1 = $criterion1['id'] . '_' . $criterion2['id'];
                                    $key2 = $criterion2['id'] . '_' . $criterion1['id'];
                                    
                                    if (isset($comparisons_array[$key1])) {
                                        echo '<strong style="color: #007bff;">' . $comparisons_array[$key1] . '</strong>';
                                    } elseif (isset($comparisons_array[$key2])) {
                                        $value = 1 / $comparisons_array[$key2];
                                        echo '<span style="color: #28a745;">' . round($value, 2) . '</span>';
                                    } else {
                                        echo '<span style="color: #6c757d;">-</span>';
                                    }
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
                <p style="color: #666; margin-top: 15px; font-size: 14px;">هنوز هیچ داده‌ای برای ماتریس وارد نشده است.</p>
                <?php endif; ?>
            </div>

            <?php if ($is_complete && isset($weights)): ?>
            <!-- وزن‌های نهایی -->
            <!--div style="margin-bottom: 30px;">
                <h2 style="color: #333; border-right: 4px solid #28a745; padding-right: 10px; font-size: 18px; margin-top: 0;">
                    وزن‌های نهایی معیارها
                </h2>
                <div style="overflow-x: auto; margin-top: 15px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px; border: 1px solid #ddd;">
                        <thead>
                            <tr style="background-color: #f8f9fa;">
                                <th style="border: 1px solid #ddd; padding: 10px; font-weight: bold;">رتبه</th>
                                <th style="border: 1px solid #ddd; padding: 10px; font-weight: bold;">معیار</th>
                                <th style="border: 1px solid #ddd; padding: 10px; font-weight: bold;">وزن</th>
                                <th style="border: 1px solid #ddd; padding: 10px; font-weight: bold;">درصد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
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
                            
                            foreach ($sorted_weights as $rank => $item): 
                                $criterion = $item['criterion'];
                                $weight = $item['weight'];
                                $percentage = round($weight * 100, 2);
                            ?>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: center; font-weight: bold;"><?= $rank + 1 ?></td>
                                <td style="border: 1px solid #ddd; padding: 10px;"><?= htmlspecialchars($criterion['name']) ?></td>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: center;"><?= round($weight, 4) ?></td>
                                <td style="border: 1px solid #ddd; padding: 10px; text-align: center; font-weight: bold;"><?= $percentage ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div-->
            <?php endif; ?>

            <!-- پاورقی -->
            <div style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
                <p>این گزارش به صورت خودکار توسط سیستم AHP تولید شده است.</p>
            </div>
        `;

            // اضافه کردن به صفحه با موقعیت قابل مشاهده
            pdfContainer.style.position = 'fixed';
            pdfContainer.style.left = '50%';
            pdfContainer.style.top = '-60%';
            pdfContainer.style.transform = 'translate(-50%, -50%)';
            pdfContainer.style.zIndex = '10000';
            pdfContainer.style.boxShadow = '0 0 20px rgba(0,0,0,0.3)';
            document.body.appendChild(pdfContainer);

            // منتظر ماندن برای رندر شدن محتوا
            await new Promise(resolve => setTimeout(resolve, 500));

            const options = {
                margin: [10, 10, 10, 10],
                filename: 'نتایج_AHP_<?= htmlspecialchars($user['fullname']) ?>_<?= date("Y-m-d") ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    width: pdfContainer.scrollWidth,
                    height: pdfContainer.scrollHeight
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait',
                    compress: true
                }
            };

            // تولید PDF
            await html2pdf().set(options).from(pdfContainer).save();

            // حذف محتوای موقت
            document.body.removeChild(pdfContainer);

            this.innerHTML = originalText;
            this.disabled = false;

            // نمایش پیام موفقیت
            setTimeout(() => {
                alert('PDF با موفقیت دانلود شد!');
            }, 100);

        } catch (error) {
            console.error('PDF Generation Error:', error);

            // حذف محتوای موقت در صورت خطا
            const tempContent = document.querySelector('div[style*="z-index: 10000"]');
            if (tempContent) {
                document.body.removeChild(tempContent);
            }

            this.innerHTML = originalText;
            this.disabled = false;
            alert('خطا در تولید PDF. لطفاً کنسول مرورگر را بررسی کنید.');
        }
    });
    </script>





</body>

</html>