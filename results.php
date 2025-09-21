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

// دریافت ماتریس‌ها
$matrices = [];
$main_matrix_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE");
$main_matrix = $main_matrix_stmt->fetch(PDO::FETCH_ASSOC);

if ($main_matrix) {
    $matrices[] = $main_matrix;
}

$sub_matrices_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = FALSE");
$sub_matrices = $sub_matrices_stmt->fetchAll(PDO::FETCH_ASSOC);
$matrices = array_merge($matrices, $sub_matrices);

// دریافت معیارهای هر ماتریس
$criteria_by_matrix = [];
foreach ($matrices as $matrix) {
    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ?");
    $stmt->execute([$matrix['id']]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $criteria_by_matrix[$matrix['id']] = $criteria;
}

// دریافت مقایسه‌های کاربر و وضعیت تکمیل هر ماتریس
$comparisons_by_matrix = [];
$completion_status = [];
$total_comparisons_needed = 0;
$total_comparisons_done = 0;

foreach ($matrices as $matrix) {
    $current_criteria = $criteria_by_matrix[$matrix['id']];
    $num_criteria = count($current_criteria);
    
    // تعداد مقایسه‌های لازم برای این ماتریس (n*(n-1)/2)
    $comparisons_needed = ($num_criteria * ($num_criteria - 1)) / 2;
    $total_comparisons_needed += $comparisons_needed;
    
    $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
    $stmt->execute([$user_id, $matrix['id']]);
    $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $comparisons_done = count($comparisons);
    $total_comparisons_done += $comparisons_done;
    
    $completion_percentage = $comparisons_needed > 0 ? round(($comparisons_done / $comparisons_needed) * 100) : 0;
    
    $completion_status[$matrix['id']] = [
        'matrix_name' => $matrix['name'],
        'comparisons_needed' => $comparisons_needed,
        'comparisons_done' => $comparisons_done,
        'completion_percentage' => $completion_percentage,
        'is_complete' => $comparisons_done >= $comparisons_needed
    ];
    
    $comparisons_by_matrix[$matrix['id']] = [];
    foreach ($comparisons as $comp) {
        $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
        $comparisons_by_matrix[$matrix['id']][$key] = $comp['value'];
    }
}

// محاسبه وزن‌های نهایی برای ماتریس‌های تکمیل شده
$weights_by_matrix = [];
foreach ($matrices as $matrix) {
    $current_criteria = $criteria_by_matrix[$matrix['id']];
    $current_comparisons = $comparisons_by_matrix[$matrix['id']] ?? [];
    
    // فقط اگر ماتریس تکمیل شده باشد، وزن‌ها را محاسبه کن
    if ($completion_status[$matrix['id']]['is_complete']) {
        // ایجاد ماتریس مقایسه‌های زوجی
        $pairwise_matrix = [];
        $n = count($current_criteria);
        
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
        foreach ($current_comparisons as $key => $value) {
            list($criterion1_id, $criterion2_id) = explode('_', $key);
            
            // یافتن اندیس معیارها
            $index1 = -1;
            $index2 = -1;
            
            foreach ($current_criteria as $idx => $criterion) {
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
        
        $weights_by_matrix[$matrix['id']] = $weights;
    } else {
        $weights_by_matrix[$matrix['id']] = null;
    }
}

// محاسبه درصد پیشرفت کلی کاربر
$overall_completion = $total_comparisons_needed > 0 ? 
    round(($total_comparisons_done / $total_comparisons_needed) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتایج وزن‌دهی</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    .matrix-table {
        border-collapse: collapse;
        width: 100%;
        margin-bottom: 30px;
    }

    .matrix-table th,
    .matrix-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }

    .matrix-table th {
        background-color: #f8f9fa;
    }

    .diagonal {
        background-color: #e9ecef;
    }

    .matrix-container {
        margin-bottom: 40px;
    }

    .matrix-title {
        background-color: #0d6efd;
        color: white;
        padding: 10px;
        border-radius: 5px;
    }

    .weight-bar {
        height: 20px;
        background-color: #0d6efd;
        border-radius: 5px;
    }

    .weights-table {
        margin-top: 20px;
    }

    .completion-badge {
        font-size: 0.9rem;
    }

    .incomplete-matrix {
        opacity: 0.7;
    }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="card-title">نتایج وزن‌دهی معیارها</h4>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>اطلاعات کاربر:</h5>
                        <p><strong>نام و نام خانوادگی:</strong> <?= htmlspecialchars($user['fullname']) ?></p>
                        <p><strong>شماره همراه:</strong> <?= htmlspecialchars($user['phone']) ?></p>
                        <p><strong>سمت یا جایگاه:</strong> <?= htmlspecialchars($user['position']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>وضعیت پیشرفت:</h5>
                        <p><strong>پیشرفت کلی:</strong> <?= $overall_completion ?>%</p>
                        <div class="progress mt-2" style="height: 20px;">
                            <div class="progress-bar" role="progressbar" style="width: <?= $overall_completion ?>%;"
                                aria-valuenow="<?= $overall_completion ?>" aria-valuemin="0" aria-valuemax="100">
                                <?= $overall_completion ?>%
                            </div>
                        </div>
                        <p class="mt-2"><small>تکمیل شده: <?= $total_comparisons_done ?> از
                                <?= $total_comparisons_needed ?> مقایسه</small></p>
                    </div>
                </div>

                <h5 class="mb-3">وضعیت ماتریس‌ها:</h5>

                <?php foreach ($matrices as $matrix): ?>
                <?php 
                $current_criteria = $criteria_by_matrix[$matrix['id']];
                $current_comparisons = $comparisons_by_matrix[$matrix['id']] ?? [];
                $current_weights = $weights_by_matrix[$matrix['id']] ?? null;
                $completion_info = $completion_status[$matrix['id']];
                ?>
                <div class="matrix-container <?= !$completion_info['is_complete'] ? 'incomplete-matrix' : '' ?>">
                    <div class="matrix-title mb-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h5><?= htmlspecialchars($matrix['name']) ?></h5>
                            <?php if (!empty($matrix['description'])): ?>
                            <p class="mb-0"><?= htmlspecialchars($matrix['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span
                            class="badge <?= $completion_info['is_complete'] ? 'bg-success' : 'bg-warning' ?> completion-badge">
                            <?php if ($completion_info['is_complete']): ?>
                            تکمیل شده (100%)
                            <?php else: ?>
                            <?= $completion_info['completion_percentage'] ?>% تکمیل
                            (<?= $completion_info['comparisons_done'] ?> از
                            <?= $completion_info['comparisons_needed'] ?>)
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($completion_info['comparisons_done'] > 0): ?>
                    <div class="table-responsive">
                        <table class="matrix-table table table-bordered">
                            <thead>
                                <tr>
                                    <th></th>
                                    <?php foreach ($current_criteria as $criterion): ?>
                                    <th><?= htmlspecialchars($criterion['name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_criteria as $i => $criterion1): ?>
                                <tr>
                                    <th><?= htmlspecialchars($criterion1['name']) ?></th>
                                    <?php foreach ($current_criteria as $j => $criterion2): ?>
                                    <td class="<?= $i == $j ? 'diagonal' : '' ?>">
                                        <?php if ($i == $j): ?>
                                        1
                                        <?php elseif ($i < $j): ?>
                                        <?php
                                        $key = $criterion1['id'] . '_' . $criterion2['id'];
                                        $value = $current_comparisons[$key] ?? '';
                                        echo $value ?: '-';
                                        ?>
                                        <?php else: ?>
                                        <?php
                                        $key = $criterion2['id'] . '_' . $criterion1['id'];
                                        $value = isset($current_comparisons[$key]) ? (1 / $current_comparisons[$key]) : '';
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
                        <i class="bi bi-info-circle"></i> هنوز هیچ داده‌ای برای این ماتریس وارد نشده است.
                    </div>
                    <?php endif; ?>

                    <!-- نمایش وزن‌های نهایی در صورت تکمیل ماتریس -->
                    <?php if ($completion_info['is_complete'] && !empty($current_weights)): ?>
                    <div class="weights-table">
                        <h6>وزن‌های نهایی معیارها:</h6>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>معیار</th>
                                    <th>وزن</th>
                                    <th>درصد</th>
                                    <th>نمودار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $max_weight = max($current_weights);
                                foreach ($current_criteria as $i => $criterion): 
                                    $weight = $current_weights[$i];
                                    $percentage = round($weight * 100, 2);
                                    $bar_width = $max_weight > 0 ? ($weight / $max_weight * 100) : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($criterion['name']) ?></td>
                                    <td><?= round($weight, 4) ?></td>
                                    <td><?= $percentage ?>%</td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?= $bar_width ?>%;" aria-valuenow="<?= $percentage ?>"
                                                aria-valuemin="0" aria-valuemax="100">
                                                <?= $percentage ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($completion_info['comparisons_done'] > 0 && !$completion_info['is_complete']): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> این ماتریس به طور کامل پر نشده است.
                        برای محاسبه وزن‌های نهایی، لطفاً <a href="form.php" class="alert-link">فرم مربوطه</a> را تکمیل
                        کنید.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="mt-4 text-center">
                    <?php if ($overall_completion < 100): ?>
                    <a href="form.php" class="btn btn-primary btn-lg">
                        ادامه تکمیل فرم‌ها
                    </a>
                    <?php else: ?>
                    <div class="alert alert-success">
                        <h5><i class="bi bi-check-circle"></i> تبریک! تمام فرم‌ها را تکمیل کرده‌اید.</h5>
                        <p>نتایج نهایی در بالا نمایش داده شده‌اند.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>