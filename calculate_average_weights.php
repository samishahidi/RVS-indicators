<?php
session_start();
require_once 'db_config.php';

// بررسی احراز هویت ادمین
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// تابع محاسبه میانگین وزنی
function calculateAverageWeights($pdo) {
    // دریافت تمام ماتریس‌ها
    $matrices = [];
    $main_matrix_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE");
    $main_matrix = $main_matrix_stmt->fetch(PDO::FETCH_ASSOC);

    if ($main_matrix) {
        $matrices[] = $main_matrix;
    }

    $sub_matrices_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = FALSE");
    $sub_matrices = $sub_matrices_stmt->fetchAll(PDO::FETCH_ASSOC);
    $matrices = array_merge($matrices, $sub_matrices);

    $results = [];

    foreach ($matrices as $matrix) {
        // دریافت معیارهای این ماتریس
        $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ?");
        $stmt->execute([$matrix['id']]);
        $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // دریافت تمام مقایسه‌های کاربران برای این ماتریس
        $stmt = $pdo->prepare("
            SELECT c.*, u.fullname 
            FROM comparisons c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.matrix_id = ?
        ");
        $stmt->execute([$matrix['id']]);
        $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // گروه‌بندی مقایسه‌ها بر اساس معیارها
        $criterion_weights = [];
        foreach ($criteria as $criterion) {
            $criterion_weights[$criterion['id']] = [
                'name' => $criterion['name'],
                'values' => [],
                'average' => 0,
                'count' => 0
            ];
        }

        // محاسبه وزن‌های نهایی برای هر کاربر
        $user_weights = [];
        $user_comparisons = [];

        // گروه‌بندی مقایسه‌ها بر اساس کاربر
        foreach ($comparisons as $comp) {
            $user_id = $comp['user_id'];
            if (!isset($user_comparisons[$user_id])) {
                $user_comparisons[$user_id] = [
                    'name' => $comp['fullname'],
                    'comparisons' => []
                ];
            }
            $user_comparisons[$user_id]['comparisons'][] = $comp;
        }

        // محاسبه وزن‌های هر کاربر
        foreach ($user_comparisons as $user_id => $user_data) {
            // ایجاد ماتریس مقایسه‌های زوجی برای این کاربر
            $pairwise_matrix = [];
            $n = count($criteria);
            
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
            
            // پر کردن ماتریس با مقادیر کاربر
            foreach ($user_data['comparisons'] as $comp) {
                // یافتن اندیس معیارها
                $index1 = -1;
                $index2 = -1;
                
                foreach ($criteria as $idx => $criterion) {
                    if ($criterion['id'] == $comp['criterion1_id']) $index1 = $idx;
                    if ($criterion['id'] == $comp['criterion2_id']) $index2 = $idx;
                }
                
                if ($index1 >= 0 && $index2 >= 0) {
                    $pairwise_matrix[$index1][$index2] = $comp['value'];
                    $pairwise_matrix[$index2][$index1] = 1 / $comp['value'];
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
                
                // ذخیره وزن برای معیار مربوطه
                $criterion_id = $criteria[$i]['id'];
                $criterion_weights[$criterion_id]['values'][] = $weights[$i];
                $criterion_weights[$criterion_id]['count']++;
            }
            
            $user_weights[$user_id] = [
                'name' => $user_data['name'],
                'weights' => $weights
            ];
        }

        // محاسبه میانگین وزنی برای هر معیار
        $average_weights = [];
        foreach ($criterion_weights as $criterion_id => $data) {
            if ($data['count'] > 0) {
                $average = array_sum($data['values']) / $data['count'];
                $average_weights[$criterion_id] = [
                    'name' => $data['name'],
                    'average' => $average,
                    'count' => $data['count'],
                    'values' => $data['values']
                ];
            }
        }

        $results[$matrix['id']] = [
            'matrix_name' => $matrix['name'],
            'matrix_description' => $matrix['description'],
            'criteria' => $criteria,
            'average_weights' => $average_weights,
            'user_weights' => $user_weights
        ];
    }

    return $results;
}

// پردازش درخواست محاسبه میانگین
$average_results = [];
if (isset($_GET['calculate_average'])) {
    $average_results = calculateAverageWeights($pdo);
}

// دریافت تعداد کاربران
$users_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
$users_count = $users_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محاسبه وزن میانگین معیارها</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .card {
        margin-bottom: 20px;
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

    .table th {
        background-color: #f8f9fa;
    }

    .nav-tabs .nav-link.active {
        font-weight: bold;
    }
    </style>
</head>

<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title">محاسبه وزن میانگین معیارها</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-info-circle"></i> اطلاعات سیستم</h6>
                                    <p class="mb-1">تعداد کاربران ثبت‌شده: <strong><?= $users_count ?> نفر</strong></p>
                                    <p class="mb-0">با کلیک روی دکمه زیر، میانگین وزن‌های تمام کاربران محاسبه خواهد شد.
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <a href="?calculate_average=1" class="btn btn-success btn-lg">
                                    <i class="bi bi-calculator"></i> محاسبه میانگین وزن‌ها
                                </a>
                                <a href="admin.php" class="btn btn-secondary btn-lg">
                                    <i class="bi bi-arrow-right"></i> بازگشت به پنل مدیریت
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($average_results)): ?>
                        <div class="alert alert-success">
                            <h6><i class="bi bi-check-circle"></i> محاسبات با موفقیت انجام شد</h6>
                            <p class="mb-0">میانگین وزن‌های <?= $users_count ?> کاربر محاسبه و نمایش داده می‌شود.</p>
                        </div>

                        <ul class="nav nav-tabs" id="matrixTabs" role="tablist">
                            <?php foreach ($average_results as $matrix_id => $matrix_data): ?>
                            <li class="nav-item" role="presentation">
                                <button
                                    class="nav-link <?= $matrix_id == array_key_first($average_results) ? 'active' : '' ?>"
                                    id="tab-<?= $matrix_id ?>" data-bs-toggle="tab"
                                    data-bs-target="#matrix-<?= $matrix_id ?>" type="button" role="tab">
                                    <?= htmlspecialchars($matrix_data['matrix_name']) ?>
                                </button>
                            </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="tab-content" id="matrixTabContent">
                            <?php foreach ($average_results as $matrix_id => $matrix_data): ?>
                            <div class="tab-pane fade <?= $matrix_id == array_key_first($average_results) ? 'show active' : '' ?>"
                                id="matrix-<?= $matrix_id ?>" role="tabpanel">

                                <div class="matrix-title mt-3">
                                    <h5><?= htmlspecialchars($matrix_data['matrix_name']) ?></h5>
                                    <?php if (!empty($matrix_data['matrix_description'])): ?>
                                    <p class="mb-0"><?= htmlspecialchars($matrix_data['matrix_description']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <h6 class="mt-4">میانگین وزن‌های نهایی:</h6>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>معیار</th>
                                                <th>میانگین وزن</th>
                                                <th>درصد</th>
                                                <th>تعداد کاربران</th>
                                                <th>نمودار</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $max_weight = 0;
                                            $max_width = 1;
                                            foreach ($matrix_data['average_weights'] as $weight_data) {
                                                if ($weight_data['average'] > $max_weight) {
                                                    $max_weight = $weight_data['average'];
                                                }
                                            }
                                            
                                            foreach ($matrix_data['average_weights'] as $criterion_id => $weight_data): 
                                                $percentage = round($weight_data['average'] * 100, 2);
                                                $bar_width = $max_weight > 0 ? ($weight_data['average'] / $max_width * 100) : 0;
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($weight_data['name']) ?></td>
                                                <td><?= round($weight_data['average'], 4) ?></td>
                                                <td><?= $percentage ?>%</td>
                                                <td><?= $weight_data['count'] ?> کاربر</td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" role="progressbar"
                                                            style="width: <?= $bar_width ?>%; max-width:<?= $max_width ?>"
                                                            aria-valuenow="<?= $percentage ?>" aria-valuemin="0"
                                                            aria-valuemax="100">
                                                            <?= $percentage ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <h6 class="mt-4">جزئیات وزن‌های هر کاربر:</h6>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>کاربر</th>
                                                <?php foreach ($matrix_data['criteria'] as $criterion): ?>
                                                <th><?= htmlspecialchars($criterion['name']) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($matrix_data['user_weights'] as $user_id => $user_data): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($user_data['name']) ?></td>
                                                <?php foreach ($user_data['weights'] as $weight): ?>
                                                <td><?= round($weight, 4) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-bar-chart-line" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">هیچ محاسبه‌ای انجام نشده است</h5>
                            <p class="text-muted">برای محاسبه میانگین وزن‌های کاربران، روی دکمه "محاسبه میانگین وزن‌ها"
                                کلیک کنید.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>