<?php
// main_inventory.php
// إدارة وجرد المخزن الرئيسي والمستودعات متعددة الفروع - محصن أمنياً (CSRF/IDOR/Validation Protected) - شاي سيّار

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 حماية الصلاحيات: التحقق من صلاحية المدير التنفيذي (executive) أو مدير الفرع حصرياً
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['executive', 'branch_manager'])) {
    header("Location: login.php");
    exit;
}

// 🛡️ إنشاء رمز حماية CSRF لمنع الهجمات وتزوير الطلبات
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// استدعاء ملف الاتصال الآمن بقاعدة البيانات
if (file_exists('db.php')) {
    include_once 'db.php';
}
include_once __DIR__ . '/inventory_lib.php';

$user_role = $_SESSION['role'] ?? 'branch_manager';
$actual_branch_id = intval($_SESSION['branch_id'] ?? 1);

// 🎯 [معالجة التبديل الديناميكي للفرع عبر POST محمي بـ CSRF]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_branch_id']) && $user_role === 'executive') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('خطأ أمني: طلب غير صالح (CSRF).');
    }
    $sw_val = $_POST['switch_branch_id'];
    if (ctype_digit((string)$sw_val) && isset($pdo)) {
        $stmtChk = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
        $stmtChk->execute([$sw_val]);
        if ($stmtChk->fetchColumn()) {
            $_SESSION['selected_inventory_branch_id'] = $sw_val;
        }
    }
    header("Location: main_inventory.php");
    exit;
}

$branch_id = ($user_role === 'executive' && isset($_SESSION['selected_inventory_branch_id'])) 
    ? intval($_SESSION['selected_inventory_branch_id']) 
    : $actual_branch_id;

$success_message = '';
$error_message = '';
$inventory_items = [];

// 👑 [دالة حقن رمز الريال العُماني القياسي]
if (!function_exists('get_omr_symbol')) {
    function get_omr_symbol() {
        $base_path = 'Ro/RO.svg';
        if (file_exists($base_path)) {
            $version = filemtime($base_path);
            return '<img src="' . $base_path . '?v=' . $version . '" class="omr-official-svg-icon" alt="﷼" style="height: 1.35em; width: auto; vertical-align: middle; margin-left: 4px; display: inline-block; background: transparent !important; background-color: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; box-shadow: none !important; filter: invert(21%) sepia(85%) saturate(3501%) hue-rotate(346deg) brightness(85%) contrast(85%) !important;">';
        } else {
            return '<span style="font-weight:bold; font-size:12px; margin-left:3px; color:#B82929; background: transparent !important; border: none !important;">ريال</span>';
        }
    }
}

// ⚡ تبويب واستدعاء رسائل الفلاش المؤمّنة (PRG)
if (isset($_SESSION['success_flash'])) {
    $success_message = htmlspecialchars($_SESSION['success_flash']);
    unset($_SESSION['success_flash']);
}
if (isset($_SESSION['error_flash'])) {
    $error_message = htmlspecialchars($_SESSION['error_flash']);
    unset($_SESSION['error_flash']);
}

// 🏗️ تأسيس وتأمين الجداول 
if (isset($pdo)) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL DEFAULT 1,
            item_name VARCHAR(150) NOT NULL,
            inventory_type VARCHAR(50) NOT NULL DEFAULT 'main_hub',
            main_category VARCHAR(50) NOT NULL,
            sub_category VARCHAR(50) DEFAULT '',
            quantity DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            unit VARCHAR(30) NOT NULL, 
            min_limit DECIMAL(10,3) NOT NULL DEFAULT 5.000, 
            avg_unit_cost DECIMAL(12,5) NOT NULL DEFAULT 0.00000,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 🛡️ [تتبع تكلفة الوحدة] إضافة العمود لقواعد البيانات القديمة الموجودة مسبقاً بدون هذا الحقل
        $checkCostCol = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'avg_unit_cost'")->fetch();
        if (!$checkCostCol) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN avg_unit_cost DECIMAL(12,5) NOT NULL DEFAULT 0.00000");
        }
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_inventory_id INT NOT NULL, 
            child_inventory_id INT NOT NULL,  
            required_qty DECIMAL(10,3) NOT NULL DEFAULT 1.000, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_inv_recipe_pair (parent_inventory_id, child_inventory_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 🔁 سجل حركات المخزون الموحد (صرف/هالك/جرد/تعديل)
        inv_ensure_movements_table($pdo);

    } catch (\PDOException $e) {
        error_log('Inventory DB Init Error: ' . $e->getMessage());
    }
}

// 🛡️ معالجة جميع الطلبات الخاصة بالـ POST والتأكد من الـ CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_flash'] = 'خطأ أمني: طلب غير صالح (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.';
        header("Location: main_inventory.php");
        exit;
    }

    // 🛡️ قوائم الفلترة (Whitelist)
    $allowed_inventory_types = ['main_hub', 'kitchen_fluid', 'pre_made_chilled', 'ready_unbaked'];
    $allowed_main_categories = ['food', 'packaging', 'ready_to_sell', 'external'];
    $allowed_sub_categories  = ['dry', 'pre_mixed', 'chilled', ''];

    // 🛠️ المحرك الأول: معالجة توريد وتسجيل مادة جديدة
    if (isset($_POST['add_inventory_item'])) {
        $item_name      = trim($_POST['item_name'] ?? '');
        $inventory_type = $_POST['inventory_type'] ?? 'main_hub';
        $main_category  = $_POST['main_category'] ?? 'food';
        $sub_category   = ($main_category === 'food') ? ($_POST['sub_category'] ?? '') : '';
        $quantity       = floatval($_POST['quantity'] ?? 0);
        $unit           = trim($_POST['unit'] ?? '');
        $min_limit      = floatval($_POST['min_limit'] ?? 5.000);
        $initial_unit_cost = floatval($_POST['initial_unit_cost'] ?? 0);

        // 🛡️ فحص الـ Whitelist
        if (!in_array($inventory_type, $allowed_inventory_types, true)) $inventory_type = 'main_hub';
        if (!in_array($main_category, $allowed_main_categories, true)) $main_category = 'food';
        if (!in_array($sub_category, $allowed_sub_categories, true)) $sub_category = '';

        if (empty($item_name) || empty($unit)) {
            $_SESSION['error_flash'] = 'الرجاء ملء الحقول الأساسية المطلوبة لتأمين عملية التوريد.';
        } elseif ($quantity < 0 || $min_limit < 0 || $initial_unit_cost < 0) {
            $_SESSION['error_flash'] = 'خطأ: لا يمكن إدخال كميات أو حدود أمان أو تكلفة سالبة.'; // 🛡️ التنبيه بالرفض بدل التصحيح الصامت
        } else {
            try {
                if (isset($pdo)) {
                    $stmt = $pdo->prepare("INSERT INTO inventory (branch_id, item_name, inventory_type, main_category, sub_category, quantity, unit, min_limit, avg_unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$branch_id, $item_name, $inventory_type, $main_category, $sub_category, $quantity, $unit, $min_limit, $initial_unit_cost]);
                    $_SESSION['success_flash'] = 'تم توريد وتسجيل المادة بمستودع الفرع بنجاح كامل! 📦✨';
                }
            } catch (\PDOException $e) { 
                error_log("Add Inventory Error: " . $e->getMessage());
                $_SESSION['error_flash'] = 'حدث خطأ تقني أثناء حفظ المادة. يرجى المحاولة لاحقاً.'; 
            }
        }
        header("Location: main_inventory.php");
        exit;
    }

    // 🛠️ المحرك الثاني: حفظ وتركيب مكونات "الخلطة المخزنية" (محصن ضد الـ IDOR)
    if (isset($_POST['action_save_inventory_recipe'])) {
        $parent_inventory_id = intval($_POST['parent_inventory_id'] ?? 0);
        $child_inventory_ids = $_POST['recipe_child_ids'] ?? $_POST['recipe_inventory_ids'] ?? [];
        $required_qtys       = $_POST['recipe_weights'] ?? [];

        if ($parent_inventory_id > 0 && !empty($child_inventory_ids)) {
            try {
                if (isset($pdo)) {
                    $pdo->beginTransaction();

                    // 🛡️ 1. التحقق من أن المنتج الأب يتبع للفرع الحالي
                    $stmtCheckParent = $pdo->prepare("SELECT id FROM inventory WHERE id = ? AND branch_id = ?");
                    $stmtCheckParent->execute([$parent_inventory_id, $branch_id]);
                    if (!$stmtCheckParent->fetchColumn()) {
                        throw new \Exception("عملية غير مصرح بها. الصنف المرجعي لا ينتمي لمستودع فرعك.");
                    }

                    // مسح المكونات القديمة
                    $stmtDel = $pdo->prepare("DELETE FROM inventory_recipes WHERE parent_inventory_id = ?");
                    $stmtDel->execute([$parent_inventory_id]);

                    $stmtIns = $pdo->prepare("INSERT INTO inventory_recipes (parent_inventory_id, child_inventory_id, required_qty) VALUES (?, ?, ?)");
                    $stmtCheckChild = $pdo->prepare("SELECT id FROM inventory WHERE id = ? AND branch_id = ?");

                    foreach ($child_inventory_ids as $idx => $child_id) {
                        $child_id = intval($child_id);
                        $weight   = floatval($required_qtys[$idx] ?? 0);
                        
                        if ($child_id > 0 && $weight > 0 && $child_id !== $parent_inventory_id) {
                            // 🛡️ 2. التحقق من أن كل مكون فرعي يتبع للفرع الحالي (IDOR Protection)
                            $stmtCheckChild->execute([$child_id, $branch_id]);
                            if ($stmtCheckChild->fetchColumn()) {
                                $stmtIns->execute([$parent_inventory_id, $child_id, $weight]);
                            }
                        } elseif ($weight < 0) {
                            throw new \Exception("خطأ: تم اكتشاف أوزان سالبة غير صالحة للاستهلاك.");
                        }
                    }
                    $pdo->commit();
                    $_SESSION['success_flash'] = "🎯 تم ربط وحفظ مكونات الخلطة المخزنية وتحديد أوزان استهلاك المواد الخام بنجاح! 🚀";
                }
            } catch (\Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                // تصفية الأخطاء للمستخدم
                if ($e instanceof \PDOException) {
                    error_log("Save Recipe DB Error: " . $e->getMessage());
                    $_SESSION['error_flash'] = "حدث خطأ في قاعدة البيانات أثناء الحفظ.";
                } else {
                    $_SESSION['error_flash'] = $e->getMessage();
                }
            }
        } else {
            $_SESSION['error_flash'] = "يرجى تحديد عنصر الخلطة الرئيسي واختيار مكون واحد على الأقل.";
        }
        header("Location: main_inventory.php");
        exit;
    }

    // 🛠️ المحرك الثالث: التعديل الشامل للمادة (محصن ضد الـ IDOR مع تأكيد التحديث)
    if (isset($_POST['update_inventory_item'])) {
        $item_id        = intval($_POST['edit_item_id'] ?? 0);
        $item_name      = trim($_POST['edit_item_name'] ?? '');
        $inventory_type = $_POST['edit_inventory_type'] ?? 'main_hub';
        $main_category  = $_POST['edit_main_category'] ?? 'food';
        $sub_category   = ($main_category === 'food') ? ($_POST['edit_sub_category'] ?? '') : '';
        $quantity       = floatval($_POST['edit_quantity'] ?? 0);
        $unit           = trim($_POST['edit_unit'] ?? '');
        $min_limit      = floatval($_POST['edit_min_limit'] ?? 5.000);
        $unit_cost      = floatval($_POST['edit_unit_cost'] ?? 0);

        // 🛡️ فحص الـ Whitelist
        if (!in_array($inventory_type, $allowed_inventory_types, true)) $inventory_type = 'main_hub';
        if (!in_array($main_category, $allowed_main_categories, true)) $main_category = 'food';
        if (!in_array($sub_category, $allowed_sub_categories, true)) $sub_category = '';

        if (empty($item_name) || empty($unit)) {
            $_SESSION['error_flash'] = 'جميع الحقول مطلوبة لإتمام عملية التعديل.';
        } elseif ($quantity < 0 || $min_limit < 0 || $unit_cost < 0) {
            $_SESSION['error_flash'] = 'خطأ: لا يمكن إدخال كميات أو حدود أمان أو تكلفة سالبة.'; // 🛡️ التنبيه بالرفض
        } else {
            try {
                if (isset($pdo)) {
                    $pdo->beginTransaction();
                    // 📒 قراءة الرصيد السابق لتوثيق أي تعديل يدوي على الكمية في سجل الحركات
                    $stmtOld = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ? AND branch_id = ? FOR UPDATE");
                    $stmtOld->execute([$item_id, $branch_id]);
                    $old_qty = $stmtOld->fetchColumn();

                    $stmt = $pdo->prepare("UPDATE inventory SET item_name = ?, inventory_type = ?, main_category = ?, sub_category = ?, quantity = ?, unit = ?, min_limit = ?, avg_unit_cost = ? WHERE id = ? AND branch_id = ?");
                    $stmt->execute([$item_name, $inventory_type, $main_category, $sub_category, $quantity, $unit, $min_limit, $unit_cost, $item_id, $branch_id]);

                    if ($old_qty !== false && abs($quantity - floatval($old_qty)) >= 0.0005) {
                        inv_log_movement($pdo, [
                            'branch_id' => $branch_id,
                            'voucher_no' => inv_next_voucher_no($pdo, $branch_id, 'manual_edit'),
                            'movement_type' => 'manual_edit',
                            'item_id' => $item_id, 'item_name' => $item_name,
                            'from_store' => $inventory_type, 'to_store' => $inventory_type,
                            'qty' => round($quantity - floatval($old_qty), 3), 'unit' => $unit,
                            'unit_cost' => $unit_cost, 'balance_after' => $quantity,
                            'note' => 'تعديل مباشر من شاشة أرصدة المخازن',
                            'created_by' => intval($_SESSION['user_id']),
                        ]);
                    }
                    $pdo->commit();
                    
                    // 🛡️ التحقق من حدوث التعديل فعلياً
                    if ($stmt->rowCount() > 0) {
                        $_SESSION['success_flash'] = 'تم تحديث بيانات الصنف ونقله بنجاح! 💾✨';
                    } else {
                        // إما أن البيانات لم تتغير، أو الآيدي لا يخص الفرع
                        $stmtChk = $pdo->prepare("SELECT id FROM inventory WHERE id = ? AND branch_id = ?");
                        $stmtChk->execute([$item_id, $branch_id]);
                        if ($stmtChk->fetchColumn()) {
                            $_SESSION['success_flash'] = 'تم حفظ التعديلات بنجاح (لم يتم تغيير أي قيمة).';
                        } else {
                            $_SESSION['error_flash'] = 'الصنف المُراد تعديله غير موجود في مستودع هذا الفرع.';
                        }
                    }
                }
            } catch (\PDOException $e) { 
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Update Inventory Error: " . $e->getMessage());
                $_SESSION['error_flash'] = 'حدث خطأ أثناء التحديث.'; 
            }
        }
        header("Location: main_inventory.php");
        exit;
    }
}

// 📊 جلب كشوفات البيانات المغذية للشاشة بحسب الفرع المختار
$branches_list = [];
$current_branch_name = "الفرع الرئيسي";
$global_company_name_ar = "شاي سيّار";

if (isset($pdo)) {
    try {
        try {
            $branches_list = $pdo->query("SELECT id, branch_name FROM branches ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $branches_list = [['id' => 1, 'branch_name' => 'الفرع الرئيسي']];
        }

        foreach ($branches_list as $b) {
            if ($b['id'] == $branch_id) { $current_branch_name = $b['branch_name']; break; }
        }

        $stmtComp = $pdo->query("SELECT company_name_ar FROM branch_settings LIMIT 1");
        if ($stmtComp) { $c_name = $stmtComp->fetchColumn(); if ($c_name) { $global_company_name_ar = $c_name; } }

        $stmtInv = $pdo->prepare("SELECT * FROM inventory WHERE branch_id = ? ORDER BY inventory_type ASC, item_name ASC");
        $stmtInv->execute([$branch_id]);
        $inventory_items = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

    } catch (\Exception $e) {}
}

// 📈 مؤشرات لوحة المخزون
$stores_meta = inv_store_types();
$kpi = ['items' => count($inventory_items), 'value' => 0, 'low' => 0, 'out' => 0];
$store_stats = [];
foreach ($stores_meta as $k => $m) { $store_stats[$k] = ['count' => 0, 'value' => 0, 'low' => 0]; }
foreach ($inventory_items as $it) {
    $q = floatval($it['quantity']);
    $v = $q * floatval($it['avg_unit_cost'] ?? 0);
    $kpi['value'] += $v;
    $is_out = $q <= 0;
    $is_low = !$is_out && $q <= floatval($it['min_limit']);
    if ($is_out) $kpi['out']++;
    if ($is_low) $kpi['low']++;
    $t = $it['inventory_type'];
    if (!isset($store_stats[$t])) $store_stats[$t] = ['count' => 0, 'value' => 0, 'low' => 0];
    $store_stats[$t]['count']++;
    $store_stats[$t]['value'] += $v;
    if ($is_out || $is_low) $store_stats[$t]['low']++;
}
$today_moves = ['count' => 0, 'waste' => 0];
if (isset($pdo)) {
    try {
        $stmtTm = $pdo->prepare("SELECT COUNT(DISTINCT voucher_no) AS c, COALESCE(SUM(CASE WHEN movement_type = 'waste' THEN total_cost ELSE 0 END), 0) AS w FROM inventory_movements WHERE branch_id = ? AND DATE(created_at) = CURRENT_DATE()");
        $stmtTm->execute([$branch_id]);
        $tm = $stmtTm->fetch(PDO::FETCH_ASSOC);
        if ($tm) { $today_moves = ['count' => intval($tm['c']), 'waste' => floatval($tm['w'])]; }
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($global_company_name_ar); ?> - أرصدة المخازن (<?php echo htmlspecialchars($current_branch_name); ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-outer: #0E121A; --bg-main: #EFEBE3; --bg-card: #FFFFFF;
            --primary-red: #B82929; --success-green: #0F754C; --warning-orange: #D97706;
            --text-dark: #222222; --text-muted: #666666; --border-color: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Tajawal', sans-serif; }
        body { background-color: var(--bg-outer); min-height: 100vh; overflow-x: hidden; position: relative; }
        
        .main-wrapper { display: flex; width: 100%; min-height: 100vh; position: relative; }
        .sys-sidebar { position: fixed !important; top: 0; right: -240px !important; bottom: 0; width: 240px; z-index: 99999 !important; transition: right 0.4s ease; box-shadow: -5px 0 25px rgba(0,0,0,0.3); }
        body.sidebar-show .sys-sidebar { right: 0 !important; }

        .dashboard-container { background-color: var(--bg-main); flex-grow: 1; width: 100%; padding: 15px; text-align: right; transition: all 0.4s ease; position: relative; right: 0; }
        @media (min-width: 992px) { body.sidebar-show .dashboard-container { right: 240px; width: calc(100% - 240px); } .dashboard-container { padding: 25px; } }
        
        .header-zone { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(184, 41, 41, 0.15); padding-bottom: 12px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header-title-wrapper { display: inline-flex; align-items: center; gap: 10px; }
        .logo-text { color: var(--primary-red); font-size: 20px; font-weight: 700; margin: 0; }
        
        .cup-toggle-btn { width: 44px; height: 44px; display: inline-flex; justify-content: center; align-items: center; cursor: pointer; border-radius: 50%; background: rgba(184, 41, 41, 0.08); border: 1px solid rgba(184, 41, 41, 0.15); }
        .cup-toggle-btn img { width: 24px; height: 24px; object-fit: contain; }

        .section-card { background-color: var(--bg-card); border-radius: 14px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 20px; border: 1px solid var(--border-color); }
        .section-title { font-size: 13px; font-weight: 700; color: var(--primary-red); margin-bottom: 15px; display: flex; align-items: center; gap: 6px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 6px; }

        .forms-dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 20px; }
        @media (min-width: 992px) { .forms-dashboard-grid { grid-template-columns: 1fr 1fr; gap: 20px; } }

        .form-group { display: flex; flex-direction: column; gap: 5px; width: 100%; margin-bottom: 12px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-dark); }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; background-color: white; }

        .search-wrapper { position: relative; margin-bottom: 15px; display: flex; align-items: center; width: 100%; }
        .search-wrapper input { width: 100%; padding: 11px 35px 11px 12px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; }
        .search-wrapper i { position: absolute; right: 12px; color: var(--text-muted); }

        .btn-submit { background-color: var(--primary-red); color: white; border: none; padding: 11px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; width: 100%; justify-content: center; }
        .btn-add-row { background-color: var(--text-dark); color: white; border: none; padding: 8px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; margin-top: 5px; }
        
        .addon-row-item { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; background: #FAF8F4; padding: 8px; border-radius: 8px; border: 1px solid var(--border-color); flex-wrap: wrap; }
        .addon-row-item select, .addon-row-item input { padding: 8px; font-size: 12px; }

        .inventory-tabs { display: flex; gap: 6px; margin-bottom: 15px; overflow-x: auto; padding-bottom: 6px; width: 100%; }
        .tab-btn { background: #E2E8F0; border: 1px solid #CBD5E0; padding: 8px 12px; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer; color: var(--text-dark); white-space: nowrap; }
        .tab-btn.active { background-color: var(--primary-red); color: white; border-color: var(--primary-red); }

        .table-responsive { background: white; border-radius: 10px; border: 1px solid var(--border-color); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 12px; min-width: 640px; }
        th { background-color: #F1ECE4; color: var(--text-dark); padding: 12px 10px; font-weight: 700; border-bottom: 2px solid var(--border-color); }
        td { padding: 10px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
        
        .badge-sub { padding: 3px 6px; border-radius: 4px; font-weight: 700; font-size: 10px; display: inline-block; background-color: rgba(184, 41, 41, 0.08); color: var(--primary-red); }
        .badge-warning { padding: 3px 6px; border-radius: 4px; font-weight: 700; font-size: 10px; display: inline-block; background-color: #FEF3C7; color: var(--warning-orange); border: 1px solid #FDE68A; }
        .badge-success { padding: 3px 6px; border-radius: 4px; font-weight: 700; font-size: 10px; display: inline-block; background-color: #D1FAE5; color: var(--success-green); border: 1px solid #A7F3D0; }
        .btn-edit-trigger { background: rgba(184, 41, 41, 0.08); border: none; color: var(--primary-red); cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }

        .exec-branch-switcher-select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--primary-red); font-size: 12px; font-weight: bold; color: var(--primary-red); background: #FFF; outline: none; cursor: pointer; }
        .alert-box { padding: 10px 14px; border-radius: 8px; margin-bottom: 15px; font-size: 12px; text-align: center; font-weight: bold; }
        .alert-success { background-color: #D1FAE5; color: var(--success-green); border: 1px solid #A7F3D0; }
        .alert-error { background-color: #FEE2E2; color: var(--primary-red); border: 1px solid #FCA5A5; }
        
        .pagination-container { display: flex; justify-content: center; align-items: center; gap: 4px; margin-top: 15px; padding-top: 5px; flex-wrap: wrap; }
        .page-btn { background-color: #E2E8F0; border: 1px solid #CBD5E0; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; }
        .page-btn.active { background-color: var(--primary-red); color: white; border-color: var(--primary-red); }

        .sys-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(14, 18, 26, 0.6); z-index: 10000; display: none; justify-content: center; align-items: center; padding: 15px; backdrop-filter: blur(4px); }
        .sys-modal-card { background: white; width: 100%; max-width: 500px; border-radius: 16px; padding: 20px; text-align: right; border: 1px solid var(--primary-red); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid var(--primary-red); padding-bottom: 8px; }

        .quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
        @media (min-width: 900px) { .quick-actions { grid-template-columns: repeat(6, 1fr); } }
        .qa-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 12px 6px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; font-size: 12px; font-weight: 700; color: var(--text-dark); text-decoration: none; cursor: pointer; transition: all .2s; text-align: center; }
        .qa-btn i { font-size: 20px; color: var(--primary-red); }
        .qa-btn:hover { border-color: var(--primary-red); transform: translateY(-2px); box-shadow: 0 6px 14px rgba(184,41,41,.12); }
        .qa-btn.qa-primary { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }
        .qa-btn.qa-primary i { color: #fff; }

        .kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
        @media (min-width: 900px) { .kpi-grid { grid-template-columns: repeat(5, 1fr); } }
        .kpi-card { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; }
        .kpi-card > i { font-size: 20px; width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #F1ECE4; color: var(--text-dark); flex-shrink: 0; }
        .kpi-card small { display: block; font-size: 11px; color: var(--text-muted); font-weight: 700; }
        .kpi-card strong { display: block; font-size: 16px; margin-top: 2px; }
        .kpi-green > i { background: #D1FAE5; color: var(--success-green); }
        .kpi-orange > i { background: #FEF3C7; color: var(--warning-orange); }
        .kpi-red > i { background: #FEE2E2; color: var(--primary-red); }
        .kpi-blue > i { background: #DBEAFE; color: #1D4ED8; }

        .store-grid { display: grid !important; grid-template-columns: repeat(2, 1fr); gap: 8px; overflow: visible !important; }
        @media (min-width: 900px) { .store-grid { grid-template-columns: repeat(6, 1fr); } }
        .tab-btn.store-card { position: relative; display: flex; align-items: center; gap: 8px; padding: 10px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; text-align: right; white-space: normal; font-size: 12px; }
        .tab-btn.store-card.active { background: #fff; color: var(--text-dark); border: 2px solid var(--primary-red); box-shadow: 0 4px 12px rgba(184,41,41,.15); }
        .sc-icon { width: 34px; height: 34px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; background: rgba(184,41,41,.08); color: var(--primary-red); font-size: 16px; flex-shrink: 0; }
        .sc-body strong { display: block; font-size: 12px; }
        .sc-body small { display: block; font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }
        .sc-alert { position: absolute; top: -6px; left: -6px; background: var(--warning-orange); color: #fff; font-size: 10px; font-weight: 700; min-width: 20px; height: 20px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; padding: 0 5px; }

        .table-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 10px; }
        .stock-bar { width: 100%; max-width: 120px; height: 5px; background: #EDF2F7; border-radius: 4px; overflow: hidden; margin: 4px 0 2px; }
        .stock-bar span { display: block; height: 100%; border-radius: 4px; }
        .badge-danger { padding: 3px 6px; border-radius: 4px; font-weight: 700; font-size: 10px; display: inline-block; background-color: #FEE2E2; color: var(--primary-red); border: 1px solid #FCA5A5; }
        a.btn-edit-trigger { text-decoration: none; }

        .omr-official-svg-icon {
            height: 1.25em !important; width: auto !important; vertical-align: middle !important; margin-left: 3px !important; display: inline-block !important; filter: invert(21%) sepia(85%) saturate(3501%) hue-rotate(346deg) brightness(85%) contrast(85%) !important;
        }
    </style>
</head>
<body>

<div class="main-wrapper" id="appWrapper">
    <?php if (file_exists('sidebar.php')) { include 'sidebar.php'; } ?>

    <div class="dashboard-container">
        <div class="header-zone">
            <div class="header-title-wrapper">
                <div class="cup-toggle-btn" id="cupToggle" title="القائمة الجانبية">
                    <?php if (file_exists('logo_cup.png')): ?>
                        <img src="logo_cup.png" alt="الشعار">
                    <?php else: ?>
                        <i class="bi bi-cup-hot-fill" style="color:var(--primary-red); font-size:20px;"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="logo-text">أرصدة المخازن وإدارة المخزون</h1>
                    <span style="font-size:12px; color:#666; font-weight:bold;">📍 <?php echo htmlspecialchars($current_branch_name); ?></span>
                </div>
            </div>

            <!-- 🎯 [شريط التبديل المباشر بين الفروع للمدير التنفيذي محمي بـ POST و CSRF] -->
            <?php if ($user_role === 'executive'): ?>
                <form method="POST" action="main_inventory.php" style="margin:0; display:inline-flex; align-items:center; gap:8px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <span style="font-size:12px; font-weight:bold;">جرد مخازن فرع:</span>
                    <select name="switch_branch_id" class="exec-branch-switcher-select" onchange="this.form.submit()">
                        <?php foreach ($branches_list as $b): ?>
                            <option value="<?php echo htmlspecialchars($b['id']); ?>" <?php echo ($b['id'] == $branch_id) ? 'selected' : ''; ?>>
                                📍 <?php echo htmlspecialchars($b['branch_name'] ?? 'فرع #' . $b['id']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert-box alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert-box alert-error"><i class="bi bi-exclamation-octagon-fill"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- ⚡ إجراءات سريعة بترتيب سير العمل اليومي في المقهى -->
        <div class="quick-actions">
            <button type="button" class="qa-btn" onclick="openModal('addItemModal')"><i class="bi bi-plus-square-fill"></i><span>صنف جديد</span></button>
            <a href="stock_issue.php?tab=transfer" class="qa-btn qa-primary"><i class="bi bi-arrow-left-right"></i><span>إذن صرف / تحويل</span></a>
            <a href="stock_issue.php?tab=out" class="qa-btn"><i class="bi bi-trash3-fill"></i><span>هالك واستهلاك</span></a>
            <a href="stock_issue.php?tab=count" class="qa-btn"><i class="bi bi-clipboard-check-fill"></i><span>جرد فعلي</span></a>
            <button type="button" class="qa-btn" onclick="openModal('bomModal')"><i class="bi bi-diagram-3-fill"></i><span>تركيبة خلطة (BOM)</span></button>
            <a href="stock_issue.php?tab=log" class="qa-btn"><i class="bi bi-journal-text"></i><span>سجل الحركات</span></a>
        </div>

        <!-- 📊 مؤشرات المخزون -->
        <div class="kpi-grid">
            <div class="kpi-card"><i class="bi bi-boxes"></i><div><small>إجمالي الأصناف</small><strong><?php echo number_format($kpi['items']); ?></strong></div></div>
            <div class="kpi-card kpi-green"><i class="bi bi-cash-coin"></i><div><small>قيمة المخزون الحالية</small><strong><?php echo get_omr_symbol(); ?> <?php echo number_format($kpi['value'], 3); ?></strong></div></div>
            <div class="kpi-card kpi-orange" onclick="filterTab('low')" style="cursor:pointer;"><i class="bi bi-exclamation-triangle-fill"></i><div><small>منخفض (تحت حد الأمان)</small><strong><?php echo number_format($kpi['low']); ?></strong></div></div>
            <div class="kpi-card kpi-red" onclick="filterTab('low')" style="cursor:pointer;"><i class="bi bi-x-octagon-fill"></i><div><small>نافد من المخزون</small><strong><?php echo number_format($kpi['out']); ?></strong></div></div>
            <div class="kpi-card kpi-blue"><i class="bi bi-arrow-repeat"></i><div><small>سندات اليوم · هالك اليوم</small><strong><?php echo $today_moves['count']; ?> · <span style="color:var(--primary-red);"><?php echo number_format($today_moves['waste'], 3); ?></span></strong></div></div>
        </div>

        <!-- 🏬 المخازن (اضغط للتصفية) -->
        <div class="store-grid inventory-tabs">
            <button class="tab-btn store-card active" id="tab-all" onclick="filterTab('all')">
                <span class="sc-icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                <span class="sc-body"><strong>كل المخازن</strong><small><?php echo $kpi['items']; ?> صنف · <?php echo number_format($kpi['value'], 3); ?></small></span>
            </button>
            <?php foreach ($stores_meta as $sk => $sm): $st = $store_stats[$sk]; ?>
                <button class="tab-btn store-card" id="tab-<?php echo $sk; ?>" onclick="filterTab('<?php echo $sk; ?>')">
                    <span class="sc-icon" style="color:<?php echo $sm['color']; ?>; background:<?php echo $sm['color']; ?>14;"><i class="bi <?php echo $sm['icon']; ?>"></i></span>
                    <span class="sc-body"><strong><?php echo $sm['label']; ?></strong><small><?php echo $st['count']; ?> صنف · <?php echo number_format($st['value'], 3); ?></small></span>
                    <?php if ($st['low'] > 0): ?><span class="sc-alert" title="أصناف تحتاج طلب"><?php echo $st['low']; ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
            <button class="tab-btn store-card store-card-alert" id="tab-low" onclick="filterTab('low')">
                <span class="sc-icon" style="color:var(--warning-orange); background:#FEF3C7;"><i class="bi bi-cart-plus-fill"></i></span>
                <span class="sc-body"><strong>يحتاج إعادة طلب</strong><small><?php echo $kpi['low'] + $kpi['out']; ?> صنف</small></span>
            </button>
        </div>

        <!-- 📋 جدول الأرصدة -->
        <div class="section-card">
            <div class="table-toolbar">
                <h2 class="section-title" style="margin:0; border:none; padding:0;"><i class="bi bi-journal-check"></i> أرصدة الأصناف — <span id="activeTabLabel">كل المخازن</span></h2>
                <div class="search-wrapper" style="margin:0; max-width:340px;">
                    <i class="bi bi-search"></i>
                    <input type="text" id="inventorySearchInput" onkeyup="onSearchOrTabChange()" placeholder="ابحث عن صنف...">
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>الصنف</th>
                            <th>المخزن</th>
                            <th>التصنيف</th>
                            <th>الرصيد الحالي</th>
                            <th>تكلفة الوحدة / القيمة</th>
                            <th>الحالة</th>
                            <th style="width: 120px; text-align:center;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <?php if (empty($inventory_items)): ?>
                            <tr class="no-data-row"><td colspan="7" style="text-align:center; color:var(--text-muted);">لا توجد بنود مخزنية مسجلة حالياً بهذا الفرع.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventory_items as $item): 
                                $unit_cost = floatval($item['avg_unit_cost'] ?? 0);
                                $qty_now = floatval($item['quantity']);
                                $min_now = floatval($item['min_limit']);
                                $total_value = $unit_cost * $qty_now;
                                $is_out = $qty_now <= 0;
                                $is_low = !$is_out && $qty_now <= $min_now;
                                $sm = $stores_meta[$item['inventory_type']] ?? ['label' => $item['inventory_type'], 'emoji' => '📦', 'color' => '#444'];
                                $fill = $min_now > 0 ? min(100, ($qty_now / ($min_now * 3)) * 100) : 100;
                            ?>
                                <tr class="item-row cat-<?php echo htmlspecialchars($item['inventory_type']); ?>" data-alert="<?php echo ($is_out || $is_low) ? '1' : '0'; ?>">
                                    <td class="item-name-cell"><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                    <td><span style="color:<?php echo $sm['color']; ?>; font-weight:700;"><?php echo $sm['emoji'] . ' ' . htmlspecialchars($sm['label']); ?></span></td>
                                    <td><small class="badge-sub"><?php echo inv_category_label($item['main_category']); ?></small></td>
                                    <td>
                                        <strong><?php echo number_format($qty_now, 3) . ' ' . htmlspecialchars($item['unit']); ?></strong>
                                        <div class="stock-bar"><span style="width:<?php echo max(0, round($fill)); ?>%; background:<?php echo $is_out ? 'var(--primary-red)' : ($is_low ? 'var(--warning-orange)' : 'var(--success-green)'); ?>;"></span></div>
                                        <small style="color:#888;">حد الأمان: <?php echo number_format($min_now, 3); ?></small>
                                    </td>
                                    <td style="font-size:11px;">
                                        <div style="color:#666;"><?php echo number_format($unit_cost, 5); ?> / <?php echo htmlspecialchars($item['unit']); ?></div>
                                        <div style="font-weight:700; color:var(--success-green);"><?php echo get_omr_symbol(); ?> <?php echo number_format($total_value, 3); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($is_out): ?>
                                            <span class="badge-danger">نافد</span>
                                        <?php elseif ($is_low): ?>
                                            <span class="badge-warning">منخفض</span>
                                        <?php else: ?>
                                            <span class="badge-success">آمن</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center; white-space:nowrap;">
                                        <button type="button" class="btn-edit-trigger" title="تعديل بيانات الصنف" data-item='<?php echo htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>' onclick="openEditModalFromAttr(this)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <a class="btn-edit-trigger" title="كرت الصنف (سجل الحركات)" href="stock_issue.php?tab=log&item=<?php echo intval($item['id']); ?>&from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&to=<?php echo date('Y-m-d'); ?>"><i class="bi bi-card-list"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr id="noMatchRow" style="display:none;"><td colspan="7" style="text-align:center; color:var(--text-muted); padding: 20px; font-weight:bold;">لا توجد مواد مخزنية تطابق استعلام البحث.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="paginationZone"></div>
        </div>
    </div>
</div>

<!-- ➕ مودال إضافة صنف جديد -->
<div class="sys-modal-overlay" id="addItemModal">
    <div class="sys-modal-card">
        <div class="modal-header">
            <h3 style="font-size:14px; color:var(--primary-red);"><i class="bi bi-plus-square-fill"></i> تعريف صنف مخزني جديد — <?php echo htmlspecialchars($current_branch_name); ?></h3>
            <button type="button" onclick="closeModal('addItemModal')" style="background:none; border:none; font-size:22px; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="main_inventory.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="add_inventory_item" value="1">
            <div class="form-group">
                <label>اسم الصنف (مادة خام أو خلطة)</label>
                <input type="text" name="item_name" placeholder="مثال: شاي سرنديب، حليب مبخر، أكواب 8oz" required>
            </div>
            <div class="form-group">
                <label>المخزن</label>
                <select name="inventory_type" required>
                    <?php foreach ($stores_meta as $sk => $sm): ?>
                        <option value="<?php echo $sk; ?>"><?php echo $sm['emoji'] . ' ' . $sm['label']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>التصنيف</label>
                    <select name="main_category" id="mainCatSelect" required>
                        <option value="food">مواد غذائية</option>
                        <option value="packaging">أوعية وتغليف</option>
                        <option value="ready_to_sell">منتجات البيع الفوري</option>
                        <option value="external">مشتريات خارجية</option>
                    </select>
                </div>
                <div class="form-group" id="subCatGroup">
                    <label>التصنيف الفرعي</label>
                    <select name="sub_category">
                        <option value="dry">أ. المخزون الجاف</option>
                        <option value="pre_mixed">ب. خلطات معبأة</option>
                        <option value="chilled">ج. تبريد الثلاجة</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                <div class="form-group">
                    <label>الرصيد الافتتاحي</label>
                    <input type="number" step="0.001" min="0" name="quantity" placeholder="0.000" required>
                </div>
                <div class="form-group">
                    <label>الوحدة</label>
                    <input type="text" name="unit" placeholder="جرام، مل، حبة" required>
                </div>
                <div class="form-group">
                    <label>حد الأمان</label>
                    <input type="number" step="0.001" min="0" name="min_limit" value="5.000">
                </div>
            </div>
            <div class="form-group">
                <label>تكلفة الوحدة (<?php echo get_omr_symbol(); ?>) — اختياري</label>
                <input type="number" step="0.00001" min="0" name="initial_unit_cost" placeholder="0.00000">
            </div>
            <button type="submit" class="btn-submit"><i class="bi bi-plus-circle-fill"></i> حفظ الصنف</button>
            <p style="font-size:11px; color:#888; margin-top:8px;">💡 لنقل كميات بين المخازن استخدم <a href="stock_issue.php?tab=transfer">إذن الصرف</a> بدلاً من تعريف الصنف مرتين يدوياً.</p>
        </form>
    </div>
</div>

<!-- 🧪 مودال تركيبة الخلطات (BOM) -->
<div class="sys-modal-overlay" id="bomModal">
    <div class="sys-modal-card" style="max-width:620px;">
        <div class="modal-header">
            <h3 style="font-size:14px; color:var(--primary-red);"><i class="bi bi-diagram-3-fill"></i> تركيبة الخلطات المخزنية (BOM)</h3>
            <button type="button" onclick="closeModal('bomModal')" style="background:none; border:none; font-size:22px; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="main_inventory.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action_save_inventory_recipe" value="1">
            <div class="form-group">
                <label>الخلطة المراد تحديد مكوناتها</label>
                <select name="parent_inventory_id" required>
                    <option value="">-- اختر الخلطة --</option>
                    <?php foreach ($inventory_items as $inv): ?>
                        <option value="<?php echo htmlspecialchars($inv['id']); ?>">📦 <?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo htmlspecialchars($inv['unit']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label style="font-size:12px; font-weight:700; color:var(--text-dark); display:block; margin-bottom:6px;">المواد الخام المستهلكة لإنتاج وحدة واحدة:</label>
            <div id="dynamicInventoryRecipeRowsContainer"></div>
            <button type="button" class="btn-add-row" onclick="addNewAddonIngredientRowToForm()"><i class="bi bi-plus-circle-fill"></i> إضافة مادة خام</button>
            <button type="submit" class="btn-submit" style="margin-top:12px; background-color:var(--success-green);"><i class="bi bi-shield-lock-fill"></i> حفظ التركيبة</button>
            <p style="font-size:11px; color:#888; margin-top:8px;">⚠️ حفظ التركيبة يستبدل المكونات السابقة لهذه الخلطة بالكامل.</p>
        </form>
    </div>
</div>

<!-- مودال التعديل المنبثق -->
<div class="sys-modal-overlay" id="editModalOverlay">
    <div class="sys-modal-card">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size:14px;"><i class="bi bi-pencil-square"></i> تعديل بيانات ونقل الصنف</h3>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()" style="background:none; border:none; font-size:22px; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="main_inventory.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="update_inventory_item" value="1">
            <input type="hidden" name="edit_item_id" id="edit_item_id">
            
            <div class="form-group">
                <label>اسم المادة / الصنف السجلي</label>
                <input type="text" name="edit_item_name" id="edit_item_name" required>
            </div>
            <div class="form-group">
                <label>المخزن (لنقل كمية جزئية استخدم إذن الصرف)</label>
                <select name="edit_inventory_type" id="edit_inventory_type" required>
                    <option value="main_hub">1. المخزن الرئيسي للفرع (المغلق)</option>
                    <option value="kitchen_fluid">2. مخزن المطبخ والتحضير (السوائل)</option>
                    <option value="pre_made_chilled">3. مخزن الخلطات الوسيطة والخفايف</option>
                    <option value="ready_unbaked">4. مخزن الأصناف الجاهزة للبيع</option>
                </select>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>التصنيف الطبيعي</label>
                    <select name="edit_main_category" id="edit_main_category" required>
                        <option value="food">مواد غذائية</option>
                        <option value="packaging">أوعية وتغليف</option>
                        <option value="ready_to_sell">جاهز للبيع</option>
                        <option value="external">مشتريات خارجية</option>
                    </select>
                </div>
                <div class="form-group" id="editSubCatGroup">
                    <label>التصنيف الفرعي</label>
                    <select name="edit_sub_category" id="edit_sub_category">
                        <option value="dry">أ. المخزون الجاف</option>
                        <option value="pre_mixed">ب. خلطات معبأة</option>
                        <option value="chilled">ج. تبريد الثلاجة</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>الكمية (أي تغيير يُسجل كتعديل يدوي)</label>
                    <input type="number" step="0.001" min="0" name="edit_quantity" id="edit_quantity" required>
                </div>
                <div class="form-group">
                    <label>الوحدة</label>
                    <input type="text" name="edit_unit" id="edit_unit" required>
                </div>
            </div>
            <div class="form-group">
                <label>حد الأمان الأدنى (للتنبيه بالنقص)</label>
                <input type="number" step="0.001" min="0" name="edit_min_limit" id="edit_min_limit" required>
            </div>
            <div class="form-group">
                <label>تكلفة الوحدة الواحدة (<?php echo get_omr_symbol(); ?>) - لأغراض الحساب الداخلي للربح</label>
                <input type="number" step="0.00001" min="0" name="edit_unit_cost" id="edit_unit_cost" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:15px;">
                <button type="submit" class="btn-submit" style="background-color: var(--success-green); padding:10px;"><i class="bi bi-shield-check"></i> حفظ التعديل</button>
                <button type="button" class="btn-submit" style="background-color: var(--text-muted); padding:10px;" onclick="closeEditModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- قالب مخفي للاستنساخ الجافاسكربتي لصفوف الخلطة -->
<div id="hiddenInventoryLookupSelectTemplateZone" style="display: none;">
    <select name="recipe_child_ids[]" required style="padding:10px; font-size:13px; border:1px solid #CBD5E0; border-radius:6px; background:white; outline:none; max-width:100%;">
        <option value="">-- اختر مادة خام من الجرد --</option>
        <?php foreach ($inventory_items as $inv): ?>
            <option value="<?php echo htmlspecialchars($inv['id']); ?>">📦 <?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo htmlspecialchars($inv['unit']); ?>)</option>
        <?php endforeach; ?>
    </select>
</div>

<script>
let currentActiveTab = 'all';
let currentPage = 1;
const itemsPerPage = 15;

function addNewAddonIngredientRowToForm() {
    const container = document.getElementById('dynamicInventoryRecipeRowsContainer');
    const templateHtml = document.getElementById('hiddenInventoryLookupSelectTemplateZone').innerHTML;
    const rowId = Date.now() + Math.random();
    
    const row = document.createElement('div');
    row.className = 'addon-row-item';
    row.id = `recipe_row_item_idx_${rowId}`;
    row.innerHTML = `
        <div style="flex:1; min-width:200px;">${templateHtml}</div>
        <input type="number" step="0.001" min="0.001" name="recipe_weights[]" placeholder="الوزن لوحدة واحدة" style="width:120px; padding:8px; border:1px solid #CBD5E0; border-radius:6px;" required>
        <button type="button" style="background:none; border:none; color:var(--primary-red); font-size:22px; cursor:pointer;" onclick="this.parentElement.remove()">&times;</button>
    `;
    container.appendChild(row);
}

document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById('dynamicInventoryRecipeRowsContainer').children.length === 0) {
        addNewAddonIngredientRowToForm();
    }
    
    const cupToggle = document.getElementById("cupToggle");

    if (cupToggle) {
        cupToggle.addEventListener("click", function(e) {
            e.stopPropagation();
            document.body.classList.toggle("sidebar-show");
        });
    }

    document.getElementById("mainCatSelect").addEventListener("change", function() {
        document.getElementById("subCatGroup").style.display = (this.value === "food") ? "flex" : "none";
    });

    document.getElementById("edit_main_category").addEventListener("change", function() {
        document.getElementById("editSubCatGroup").style.display = (this.value === "food") ? "flex" : "none";
    });

    renderTableWithPagination();
});

function onSearchOrTabChange() { currentPage = 1; renderTableWithPagination(); }

function renderTableWithPagination() {
    const filter = document.getElementById("inventorySearchInput").value.toLowerCase();
    const rows = Array.from(document.querySelectorAll(".item-row"));
    
    const filteredRows = rows.filter(row => {
        const nameCell = row.querySelector(".item-name-cell");
        const matchesSearch = nameCell && nameCell.textContent.toLowerCase().includes(filter);
        const matchesTab = (currentActiveTab === 'all'
            || (currentActiveTab === 'low' && row.dataset.alert === '1')
            || row.classList.contains('cat-' + currentActiveTab));
        return matchesSearch && matchesTab;
    });

    const totalItems = filteredRows.length;
    const totalPages = Math.ceil(totalItems / itemsPerPage);

    rows.forEach(row => row.style.display = "none");

    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const pageRows = filteredRows.slice(startIndex, endIndex);
    
    pageRows.forEach(row => row.style.display = "table-row");

    document.getElementById("noMatchRow").style.display = (totalItems === 0 && rows.length > 0) ? "table-row" : "none";

    const paginationZone = document.getElementById("paginationZone");
    paginationZone.innerHTML = "";

    if (totalPages > 1) {
        const prevBtn = document.createElement("button");
        prevBtn.className = "page-btn"; prevBtn.innerHTML = '<i class="bi bi-chevron-right"></i>';
        prevBtn.disabled = (currentPage === 1);
        prevBtn.onclick = () => { currentPage--; renderTableWithPagination(); };
        paginationZone.appendChild(prevBtn);

        for (let i = 1; i <= totalPages; i++) {
            const numBtn = document.createElement("button");
            numBtn.className = "page-btn" + (i === currentPage ? " active" : "");
            numBtn.textContent = i;
            numBtn.onclick = () => { currentPage = i; renderTableWithPagination(); };
            paginationZone.appendChild(numBtn);
        }

        const nextBtn = document.createElement("button");
        nextBtn.className = "page-btn"; nextBtn.innerHTML = '<i class="bi bi-chevron-left"></i>';
        nextBtn.disabled = (currentPage === totalPages);
        nextBtn.onclick = () => { currentPage++; renderTableWithPagination(); };
        paginationZone.appendChild(nextBtn);
    }
}

function filterTab(category) {
    currentActiveTab = category;
    document.querySelectorAll('.tab-btn').forEach(tab => tab.classList.remove('active'));
    const tabEl = document.getElementById('tab-' + category);
    tabEl.classList.add('active');
    const lbl = document.getElementById('activeTabLabel');
    if (lbl) { const s = tabEl.querySelector('strong'); lbl.textContent = s ? s.textContent : ''; }
    onSearchOrTabChange();
}

function openEditModalFromAttr(btnEl) {
    try {
        const rawJson = btnEl.getAttribute('data-item');
        const item = JSON.parse(rawJson);
        
        document.getElementById('edit_item_id').value = item.id;
        document.getElementById('edit_item_name').value = item.item_name;
        document.getElementById('edit_inventory_type').value = item.inventory_type || 'main_hub';
        document.getElementById('edit_main_category').value = item.main_category;
        document.getElementById('edit_quantity').value = parseFloat(item.quantity).toFixed(3);
        document.getElementById('edit_unit').value = item.unit;
        document.getElementById('edit_min_limit').value = parseFloat(item.min_limit).toFixed(3);
        document.getElementById('edit_unit_cost').value = parseFloat(item.avg_unit_cost || 0).toFixed(5);
        
        const editSubCatGroup = document.getElementById('editSubCatGroup');
        if (item.main_category === 'food') {
            editSubCatGroup.style.display = 'flex';
            document.getElementById('edit_sub_category').value = item.sub_category;
        } else {
            editSubCatGroup.style.display = 'none';
        }
        document.getElementById('editModalOverlay').style.display = 'flex';
    } catch(e) {
        console.error("Failed to parse inventory item data", e);
    }
}

function closeEditModal() { document.getElementById('editModalOverlay').style.display = 'none'; }
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(e) {
    if (e.target === document.getElementById('editModalOverlay')) { closeEditModal(); }
    ['addItemModal', 'bomModal'].forEach(function(id) { if (e.target === document.getElementById(id)) { closeModal(id); } });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeEditModal(); closeModal('addItemModal'); closeModal('bomModal'); } });
</script>
</body>
</html>