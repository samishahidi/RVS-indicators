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

if (!$user) {
    header('Location: index.php');
    exit;
}

// دریافت ماتریس معیارهای اصلی
$stmt = $pdo->query("SELECT * FROM matrices WHERE is_criteria_matrix = TRUE");
$main_matrix = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$main_matrix) {
    die("هیچ معیاری تعریف نشده است. لطفاً با ادمین تماس بگیرید.");
}

// دریافت معیارهای اصلی
$stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? ORDER BY sort_order");
$stmt->execute([$main_matrix['id']]);
$criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($criteria)) {
    die("هیچ معیاری تعریف نشده است. لطفاً با ادمین تماس بگیرید.");
}

$total_criteria = count($criteria);

// تعیین سطر جاری
$current_row = isset($_GET['row']) ? (int)$_GET['row'] : 0; // شروع از سطر 0 (اولین سطر)

// محاسبه تعداد کل مقایسه‌های مورد نیاز
$total_comparisons_needed = ($total_criteria * ($total_criteria - 1)) / 2;

// محاسبه تعداد مقایسه‌های انجام شده تا این سطر
$comparisons_done_so_far = 0;
for ($i = 0; $i < $current_row; $i++) {
    $comparisons_done_so_far += ($total_criteria - $i - 1);
}

// اگر سطر جاری بیشتر از تعداد معیارهاست، به نتایج هدایت شود
if ($current_row >= $total_criteria - 1) {
    // تکمیل فرآیند
    $stmt = $pdo->prepare("UPDATE users SET completed = TRUE WHERE id = ?");
    $stmt->execute([$user_id]);
    header('Location: results.php');
    exit;
}

// پردازش ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row_criterion_id = $criteria[$current_row]['id'];
    
    // ذخیره مقادیر سطر جاری
    foreach ($_POST['values'] as $col_index => $value) {
        if (!empty($value)) {
            $actual_col_index = $current_row + $col_index + 1; // ستون‌های بعد از سطر جاری
            $col_criterion_id = $criteria[$actual_col_index]['id'];
            
            // بررسی وجود مقایسه قبلی
            $stmt = $pdo->prepare("SELECT id FROM comparisons 
                                  WHERE user_id = ? AND criterion1_id = ? AND criterion2_id = ?");
            $stmt->execute([$user_id, $row_criterion_id, $col_criterion_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // به روزرسانی مقایسه موجود
                $stmt = $pdo->prepare("UPDATE comparisons SET value = ? WHERE id = ?");
                $stmt->execute([$value, $existing['id']]);
            } else {
                // درج مقایسه جدید
                $stmt = $pdo->prepare("INSERT INTO comparisons (user_id, criterion1_id, criterion2_id, value, matrix_id) 
                                      VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $row_criterion_id, $col_criterion_id, $value, $main_matrix['id']]);
            }
        }
    }
    
    // رفتن به سطر بعدی
    $next_row = $current_row + 1;
    
    // اگر سطر بعدی آخرین سطر است (هیچ مقایسه‌ای ندارد)، مستقیماً به نتایج برو
    if ($next_row >= $total_criteria - 1) {
        $stmt = $pdo->prepare("UPDATE users SET completed = TRUE WHERE id = ?");
        $stmt->execute([$user_id]);
        header('Location: results.php');
        exit;
    }
    
    header("Location: form.php?row=$next_row");
    exit;
}

// دریافت مقایسه‌های قبلی کاربر برای سطر جاری
$existing_comparisons = [];
$row_criterion_id = $criteria[$current_row]['id'];

$stmt = $pdo->prepare("SELECT * FROM comparisons 
                      WHERE user_id = ? AND criterion1_id = ?");
$stmt->execute([$user_id, $row_criterion_id]);
$comparisons_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($comparisons_data as $comp) {
    // پیدا کردن index ستون مربوطه
    foreach ($criteria as $index => $criterion) {
        if ($criterion['id'] == $comp['criterion2_id']) {
            $existing_comparisons[$index] = $comp['value'];
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم وزن‌دهی ماتریس - مرحله <?= $current_row + 1 ?></title>
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
        background-color: white;
    }

    .comparison-label {
        font-weight: bold;
        margin-bottom: 10px;
        color: #333;
    }

    .orange-text {
        color: #fd7e14;
        font-weight: bold;
    }

    .matrix-header {
        background: linear-gradient(45deg, #007bff, #0056b3);
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .navigation-buttons {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #dee2e6;
    }

    .is-invalid {
        border-color: #dc3545 !important;
    }

    .current-criterion {
        background-color: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card">
            <div class="matrix-header">
                <h4 class="card-title mb-1">فرم وزن‌دهی ماتریس معیارها</h4>
                <p class="mb-0">مرحله <?= $current_row + 1 ?> از <?= $total_criteria - 1 ?> - مقایسه معیار فعلی با
                    معیارهای بعدی</p>
            </div>

            <div class="card-body" style="background-color:#f8f9fa">
                <!-- نوار پیشرفت -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>پیشرفت تکمیل فرم:</span>
                        <span class="fw-bold">
                            <?php 
                            $current_comparisons = $total_criteria - $current_row - 1;
                            $total_done = $comparisons_done_so_far;
                            $percentage = $total_comparisons_needed > 0 ? round(($total_done / $total_comparisons_needed) * 100) : 0;
                            echo $percentage ?>%
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar" style="width: <?= $percentage ?>%;"
                            aria-valuenow="<?= $total_done ?>" aria-valuemin="0"
                            aria-valuemax="<?= $total_comparisons_needed ?>">
                            <?= $total_done ?> از <?= $total_comparisons_needed ?>
                        </div>
                    </div>
                </div>

                <!-- معیار جاری -->
                <div class="current-criterion">
                    <h5 class="mb-2">📊 معیار فعلی برای مقایسه:</h5>
                    <h4 class="orange-text mb-0"><?= htmlspecialchars($criteria[$current_row]['name']) ?></h4>
                    <?php if (!empty($criteria[$current_row]['description'])): ?>
                    <p class="mb-0 mt-2 text-muted"><?= htmlspecialchars($criteria[$current_row]['description']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="alert alert-info">
                    <h6 class="alert-heading">📋 راهنما:</h6>
                    <a href="Help.pdf" class="btn btn-link" style="text-decoration:none">
                        برای آموزش نحوه تکمیل فرآیند اینجا کلیک کنید
                    </a>
                    <p class="mb-2 mt-2">لطفاً اهمیت <strong
                            class="orange-text"><?= htmlspecialchars($criteria[$current_row]['name']) ?></strong> را
                        نسبت به معیارهای زیر مشخص کنید:</p>
                    <ul class="mb-0">
                        <li>1: اهمیت <strong>برابر</strong> (دو معیار کاملا هم ارزشند)</li>
                        <li>3: <strong>کمی</strong> مهمتر (معیار ردیف نسبت به ستون کمی مهمتر است)</li>
                        <li>5: <strong>مهمتر</strong> (معیار ردیف به طور واضح مهمتر است)</li>
                        <li>7: <strong>خیلی</strong> مهمتر (معیار ردیف خیلی برتری دارد)</li>
                        <li>9: <strong>بسیار</strong> مهمتر (معیار ردیف کاملا برتر است)</li>
                        <li>اعداد زوج (2,4,6,8) برای اهمیت‌های میانی</li>
                        <li>اعداد کسری (مانند 0.33، 0.25) برای وقتی که معیار مقابل مهمتر است</li>
                    </ul>
                </div>

                <form id="comparisonForm" method="post">
                    <div class="comparisons-container">
                        <?php 
                        // تعداد ستون‌های باقی‌مانده برای مقایسه
                        $remaining_columns = $total_criteria - $current_row - 1;
                        
                        for ($col = 0; $col < $remaining_columns; $col++): 
                            $actual_col_index = $current_row + $col + 1;
                            $col_criterion = $criteria[$actual_col_index];
                            $existing_value = $existing_comparisons[$actual_col_index] ?? '';
                        ?>
                        <div class="comparison-item shadow-sm">
                            <div class="comparison-label">
                                <span class="text-secondary">مقایسه <?= $col + 1 ?>:</span>
                                <span class="orange-text">نسبت اهمیت </span>
                                <strong><?= htmlspecialchars($criteria[$current_row]['name']) ?></strong>
                                <span class="orange-text">به </span>
                                <strong><?= htmlspecialchars($col_criterion['name']) ?></strong>
                            </div>

                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">مقدار وزن:</label>
                                        <input type="number" class="form-control comparison-input"
                                            name="values[<?= $col ?>]" value="<?= $existing_value ?>" step="0.01"
                                            min="0.11" max="9" required placeholder="مثلاً: 1, 3, 5, 0.33, 0.2">
                                        <div class="invalid-feedback">لطفاً عددی بین 0.11 تا 9 وارد کنید</div>
                                        <small class="form-text text-muted">
                                            اگر <?= htmlspecialchars($col_criterion['name']) ?> مهمتر است، از اعداد کسری
                                            استفاده کنید
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">مقدار معکوس (محاسبه خودکار):</label>
                                        <input type="text" class="form-control reciprocal-value"
                                            value="<?= !empty($existing_value) ? round(1 / $existing_value, 2) : '' ?>"
                                            disabled readonly>
                                        <small class="form-text text-muted">
                                            اهمیت <?= htmlspecialchars($col_criterion['name']) ?> نسبت به
                                            <?= htmlspecialchars($criteria[$current_row]['name']) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($col_criterion['description'])): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong>توضیحات <?= htmlspecialchars($col_criterion['name']) ?>:</strong>
                                    <?= htmlspecialchars($col_criterion['description']) ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <div class="navigation-buttons d-flex justify-content-between">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <?php if ($current_row < $total_criteria - 2): ?>
                            ✅ ذخیره و ادامه به مرحله بعدی →
                            <?php else: ?>
                            🎉 تکمیل ماتریس و مشاهده نتایج
                            <?php endif; ?>
                        </button>

                        <?php if ($current_row > 0): ?>
                        <a href="form.php?row=<?= $current_row - 1 ?>" class="btn btn-secondary">
                            ← بازگشت به مرحله قبلی
                        </a>
                        <?php else: ?>
                        <a href="index.php" class="btn btn-outline-secondary">
                            ← بازگشت به صفحه اصلی
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- نمایش وضعیت پیشرفت -->
                <div class="alert alert-light mt-4">
                    <h6 class="alert-heading">📈 وضعیت پیشرفت:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>مرحله فعلی:</strong> <?= $current_row + 1 ?> از <?= $total_criteria - 1 ?>
                        </div>
                        <div class="col-md-6">
                            <strong>مقایسه‌های باقی‌مانده:</strong> <?= $remaining_columns ?>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-12">
                            <strong>توضیح فرآیند:</strong>
                            در این مرحله، معیار
                            <strong><?= htmlspecialchars($criteria[$current_row]['name']) ?></strong>
                            را با <?= $remaining_columns ?> معیار بعدی مقایسه می‌کنید.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // محاسبه مقادیر معکوس به صورت real-time
        $('.comparison-input').on('input', function() {
            let value = parseFloat($(this).val());

            if (isNaN(value) || value <= 0) {
                $(this).closest('.row').find('.reciprocal-value').val('');
                $(this).removeClass('is-invalid');
                return;
            }

            if (value < 0.11 || value > 9) {
                $(this).addClass('is-invalid');
                $(this).closest('.row').find('.reciprocal-value').val('');
            } else {
                $(this).removeClass('is-invalid');
                // محاسبه مقدار معکوس
                let reciprocalValue = (1 / value).toFixed(2);
                $(this).closest('.row').find('.reciprocal-value').val(reciprocalValue);
            }
        });

        // اعتبارسنجی فرم
        $('#comparisonForm').on('submit', function(e) {
            let isValid = true;
            $('.comparison-input').each(function() {
                let value = parseFloat($(this).val());
                if (isNaN(value) || value < 0.11 || value > 9) {
                    isValid = false;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('لطفاً مقادیر معتبر بین 0.11 تا 9 وارد کنید.');
                $('html, body').animate({
                    scrollTop: $('.is-invalid').first().offset().top - 100
                }, 500);
            }
        });

        // focus روی اولین input
        $('.comparison-input').first().focus();

        // نمایش راهنمای مقدار ورودی
        $('.comparison-input').on('focus', function() {
            $(this).attr('title',
                'مقادیر مجاز: 1 (برابر)، 3 (کمی مهمتر)، 5 (مهمتر)، 7 (خیلی مهمتر)، 9 (بسیار مهمتر) یا اعداد کسری برای زمانی که معیار مقابل مهمتر است'
            );
        });
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>