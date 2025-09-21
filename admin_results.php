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
$user_matrices = [];

if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    // دریافت اطلاعات کاربر انتخاب شده
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_user) {
        // دریافت ماتریس‌ها
        $matrices = [];
        $main_matrix_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE AND active = TRUE");
        $main_matrix = $main_matrix_stmt->fetch(PDO::FETCH_ASSOC);

        if ($main_matrix) {
            $matrices[] = $main_matrix;
        }

        // دریافت ماتریس‌های زیرمعیار
        $sub_matrices_stmt = $pdo->prepare("
            SELECT m.* 
            FROM matrices m 
            INNER JOIN criteria c ON m.parent_id = c.matrix_id 
            WHERE m.is_main = FALSE AND m.active = TRUE 
            GROUP BY m.id
            ORDER BY c.sort_order
        ");
        $sub_matrices_stmt->execute();
        $sub_matrices = $sub_matrices_stmt->fetchAll(PDO::FETCH_ASSOC);
        $matrices = array_merge($matrices, $sub_matrices);

        // دریافت معیارهای هر ماتریس
        $criteria_by_matrix = [];
        foreach ($matrices as $matrix) {
            $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? AND active = TRUE ORDER BY sort_order");
            $stmt->execute([$matrix['id']]);
            $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $criteria_by_matrix[$matrix['id']] = $criteria;
        }

        // دریافت مقایسه‌های کاربر
        $comparisons_by_matrix = [];
        foreach ($matrices as $matrix) {
            $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
            $stmt->execute([$user_id, $matrix['id']]);
            $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $comparisons_by_matrix[$matrix['id']] = [];
            foreach ($comparisons as $comp) {
                $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
                $comparisons_by_matrix[$matrix['id']][$key] = $comp['value'];
            }
        }

        // محاسبه وزن‌های نهایی با استفاده از روش AHP
        $weights_by_matrix = [];
        foreach ($matrices as $matrix) {
            $current_criteria = $criteria_by_matrix[$matrix['id']];
            $current_comparisons = $comparisons_by_matrix[$matrix['id']] ?? [];
            
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
        }
        
        $user_results = [
            'matrices' => $matrices,
            'criteria_by_matrix' => $criteria_by_matrix,
            'comparisons_by_matrix' => $comparisons_by_matrix,
            'weights_by_matrix' => $weights_by_matrix
        ];
        
        $user_matrices = $matrices;
        
        // محاسبه تعداد ماتریکس‌های تکمیل شده توسط کاربر
        $completed_matrices = 0;
        foreach ($matrices as $matrix) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM comparisons WHERE user_id = ? AND matrix_id = ?");
            $stmt->execute([$user_id, $matrix['id']]);
            $comparison_count = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // محاسبه تعداد مقایسه‌های مورد نیاز برای این ماتریس
            $criteria_count = count($criteria_by_matrix[$matrix['id']]);
            $required_comparisons = $criteria_count > 0 ? ($criteria_count * ($criteria_count - 1)) / 2 : 0;
            
            if ($comparison_count['count'] >= $required_comparisons) {
                $completed_matrices++;
            }
        }
        
        $total_matrices = count($matrices);
        $completion_percentage = $total_matrices > 0 ? round(($completed_matrices / $total_matrices) * 100) : 0;
    }
}
if (isset($_POST['delete_user'])) {
    echo "<script> alert('ss') </script>";
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
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت نتایج کاربران</title>
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

    .user-list {
        max-height: 500px;
        overflow-y: auto;
    }

    .active-user {
        background-color: #e3f2fd !important;
    }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="admin.php">پنل مدیریت ماتریکس‌ها</a>
            <div class="navbar-nav">
                <a class="nav-link" href="admin_results.php">نتایج کاربران</a>
                <a class="nav-link" href="index.php" target="_blank">مشاهده سایت</a>
                <a class="nav-link" href="logout.php">خروج</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title">لیست کاربران</h5>
                    </div>
                    <div class="card-body user-list">
                        <?php if (empty($users)): ?>
                        <p class="text-center">هیچ کاربری ثبت نشده است.</p>
                        <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($users as $user): 
                                    // محاسبه پیشرفت هر کاربر
                                    $stmt = $pdo->prepare("
                                        SELECT COUNT(DISTINCT matrix_id) as completed 
                                        FROM comparisons 
                                        WHERE user_id = ?
                                    ");
                                    $stmt->execute([$user['id']]);
                                    $user_completed = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM matrices WHERE active = TRUE");
                                    $total_m = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    $user_progress = $total_m['total'] > 0 ? round(($user_completed['completed'] / $total_m['total']) * 100) : 0;
                                ?>
                            <a href="?user_id=<?= $user['id'] ?>"
                                class="list-group-item list-group-item-action <?= isset($_GET['user_id']) && $_GET['user_id'] == $user['id'] ? 'active-user' : '' ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($user['fullname']) ?></h6>
                                    <small><?= date('Y/m/d', strtotime($user['created_at'])) ?></small>
                                </div>
                                <p class="mb-1"><?= htmlspecialchars($user['position']) ?></p>
                                <small class="text-muted">تکمیل شده: <?= $user_completed['completed'] ?> از
                                    <?= $total_m['total'] ?></small>
                                <div class="progress mt-1" style="height: 10px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $user_progress ?>%;"
                                        aria-valuenow="<?= $user_progress ?>" aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
                <?php if ($selected_user): ?>
                <div class="card">

                    <div class="col-md-12">
                        <h6>عملیات کاربر:</h6>
                        <form method="post"
                            onsubmit="return confirm('آیا از حذف این کاربر و تمام داده‌های مربوطه اطمینان دارید؟ این عمل غیرقابل بازگشت است.');">
                            <input type="hidden" name="user_id" value="<?= $selected_user['id'] ?>">
                            <button type="submit" name="delete_user" class="btn btn-danger">حذف کاربر و تمام
                                داده‌ها</button>
                        </form>
                    </div>

                    <div class="card-header bg-success text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title">نتایج کاربر: <?= htmlspecialchars($selected_user['fullname']) ?></h5>
                            <a href="admin_results.php" class="btn btn-light btn-sm">بازگشت به لیست</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6>اطلاعات کاربر:</h6>
                                <p><strong>نام و نام خانوادگی:</strong>
                                    <?= htmlspecialchars($selected_user['fullname']) ?></p>
                                <p><strong>شماره همراه:</strong> <?= htmlspecialchars($selected_user['phone']) ?></p>
                                <p><strong>سمت یا جایگاه:</strong> <?= htmlspecialchars($selected_user['position']) ?>
                                </p>
                                <p><strong>تاریخ ثبت:</strong>
                                    <?= date('Y/m/d H:i', strtotime($selected_user['created_at'])) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6>وضعیت تکمیل ماتریکس‌ها:</h6>
                                <p><strong>تعداد ماتریکس‌ها:</strong> <?= $total_matrices ?></p>
                                <p><strong>تکمیل شده:</strong> <?= $completed_matrices ?> از <?= $total_matrices ?></p>
                                <div class="progress mt-2" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar"
                                        style="width: <?= $completion_percentage ?>%;"
                                        aria-valuenow="<?= $completion_percentage ?>" aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?= $completion_percentage ?>%
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5 class="mb-3">نتایج وزن‌دهی:</h5>

                        <?php foreach ($user_results['matrices'] as $matrix): ?>
                        <?php 
                            $current_criteria = $user_results['criteria_by_matrix'][$matrix['id']];
                            $current_comparisons = $user_results['comparisons_by_matrix'][$matrix['id']] ?? [];
                            $current_weights = $user_results['weights_by_matrix'][$matrix['id']] ?? [];
                            
                            // بررسی آیا ماتریکس تکمیل شده است
                            $criteria_count = count($current_criteria);
                            $required_comparisons = $criteria_count > 0 ? ($criteria_count * ($criteria_count - 1)) / 2 : 0;
                            $actual_comparisons = count($current_comparisons);
                            $is_completed = $actual_comparisons >= $required_comparisons;
                            ?>
                        <div class="matrix-container">
                            <div class="matrix-title mb-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5><?= htmlspecialchars($matrix['name']) ?></h5>
                                    <?php if (!empty($matrix['description'])): ?>
                                    <p class="mb-0"><?= htmlspecialchars($matrix['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-<?= $is_completed ? 'success' : 'warning' ?>">
                                    <?= $is_completed ? 'تکمیل شده' : 'ناقص (' . $actual_comparisons . '/' . $required_comparisons . ')' ?>
                                </span>
                            </div>

                            <?php if ($is_completed): ?>
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
                                                    echo $value;
                                                    ?>
                                                <?php else: ?>
                                                <?php
                                                    $key = $criterion2['id'] . '_' . $criterion1['id'];
                                                    $value = isset($current_comparisons[$key]) ? (1 / $current_comparisons[$key]) : '';
                                                    echo round($value, 2);
                                                    ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- نمایش وزن‌های نهایی -->
                            <?php if (!empty($current_weights)): ?>
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
                                                        style="width: <?= $bar_width ?>%;"
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
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                این ماتریکس هنوز تکمیل نشده است. کاربر تنها <?= $actual_comparisons ?> از
                                <?= $required_comparisons ?> مقایسه را انجام داده است.
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title">مدیریت نتایج کاربران</h5>
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-people-fill" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">برای مشاهده نتایج، یک کاربر از لیست سمت راست انتخاب کنید</h5>
                        <p class="text-muted">با انتخاب هر کاربر، می‌توانید نتایج کامل وزن‌دهی او را مشاهده کنید</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // هایلایت کاربر انتخاب شده در لیست
        $('.list-group-item').click(function() {
            $('.list-group-item').removeClass('active-user');
            $(this).addClass('active-user');
        });

        // اسکرول به کاربر انتخاب شده در لیست
        <?php if (isset($_GET['user_id'])): ?>
        const activeUser = document.querySelector('.active-user');
        if (activeUser) {
            activeUser.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
        <?php endif; ?>
    });
    </script>
</body>

</html>