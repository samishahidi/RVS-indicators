<?php
session_start();
require_once 'db_config.php';

// بررسی احراز هویت ادمین
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// پردازش فرم‌های ارسالی
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_main_criteria'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    // ایجاد ماتریس اصلی اگر وجود ندارد
    $stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE AND is_criteria_matrix = TRUE");
    $main_matrix = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$main_matrix) {
        $stmt = $pdo->prepare("INSERT INTO matrices (name, description, is_main, is_criteria_matrix) 
                              VALUES (?, ?, TRUE, TRUE)");
        $stmt->execute(['ماتریس معیارهای اصلی', 'ماتریس معیارهای اصلی سیستم']);
        $matrix_id = $pdo->lastInsertId();
    } else {
        $matrix_id = $main_matrix['id'];
    }
    
    // پیدا کردن آخرین ترتیب (اصلاح شده)
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as new_order FROM criteria WHERE matrix_id = ?");
    $stmt->execute([$matrix_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $new_order = $result['new_order'];
    
    // افزودن معیار جدید (اصلاح شده)
    $stmt = $pdo->prepare("INSERT INTO criteria (matrix_id, name, description, sort_order) 
                          VALUES (?, ?, ?, ?)");
    $stmt->execute([$matrix_id, $name, $description, $new_order]);
    
    $_SESSION['success'] = "معیار با موفقیت افزوده شد.";
    header('Location: admin.php');
    exit;
} elseif (isset($_POST['update_criteria_order'])) {
        $orders = $_POST['order'];
        foreach ($orders as $criteria_id => $order) {
            $stmt = $pdo->prepare("UPDATE criteria SET sort_order = ? WHERE id = ?");
            $stmt->execute([$order, $criteria_id]);
        }
        
        $_SESSION['success'] = "ترتیب معیارها با موفقیت به روز شد.";
        header('Location: admin.php');
        exit;
        
    } elseif (isset($_POST['delete_criteria'])) {
        $criteria_id = (int)$_POST['criteria_id'];
        
        // حذف مقایسه‌های مربوط به این معیار
        $stmt = $pdo->prepare("DELETE FROM comparisons WHERE criterion1_id = ? OR criterion2_id = ?");
        $stmt->execute([$criteria_id, $criteria_id]);
        
        // حذف خود معیار
        $stmt = $pdo->prepare("DELETE FROM criteria WHERE id = ?");
        $stmt->execute([$criteria_id]);
        
        $_SESSION['success'] = "معیار با موفقیت حذف شد.";
        header('Location: admin.php');
        exit;
        
    } elseif (isset($_POST['edit_criteria'])) {
        $criteria_id = (int)$_POST['criteria_id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        
        $stmt = $pdo->prepare("UPDATE criteria SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $criteria_id]);
        
        $_SESSION['success'] = "معیار با موفقیت ویرایش شد.";
        header('Location: admin.php');
        exit;
    }
}

// خط 87: دریافت ماتریس معیارهای اصلی
$stmt = $pdo->query("SELECT * FROM matrices WHERE is_criteria_matrix = TRUE");
$main_matrix = $stmt->fetch(PDO::FETCH_ASSOC);

// دریافت معیارهای اصلی
$main_criteria = [];
if ($main_matrix) {
    // خط 92: دریافت معیارها (حذف شرط is_active)
    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? ORDER BY sort_order");
    $stmt->execute([$main_matrix['id']]);
    $main_criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// دریافت کاربران و وضعیت آنها
$users_stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت - سیستم ماتریس معیارها</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .sortable-ghost {
        opacity: 0.5;
        background-color: #f8f9fa;
    }

    .criteria-item {
        cursor: move;
        transition: all 0.3s ease;
    }

    .criteria-item:hover {
        background-color: #f8f9fa;
    }

    .progress-bar {
        transition: width 0.3s ease;
    }

    .user-status-completed {
        color: #198754;
        font-weight: bold;
    }

    .user-status-inprogress {
        color: #fd7e14;
        font-weight: bold;
    }

    .user-status-notstarted {
        color: #6c757d;
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
        <!-- پیام‌های موفقیت -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- بخش سمت راست - آمار کلی -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">آمار سیستم</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>تعداد معیارها:</span>
                            <strong class="text-primary"><?= count($main_criteria) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>تعداد کاربران:</span>
                            <strong class="text-primary"><?= count($users) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span>کاربران تکمیل‌شده:</span>
                            <strong class="text-success">
                                <?= count(array_filter($users, fn($u) => $u['completed'])) ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- کاربران اخیر -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0">آخرین کاربران</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach (array_slice($users, 0, 5) as $user): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><?= htmlspecialchars($user['fullname']) ?></span>
                            <span
                                class="<?= $user['completed'] ? 'user-status-completed' : 'user-status-inprogress' ?>">
                                <?= $user['completed'] ? 'تکمیل شده' : 'در حال انجام' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- بخش سمت چپ - مدیریت معیارها -->
            <div class="col-md-8">
                <!-- فرم افزودن معیار اصلی -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle me-2"></i>
                            افزودن معیار جدید
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">نام معیار:</label>
                                        <input type="text" class="form-control" id="name" name="name" required
                                            placeholder="نام معیار را وارد کنید">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="description" class="form-label">توضیحات:</label>
                                        <input type="text" class="form-control" id="description" name="description"
                                            placeholder="توضیحات اختیاری">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_main_criteria" class="btn btn-success">
                                <i class="bi bi-plus-lg me-1"></i>
                                افزودن معیار
                            </button>
                        </form>
                    </div>
                </div>

                <!-- مدیریت معیارهای موجود -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-list-check me-2"></i>
                            مدیریت معیارهای موجود
                            <span class="badge bg-light text-dark ms-2"><?= count($main_criteria) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($main_criteria)): ?>
                        <form method="post" id="orderForm">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th width="50">ترتیب</th>
                                            <th>نام معیار</th>
                                            <th>توضیحات</th>
                                            <th width="120">عملیات</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sortable">
                                        <?php foreach ($main_criteria as $index => $criterion): ?>
                                        <tr class="criteria-item" data-id="<?= $criterion['id'] ?>">
                                            <td>
                                                <input type="hidden" name="order[<?= $criterion['id'] ?>]"
                                                    value="<?= $criterion['sort_order'] ?>">
                                                <span class="badge bg-secondary"><?= $index + 1 ?></span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($criterion['name']) ?></strong>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($criterion['description'] ?? 'بدون توضیح') ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editModal<?= $criterion['id'] ?>"
                                                        title="ویرایش">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="criteria_id"
                                                            value="<?= $criterion['id'] ?>">
                                                        <button type="submit" name="delete_criteria"
                                                            class="btn btn-outline-danger"
                                                            onclick="return confirm('آیا از حذف معیار \"
                                                            <?= htmlspecialchars($criterion['name']) ?>\" اطمینان
                                                            دارید؟')" title="حذف">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Modal ویرایش -->
                                        <div class="modal fade" id="editModal<?= $criterion['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">ویرایش معیار</h5>
                                                        <button type="button" class="btn-close"
                                                            data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">نام معیار:</label>
                                                                <input type="text" class="form-control" name="name"
                                                                    value="<?= htmlspecialchars($criterion['name']) ?>"
                                                                    required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">توضیحات:</label>
                                                                <textarea class="form-control" name="description"
                                                                    rows="3"><?= htmlspecialchars($criterion['description'] ?? '') ?></textarea>
                                                            </div>
                                                            <input type="hidden" name="criteria_id"
                                                                value="<?= $criterion['id'] ?>">
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary"
                                                                data-bs-dismiss="modal">انصراف</button>
                                                            <button type="submit" name="edit_criteria"
                                                                class="btn btn-primary">ذخیره تغییرات</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <button type="submit" name="update_criteria_order" class="btn btn-warning">
                                    <i class="bi bi-check2-circle me-1"></i>
                                    ذخیره ترتیب جدید
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-4 text-muted"></i>
                            <p class="text-muted mt-3">هیچ معیاری تعریف نشده است. اولین معیار را اضافه کنید.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // فعال کردن قابلیت drag and drop برای جدول
        var tbody = document.getElementById('sortable');
        var sortable = new Sortable(tbody, {
            handle: '.criteria-item',
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                var rows = evt.from.querySelectorAll('tr');
                rows.forEach(function(row, index) {
                    var input = row.querySelector('input[name^="order"]');
                    if (input) {
                        input.value = index + 1;
                    }
                });
            }
        });

        // نمایش پیام‌ها
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);
    });
    </script>
</body>

</html>