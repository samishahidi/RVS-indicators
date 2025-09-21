<?php
require_once 'db_config.php';

// ماتریس مقایسات زوجی را ایجاد می‌کند
function createPairwiseMatrix($criteria, $comparisons) {
    $n = count($criteria);
    $matrix = array();
    
    // مقداردهی اولیه ماتریس
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i == $j) {
                $matrix[$i][$j] = 1;
            } else {
                $matrix[$i][$j] = 0;
            }
        }
    }
    
    // پر کردن ماتریس با مقادیر وارد شده توسط کاربر
    foreach ($comparisons as $comp) {
        $criterion1_id = $comp['criterion1_id'];
        $criterion2_id = $comp['criterion2_id'];
        $value = $comp['value'];
        
        // یافتن اندیس معیارها
        $index1 = -1;
        $index2 = -1;
        
        foreach ($criteria as $idx => $criterion) {
            if ($criterion['id'] == $criterion1_id) $index1 = $idx;
            if ($criterion['id'] == $criterion2_id) $index2 = $idx;
        }
        
        if ($index1 >= 0 && $index2 >= 0) {
            $matrix[$index1][$index2] = $value;
            $matrix[$index2][$index1] = 1 / $value;
        }
    }
    
    return $matrix;
}

// مجموع هر ستون ماتریس را محاسبه می‌کند
function calculateColumnSums($matrix) {
    $n = count($matrix);
    $column_sums = array_fill(0, $n, 0);
    
    for ($j = 0; $j < $n; $j++) {
        for ($i = 0; $i < $n; $i++) {
            $column_sums[$j] += $matrix[$i][$j];
        }
    }
    
    return $column_sums;
}

// ماتریس را نرمالایز می‌کند
function normalizeMatrix($matrix, $column_sums) {
    $n = count($matrix);
    $normalized_matrix = array();
    
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $normalized_matrix[$i][$j] = $matrix[$i][$j] / $column_sums[$j];
        }
    }
    
    return $normalized_matrix;
}

// میانگین هر سطر (وزن نهایی) را محاسبه می‌کند
function calculateWeights($normalized_matrix) {
    $n = count($normalized_matrix);
    $weights = array();
    
    for ($i = 0; $i < $n; $i++) {
        $row_sum = 0;
        for ($j = 0; $j < $n; $j++) {
            $row_sum += $normalized_matrix[$i][$j];
        }
        $weights[$i] = $row_sum / $n;
    }
    
    return $weights;
}

// بردار مجموع وزنی را محاسبه می‌کند
function calculateWeightedSumVector($matrix, $weights) {
    $n = count($matrix);
    $weighted_sum = array_fill(0, $n, 0);
    
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $weighted_sum[$i] += $matrix[$i][$j] * $weights[$j];
        }
    }
    
    return $weighted_sum;
}

// بردار سازگاری را محاسبه می‌کند
function calculateConsistencyVector($weighted_sum, $weights) {
    $n = count($weighted_sum);
    $consistency_vector = array();
    
    for ($i = 0; $i < $n; $i++) {
        $consistency_vector[$i] = $weighted_sum[$i] / $weights[$i];
    }
    
    return $consistency_vector;
}

// مقدار لامبدا ماکزیمم را محاسبه می‌کند
function calculateLambdaMax($consistency_vector) {
    $n = count($consistency_vector);
    return array_sum($consistency_vector) / $n;
}

// شاخص سازگاری را محاسبه می‌کند
function calculateConsistencyIndex($lambda_max, $n) {
    return ($lambda_max - $n) / ($n - 1);
}

// نرخ سازگاری را محاسبه می‌کند
function calculateConsistencyRatio($ci, $n) {
    // مقادیر شاخص تصادفی (RI) بر اساس اندازه ماتریس
    $ri_values = array(
        1 => 0,
        2 => 0,
        3 => 0.58,
        4 => 0.90,
        5 => 1.12,
        6 => 1.24,
        7 => 1.32,
        8 => 1.41,
        9 => 1.45,
        10 => 1.49,
        11 => 1.51,
        12 => 1.48,
        13 => 1.56,
        14 => 1.57,
        15 => 1.59
    );
    
    $ri = isset($ri_values[$n]) ? $ri_values[$n] : 1.6;
    return $ci / $ri;
}

// محاسبه کامل نرخ ناسازگاری برای یک ماتریس
function calculateConsistencyForMatrix($criteria, $comparisons) {
    $n = count($criteria);
    
    if ($n <= 1) {
        return array(
            'consistency_ratio' => 0,
            'weights' => array(),
            'is_consistent' => true
        );
    }
    
    // ایجاد ماتریس مقایسات زوجی
    $matrix = createPairwiseMatrix($criteria, $comparisons);
    
    // محاسبه مجموع ستون‌ها
    $column_sums = calculateColumnSums($matrix);
    
    // نرمالایز کردن ماتریس
    $normalized_matrix = normalizeMatrix($matrix, $column_sums);
    
    // محاسبه وزن‌ها
    $weights = calculateWeights($normalized_matrix);
    
    // محاسبه بردار مجموع وزنی
    $weighted_sum = calculateWeightedSumVector($matrix, $weights);
    
    // محاسبه بردار سازگاری
    $consistency_vector = calculateConsistencyVector($weighted_sum, $weights);
    
    // محاسبه لامبدا ماکزیمم
    $lambda_max = calculateLambdaMax($consistency_vector);
    
    // محاسبه شاخص سازگاری
    $ci = calculateConsistencyIndex($lambda_max, $n);
    
    // محاسبه نرخ سازگاری
    $cr = calculateConsistencyRatio($ci, $n);
    
    return array(
        'consistency_ratio' => $cr,
        'weights' => $weights,
        'is_consistent' => $cr <= 0.1,
        'lambda_max' => $lambda_max,
        'consistency_index' => $ci
    );
}

// بررسی سازگاری تمام ماتریس‌های یک کاربر
function checkUserConsistency($user_id, $pdo) {
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

    $results = array();
    $all_consistent = true;
    
    foreach ($matrices as $matrix) {
        // دریافت معیارهای ماتریس
        $stmt = $pdo->prepare("SELECT * FROM criteria WHERE matrix_id = ?");
        $stmt->execute([$matrix['id']]);
        $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // دریافت مقایسه‌های کاربر برای این ماتریس
        $stmt = $pdo->prepare("SELECT * FROM comparisons WHERE user_id = ? AND matrix_id = ?");
        $stmt->execute([$user_id, $matrix['id']]);
        $comparisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($criteria) > 1 && count($comparisons) > 0) {
            $result = calculateConsistencyForMatrix($criteria, $comparisons);
            $result['matrix_id'] = $matrix['id'];
            $result['matrix_name'] = $matrix['name'];
            $results[] = $result;
            
            if (!$result['is_consistent']) {
                $all_consistent = false;
            }
            
            // به روزرسانی نرخ ناسازگاری در دیتابیس
            $update_stmt = $pdo->prepare("UPDATE comparisons SET consistency_ratio = ? WHERE user_id = ? AND matrix_id = ?");
            $update_stmt->execute([$result['consistency_ratio'], $user_id, $matrix['id']]);
        }
    }
    
    // به روزرسانی وضعیت سازگاری کاربر
    $update_user_stmt = $pdo->prepare("UPDATE users SET consistency_checked = TRUE WHERE id = ?");
    $update_user_stmt->execute([$user_id]);
    
    return array(
        'results' => $results,
        'all_consistent' => $all_consistent
    );
}
?>