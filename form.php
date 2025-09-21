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

// تعیین سطر جاری
$current_row = isset($_GET['row']) ? (int)$_GET['row'] : 1; // شروع از سطر 1
$total_rows = count($criteria);

// اگر سطر جاری بیشتر از تعداد معیارهاست یا سطر اول است (هیچ مقایسه‌ای ندارد)، به نتایج هدایت شود
if ($current_row >= $total_rows || $current_row == 0) {
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
            $col_criterion_id = $criteria[$col_index]['id'];
            
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
    if ($next_row >= $total_rows) {
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
    $existing_comparisons[$comp['criterion2_id']] = $comp['value'];
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم وزن‌دهی ماتریس - سطر <?= $current_row + 1 ?></title>
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
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card">
            <div class="matrix-header">
                <h4 class="card-title mb-1">فرم وزن‌دهی ماتریس معیارها</h4>
                <p class="mb-0">مقایسه معیار <?= $current_row + 1 ?> با معیارهای قبلی</p>
            </div>

            <div class="card-body" style="background-color:#f8f9fa">
                <!-- نوار پیشرفت -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>پیشرفت تکمیل فرم:</span>
                        <span class="fw-bold"><?= round(($current_row / ($total_rows - 1)) * 100) ?>%</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar"
                            style="width: <?= ($current_row / ($total_rows - 1)) * 100 ?>%;"
                            aria-valuenow="<?= $current_row ?>" aria-valuemin="1"
                            aria-valuemax="<?= $total_rows - 1 ?>">
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    <h6 class="alert-heading">📋 راهنما:</h6>
                    <a href="Help.pdf" class="btn btn-link" style="text-decoration:none">
                        برای آموزش نحوه تکمیل فرآیند اینجا کلیک کنید
                    </a>
                    <p class="mb-2 mt-2">لطفاً اهمیت <strong
                            class="orange-text"><?= htmlspecialchars($criteria[$current_row]['name']) ?></strong> را
                        نسبت به معیار دیگر به صورت اعداد زیر مشخص کنید:</p>
                    <ul class="mb-0">
                        <li>1: اهمیت <strong>برابر</strong> (دو معیار کاملا هم ارزشند)</li>
                        <li>3: <strong>کمی</strong> مهمتر (معیار ردیف نسبت به ستون کمی مهمتر است)</li>
                        <li>5: <strong>مهمتر</strong> (معیار ردیف به طور واضح مهم تر است)</li>
                        <li>7: <strong>خیلی</strong> مهمتر (معیار ردیف خیلی برتری دارد)</li>
                        <li>9: <strong>بسیار</strong> مهمتر (معیار ردیف کاملا برتر است)</li>
                        <li>اعداد زوج (2,4,6,8) ارزش های میانی برای وقتی که اهمیت دقیق بین اعداد اصلی نیاز است</li>
                    </ul>
                </div>

                <form id="comparisonForm" method="post">
                    <div class="comparisons-container">
                        <?php for ($col = 0; $col < $current_row; $col++): ?>
                        <?php 
                            $col_criterion = $criteria[$col];
                            $existing_value = $existing_comparisons[$col_criterion['id']] ?? '';
                            ?>

                        <div class="comparison-item shadow-sm">
                            <div class="comparison-label">
                                <span class="orange-text">مقایسه:</span>
                                <strong><?= htmlspecialchars($criteria[$current_row]['name']) ?></strong>
                                <span class="orange-text">نسبت به</span>
                                <strong><?= htmlspecialchars($col_criterion['name']) ?></strong>
                            </div>

                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">مقدار وزن:</label>
                                        <input type="number" class="form-control comparison-input"
                                            name="values[<?= $col ?>]" value="<?= $existing_value ?>" step="0.1"
                                            min="0.1" max="9" required placeholder="عدد 1 تا 9 وارد کنید">
                                        <div class="invalid-feedback">لطفاً عددی بین 0.1 تا 9 وارد کنید</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">مقدار معکوس (محاسبه خودکار):</label>
                                        <input type="text" class="form-control reciprocal-value"
                                            value="<?= !empty($existing_value) ? round(1 / $existing_value, 2) : '' ?>"
                                            disabled readonly>
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
                        <?php if ($current_row > 1): ?>
                        <a href="form.php?row=<?= $current_row - 1 ?>" class="btn btn-secondary">
                            ← بازگشت به سطر قبلی
                        </a>
                        <?php else: ?>
                        <a href="index.php" class="btn btn-outline-secondary">
                            ← بازگشت به صفحه اصلی
                        </a>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary btn-lg">
                            <?php if ($current_row < $total_rows - 1): ?>
                            ذخیره و ادامه به سطر بعدی →
                            <?php else: ?>
                            تکمیل ماتریس و مشاهده نتایج
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
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

            if (value < 0.1 || value > 9) {
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
                if (isNaN(value) || value < 0.1 || value > 9) {
                    isValid = false;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('لطفاً مقادیر معتبر بین 0.1 تا 9 وارد کنید.');
                $('html, body').animate({
                    scrollTop: $('.is-invalid').first().offset().top - 100
                }, 500);
            }
        });

        // focus روی اولین input
        $('.comparison-input').first().focus();
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>