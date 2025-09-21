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
        $name = $_POST['name'];
        $description = $_POST['description'];
        
        // بررسی وجود ماتریس اصلی
        $stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE");
        $main_matrix = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$main_matrix) {
            $stmt = $pdo->prepare("INSERT INTO matrices (name, description, is_main) VALUES (?, ?, TRUE)");
            $stmt->execute(['ماتریکس اصلی', 'ماتریکس معیارهای اصلی']);
            $matrix_id = $pdo->lastInsertId();
        } else {
            $matrix_id = $main_matrix['id'];
        }
        
        // پیدا کردن آخرین ترتیب
        $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM criteria WHERE matrix_id = ?");
        $stmt->execute([$matrix_id]);
        $last_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_order = $last_order['max_order'] + 1;
        
        $stmt = $pdo->prepare("INSERT INTO criteria (matrix_id, name, description, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->execute([$matrix_id, $name, $description, $new_order]);
        
        // ایجاد ماتریکس زیرمعیار به صورت خودکار
        $sub_matrix_name = "ماتریکس زیرمعیارهای " . $name;
        $stmt = $pdo->prepare("INSERT INTO matrices (name, description, parent_id) VALUES (?, ?, ?)");
        $stmt->execute([$sub_matrix_name, $sub_matrix_name, $matrix_id]);
        $sub_matrix_id = $pdo->lastInsertId();
        
        header('Location: admin.php');
        exit;
        
    } elseif (isset($_POST['add_sub_criteria'])) {
        $matrix_id = $_POST['matrix_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        
        // پیدا کردن آخرین ترتیب
        $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM criteria WHERE matrix_id = ?");
        $stmt->execute([$matrix_id]);
        $last_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_order = $last_order['max_order'] + 1;
        
        $stmt = $pdo->prepare("INSERT INTO criteria (matrix_id, name, description, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->execute([$matrix_id, $name, $description, $new_order]);
        header('Location: admin.php');
        exit;
        
    } elseif (isset($_POST['update_criteria_order'])) {
        $orders = $_POST['order'];
        foreach ($orders as $criteria_id => $order) {
            $stmt = $pdo->prepare("UPDATE criteria SET sort_order = ? WHERE id = ?");
            $stmt->execute([$order, $criteria_id]);
        }
        header('Location: admin.php');
        exit;
        
    } elseif (isset($_REQUEST['delete_criteria'])) {
        $criteria_id = $_POST['criteria_id'];
        
        // ابتدا اطلاعات معیار را می‌گیریم
        $stmt = $pdo->prepare("SELECT matrix_id FROM criteria WHERE id = ?");
        $stmt->execute([$criteria_id]);
        $criteria = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($criteria) {
            $matrix_id = $criteria['matrix_id'];
            
            // بررسی می‌کنیم که آیا این معیار اصلی است یا نه
            $stmt = $pdo->prepare("SELECT is_main FROM matrices WHERE id = ?");
            $stmt->execute([$matrix_id]);
            $matrix = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($matrix && $matrix['is_main']) {
                // این یک معیار اصلی است، پس ماتریکس زیرمعیار مربوطه را نیز حذف می‌کنیم
                
                // ابتدا ماتریکس زیرمعیار را پیدا می‌کنیم
                $stmt = $pdo->prepare("SELECT id FROM matrices WHERE parent_id = ?");
                $stmt->execute([$matrix_id]);
                $sub_matrix = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sub_matrix) {
                    // حذف مقایسه‌های مربوط به ماتریکس زیرمعیار
                    $stmt = $pdo->prepare("DELETE FROM comparisons WHERE matrix_id = ?");
                    $stmt->execute([$sub_matrix['id']]);
                    
                    // حذف معیارهای ماتریکس زیرمعیار
                    $stmt = $pdo->prepare("DELETE FROM criteria WHERE matrix_id = ?");
                    $stmt->execute([$sub_matrix['id']]);
                    
                    // حذف خود ماتریکس زیرمعیار
                    $stmt = $pdo->prepare("DELETE FROM matrices WHERE id = ?");
                    $stmt->execute([$sub_matrix['id']]);
                }
            }
            
            // حذف مقایسه‌های مربوط به این معیار
            $stmt = $pdo->prepare("DELETE FROM comparisons WHERE criterion1_id = ? OR criterion2_id = ?");
            $stmt->execute([$criteria_id, $criteria_id]);
            
            // حذف خود معیار
            $stmt = $pdo->prepare("DELETE FROM criteria WHERE id = ?");
            $stmt->execute([$criteria_id]);
            
            // اگر معیار اصلی بود، ماتریکس اصلی را بررسی می‌کنیم
            if ($matrix && $matrix['is_main']) {
                // بررسی می‌کنیم که آیا ماتریکس اصلی دیگر معیاری دارد یا نه
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM criteria WHERE matrix_id = ?");
                $stmt->execute([$matrix_id]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($count['count'] == 0) {
                    // اگر ماتریکس اصلی دیگر معیاری ندارد، آن را نیز حذف می‌کنیم
                    $stmt = $pdo->prepare("DELETE FROM matrices WHERE id = ?");
                    $stmt->execute([$matrix_id]);
                }
            }
        }
        
        header('Location: admin.php');
        exit;
    } elseif (isset($_POST['edit_criteria'])) {
        $criteria_id = $_POST['criteria_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        
        // ابتدا معیار را ویرایش می‌کنیم
        $stmt = $pdo->prepare("UPDATE criteria SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $criteria_id]);
        
        // اگر معیار اصلی است، نام ماتریکس زیرمعیار مربوطه را نیز به روز می‌کنیم
        $stmt = $pdo->prepare("SELECT matrix_id FROM criteria WHERE id = ?");
        $stmt->execute([$criteria_id]);
        $criteria = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($criteria) {
            $stmt = $pdo->prepare("SELECT is_main FROM matrices WHERE id = ?");
            $stmt->execute([$criteria['matrix_id']]);
            $matrix = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($matrix && $matrix['is_main']) {
                // این یک معیار اصلی است، پس ماتریکس زیرمعیار مربوطه را پیدا و به روز می‌کنیم
                $stmt = $pdo->prepare("SELECT id FROM matrices WHERE parent_id = ?");
                $stmt->execute([$criteria['matrix_id']]);
                $sub_matrix = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sub_matrix) {
                    $new_sub_matrix_name = "ماتریکس زیرمعیارهای " . $name;
                    $stmt = $pdo->prepare("UPDATE matrices SET name = ? WHERE id = ?");
                    $stmt->execute([$new_sub_matrix_name, $sub_matrix['id']]);
                }
            }
        }
        
        header('Location: admin.php');
        exit;
    } 
}

// دریافت ماتریس‌ها و معیارها
$main_matrix_stmt = $pdo->query("SELECT * FROM matrices WHERE is_main = TRUE AND active = TRUE");
$main_matrix = $main_matrix_stmt->fetch(PDO::FETCH_ASSOC);

$main_criteria = [];
if ($main_matrix) {
    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? AND active = TRUE ORDER BY sort_order");
    $stmt->execute([$main_matrix['id']]);
    $main_criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sub_matrices_stmt = $pdo->query("SELECT m.* FROM matrices m WHERE m.is_main = FALSE AND m.active = TRUE ORDER BY m.id");
$sub_matrices = $sub_matrices_stmt->fetchAll(PDO::FETCH_ASSOC);

$sub_criteria = [];
foreach ($sub_matrices as $matrix) {
    $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ? AND active = TRUE ORDER BY sort_order");
    $stmt->execute([$matrix['id']]);
    $sub_criteria[$matrix['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .sortable-ghost {
        opacity: 0.5;
    }

    .criteria-item {
        cursor: move;
    }

    .orange-text {
        color: #fd7e14;
        font-weight: bold;
    }
    </style>
</head>

<body>
    <!-- در بخش منوی ناوبری admin.php -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">پنل مدیریت</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">تنظیمات سیستم</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_results.php">نتایج کاربران</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="calculate_average_weights.php">محاسبه میانگین وزن‌ها</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">خروج</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1 class="text-center mb-4">پنل مدیریت سیستم وزن‌دهی ماتریکس‌ها</h1>

        <!-- فرم افزودن معیار اصلی -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5>افزودن معیار اصلی</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label for="name" class="form-label">نام معیار:</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">توضیحات:</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <button type="submit" name="add_main_criteria" class="btn btn-primary">افزودن معیار</button>
                </form>

                <!-- نمایش و مدیریت معیارهای اصلی موجود -->
                <?php if (!empty($main_criteria)): ?>
                <div class="mt-4">
                    <h6>مدیریت معیارهای اصلی:</h6>
                    <form method="post" id="orderForm">
                        <ul class="list-group" id="sortable">
                            <?php foreach ($main_criteria as $criterion): ?>
                            <li class="list-group-item criteria-item d-flex justify-content-between align-items-center"
                                data-id="<?= $criterion['id'] ?>">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-grip-vertical me-2"></i>
                                    <span><?= htmlspecialchars($criterion['name']) ?></span>
                                    <small
                                        class="text-muted ms-2"><?= htmlspecialchars($criterion['description']) ?></small>
                                </div>
                                <div>
                                    <input type="hidden" name="order[<?= $criterion['id'] ?>]"
                                        value="<?= $criterion['sort_order'] ?>" class="order-input">
                                    <!-- <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                        data-bs-toggle="modal" data-bs-target="#editModal<?= $criterion['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button> -->
                                    <button type="submit" name="delete_criteria" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('آیا از حذف این معیار اطمینان دارید؟')">
                                        <i class="bi bi-trash"></i>
                                        <input type="hidden" name="criteria_id" value="<?= $criterion['id'] ?>">
                                    </button>
                                </div>
                            </li>

                            <!-- Modal برای ویرایش -->
                            <div class="modal fade" id="editModal<?= $criterion['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">ویرایش معیار</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="post">
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label for="name<?= $criterion['id'] ?>" class="form-label">نام
                                                        معیار:</label>
                                                    <input type="text" class="form-control"
                                                        id="name<?= $criterion['id'] ?>" name="name"
                                                        value="<?= htmlspecialchars($criterion['name']) ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="description<?= $criterion['id'] ?>"
                                                        class="form-label">توضیحات:</label>
                                                    <textarea class="form-control"
                                                        id="description<?= $criterion['id'] ?>" name="description"
                                                        rows="2"><?= htmlspecialchars($criterion['description']) ?></textarea>
                                                </div>
                                                <input type="hidden" name="criteria_id" value="<?= $criterion['id'] ?>">
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary"
                                                    data-bs-dismiss="modal">انصراف</button>
                                                <button type="submit" name="edit_criteria" class="btn btn-primary">ذخیره
                                                    تغییرات</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </ul>
                        <!-- <div class="mt-3">
                            <form action="" method="post">
                                <button type="submit" name="update_criteria_order" class="btn btn-success">ذخیره ترتیب
                                    جدید</button>

                            </form>
                        </div> -->
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- فرم افزودن معیار به ماتریکس زیرمعیار -->
        <?php if (!empty($sub_matrices)): ?>
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5>افزودن معیار به ماتریکس زیرمعیار</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label for="matrix_id" class="form-label">انتخاب ماتریکس:</label>
                        <select class="form-select" id="matrix_id" name="matrix_id" required>
                            <option value="">-- انتخاب ماتریکس --</option>
                            <?php foreach ($sub_matrices as $matrix): ?>
                            <option value="<?= $matrix['id'] ?>"><?= htmlspecialchars($matrix['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">نام معیار:</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">توضیحات:</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <button type="submit" name="add_sub_criteria" class="btn btn-success">افزودن معیار</button>
                </form>

                <!-- نمایش معیارهای زیرمعیار موجود -->
                <?php if (!empty($sub_criteria)): ?>
                <div class="mt-4">
                    <h6>معیارهای زیرمعیار موجود:</h6>
                    <?php foreach ($sub_matrices as $matrix): ?>
                    <?php if (!empty($sub_criteria[$matrix['id']])): ?>
                    <div class="card mt-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><?= htmlspecialchars($matrix['name']) ?>:</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-group">
                                <?php foreach ($sub_criteria[$matrix['id']] as $criterion): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= htmlspecialchars($criterion['name']) ?>
                                    <small><?= htmlspecialchars($criterion['description']) ?></small>
                                    <form method="post">
                                        <div>
                                            <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editSubModal<?= $criterion['id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="submit" name="delete_criteria"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('آیا از حذف این معیار اطمینان دارید؟')">
                                                <i class="bi bi-trash"></i>
                                                <input type="hidden" name="criteria_id" value="<?= $criterion['id'] ?>">
                                            </button>
                                        </div>
                                    </form>
                                </li>

                                <!-- Modal برای ویرایش زیرمعیار -->
                                <div class="modal fade" id="editSubModal<?= $criterion['id'] ?>" tabindex="-1">
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
                                                        <label for="name<?= $criterion['id'] ?>" class="form-label">نام
                                                            معیار:</label>
                                                        <input type="text" class="form-control"
                                                            id="name<?= $criterion['id'] ?>" name="name"
                                                            value="<?= htmlspecialchars($criterion['name']) ?>"
                                                            required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="description<?= $criterion['id'] ?>"
                                                            class="form-label">توضیحات:</label>
                                                        <textarea class="form-control"
                                                            id="description<?= $criterion['id'] ?>" name="description"
                                                            rows="2"><?= htmlspecialchars($criterion['description']) ?></textarea>
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
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // فعال کردن قابلیت drag and drop
        var el = document.getElementById('sortable');
        var sortable = new Sortable(el, {
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                var items = evt.from.children;
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    var input = item.querySelector('.order-input');
                    if (input) {
                        input.value = i;
                    }
                }
            }
        });
    });
    </script>
</body>

</html>