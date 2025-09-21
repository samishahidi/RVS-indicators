<?php
session_start();
require_once 'db_config.php';

// بررسی وجود کاربر
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_step = $_SESSION['current_step'] ?? 1;

// پردازش درخواست بازگشت به مرحله قبل
if (isset($_GET['prev']) && $_GET['prev'] == 1) {
    if ($current_step > 1) {
        $prev_step = $current_step - 1;
        $stmt = $pdo->prepare("UPDATE users SET current_step = ? WHERE id = ?");
        $stmt->execute([$prev_step, $user_id]);
        $_SESSION['current_step'] = $prev_step;
        header('Location: form.php');
        exit;
    }
}

// دریافت اطلاعات کاربر
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: index.php');
    exit;
}

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

// پردازش ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matrix_id = $_POST['matrix_id'];
    $comparisons = $_POST['comparisons'];
    
    foreach ($comparisons as $comp) {
        $criterion1_id = $comp['criterion1'];
        $criterion2_id = $comp['criterion2'];
        $value = $comp['value'];
        
        // بررسی وجود مقایسه قبلی
        $stmt = $pdo->prepare("SELECT id FROM comparisons WHERE user_id = ? AND criterion1_id = ? AND criterion2_id = ? AND matrix_id = ?");
        $stmt->execute([$user_id, $criterion1_id, $criterion2_id, $matrix_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // به روزرسانی مقایسه موجود
            $stmt = $pdo->prepare("UPDATE comparisons SET value = ? WHERE id = ?");
            $stmt->execute([$value, $existing['id']]);
        } else {
            // درج مقایسه جدید
            $stmt = $pdo->prepare("INSERT INTO comparisons (user_id, criterion1_id, criterion2_id, value, matrix_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $criterion1_id, $criterion2_id, $value, $matrix_id]);
        }
    }
    
    // به روزرسانی مرحله کاربر
    $next_step = $current_step + 1;
    if ($next_step <= count($matrices)) {
        $stmt = $pdo->prepare("UPDATE users SET current_step = ? WHERE id = ?");
        $stmt->execute([$next_step, $user_id]);
        $_SESSION['current_step'] = $next_step;
        $current_step = $next_step;
    } else {
        // تمام مراحل تکمیل شده‌اند
        header('Location: results.php');
        exit;
    }
}

// اگر تمام مراحل تکمیل شده‌اند، به صفحه نتایج هدایت شود
if ($current_step > count($matrices)) {
    header('Location: results.php');
    exit;
}

// ماتریس فعلی
$current_matrix = $matrices[$current_step - 1];
$current_criteria = $criteria_by_matrix[$current_matrix['id']];

// دریافت مقایسه‌های قبلی کاربر برای این ماتریس
$existing_comparisons = [];
$stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
$stmt->execute([$user_id, $current_matrix['id']]);
$comparisons_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($comparisons_data as $comp) {
    $key = $comp['criterion1_id'] . '_' . $comp['criterion2_id'];
    $existing_comparisons[$key] = $comp['value'];
}

// تولید لیست مقایسه‌های لازم (فقط مثلث بالایی ماتریس)
$comparison_pairs = [];
for ($i = 0; $i < count($current_criteria); $i++) {
    for ($j = $i + 1; $j < count($current_criteria); $j++) {
        $criterion1 = $current_criteria[$i];
        $criterion2 = $current_criteria[$j];
        $key = $criterion1['id'] . '_' . $criterion2['id'];
        $value = $existing_comparisons[$key] ?? '';
        
        $comparison_pairs[] = [
            'criterion1' => $criterion1,
            'criterion2' => $criterion2,
            'value' => $value
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم وزن‌دهی معیارها</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    .progress {
        height: 30px;
    }

    .progress-bar {
        font-weight: bold;
    }

    .comparison-item {
        margin-bottom: 15px;
        padding: 15px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
    }

    .comparison-label {
        font-weight: bold;
        margin-bottom: 10px;
    }

    .orange-text {
        color: #fd7e14;
        font-weight: bold;
    }

    .divider {
        border-top: 2px dashed #dee2e6;
        margin: 20px 0;
    }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="card-title">فرم وزن‌دهی معیارها - مرحله <?= $current_step ?> از <?= count($matrices) ?></h4>

                <a href="results.php" class="btn btn-danger">مشاهده نتایج</a>
            </div>
            <div class="card-body" style="background-color:#efefef">
                <!-- نوار پیشرفت -->
                <div class="mb-4">
                    <div class="progress">
                        <div class="progress-bar" role="progressbar"
                            style="width: <?= ($current_step / count($matrices)) * 100 ?>%;"
                            aria-valuenow="<?= $current_step ?>" aria-valuemin="1"
                            aria-valuemax="<?= count($matrices) ?>">
                            <?= $current_step ?> از <?= count($matrices) ?>
                        </div>
                    </div>
                </div>

                <h5 class="mb-3"><?= htmlspecialchars($current_matrix['name']) ?></h5>
                <?php if (!empty($current_matrix['description'])): ?>
                <p class="text-muted"><?= htmlspecialchars($current_matrix['description']) ?></p>
                <?php endif; ?>

                <div class="alert alert-info">
                    <h6>راهنما:</h6>
                    <p>لطفاً اهمیت هر معیار را نسبت به معیار دیگر با اعداد 1 تا 9 مشخص کنید:</p>
                    <ul class="mb-0">
                        <li>1: اهمیت برابر</li>
                        <li>3: کمی مهمتر</li>
                        <li>5: خیلی مهمتر</li>
                        <li>7: بسیار مهمتر</li>
                        <li>9: به شدت مهمتر</li>
                        <li>اعداد زوج (2,4,6,8) برای مقادیر واسطه</li>
                    </ul>
                </div>

                <form id="comparisonForm" method="post">
                    <input type="hidden" name="matrix_id" value="<?= $current_matrix['id'] ?>">

                    <div class="comparisons-container">
                        <?php $oldMeyar = ''; ?>
                        <?php foreach ($comparison_pairs as $index => $pair): ?>


                        <?php
                            $line = false;
                            if($oldMeyar != $pair['criterion1']['name']){
                                $oldMeyar = $pair['criterion1']['name'];
                                $line = true;
                            }
                        ?>

                        <?php
                            if($line){
                        ?>
                        <div class="p-1 bg-white shadow mt-4">
                            <?php
                            }
                        ?>

                            <div class="comparison-item">
                                <div class="comparison-label">
                                    <span class="orange-text">نسبت</span>
                                    <strong><?= htmlspecialchars($pair['criterion1']['name']) ?></strong>
                                    <span class="orange-text">به</span>
                                    <strong><?= htmlspecialchars($pair['criterion2']['name']) ?></strong>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="comparison_<?= $index ?>">مقدار:</label>
                                            <input type="number" class="form-control comparison-input"
                                                id="comparison_<?= $index ?>" name="comparisons[<?= $index ?>][value]"
                                                data-criterion1="<?= $pair['criterion1']['id'] ?>"
                                                data-criterion2="<?= $pair['criterion2']['id'] ?>"
                                                value="<?= $pair['value'] ?>" step="0.1" min="0.1" max="9">
                                            <input type="hidden" name="comparisons[<?= $index ?>][criterion1]"
                                                value="<?= $pair['criterion1']['id'] ?>">
                                            <input type="hidden" name="comparisons[<?= $index ?>][criterion2]"
                                                value="<?= $pair['criterion2']['id'] ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="reciprocal_<?= $index ?>">مقدار معکوس:</label>
                                            <input type="text" class="form-control reciprocal-value"
                                                id="reciprocal_<?= $index ?>"
                                                value="<?= !empty($pair['value']) ? round(1 / $pair['value'], 2) : '' ?>"
                                                disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>


                            <?php
                            if(!$line){
                            ?>
                        </div>
                        <?php
                                }
                            ?>

                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3 d-flex justify-content-between">
                        <?php if ($current_step > 1): ?>
                        <a href="?prev=1" class="btn btn-info">مرحله قبل</a>
                        <?php else: ?>
                        <span></span>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <?= $current_step < count($matrices) ? 'ذخیره و ادامه' : 'پایان و مشاهده نتایج' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // محاسبه مقادیر معکوس به صورت real-time
        $('.comparison-input').on('input', function() {
            let value = parseFloat($(this).val());
            if (isNaN(value) || value <= 0) return;

            // محاسبه مقدار معکوس
            let reciprocalValue = (1 / value).toFixed(2);
            $(this).closest('.row').find('.reciprocal-value').val(reciprocalValue);

            // ذخیره real-time با AJAX
            let formData = $('#comparisonForm').serialize();

            $.ajax({
                url: 'save_comparison.php',
                type: 'POST',
                data: formData,
                success: function(response) {
                    console.log('Data saved successfully');
                },
                error: function() {
                    console.log('Error saving data');
                }
            });
        });
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

<style>
.orenge-text {
    color: tomato;
}
</style>