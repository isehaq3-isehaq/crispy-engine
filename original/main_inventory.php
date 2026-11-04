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
                    $stmt = $pdo->prepare("UPDATE inventory SET item_name = ?, inventory_type = ?, main_category = ?, sub_category = ?, quantity = ?, unit = ?, min_limit = ?, avg_unit_cost = ? WHERE id = ? AND branch_id = ?");
                    $stmt->execute([$item_name, $inventory_type, $main_category, $sub_category, $quantity, $unit, $min_limit, $unit_cost, $item_id, $branch_id]);
                    
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($global_company_name_ar); ?> - جرد ومخازن (<?php echo htmlspecialchars($current_branch_name); ?>)</title>
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
                    <h1 class="logo-text">جرد وإدارة المستودعات الفرعية</h1>
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

        <!-- 👑 لوحة التحكم العلوية بالمدخلات وتوريد المواد بـ الفرع -->
        <div class="forms-dashboard-grid">
            <!-- الشاشة أ: توريد مادة أولية جديدة -->
            <div class="section-card">
                <h2 class="section-title"><i class="bi bi-box-seam"></i> توريد وتسجيل بند مخزني لفرع (<?php echo htmlspecialchars($current_branch_name); ?>)</h2>
                <form method="POST" action="main_inventory.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="add_inventory_item" value="1">
                    <div class="form-group">
                        <label>اسم المادة (خام أو خلطة مستهدفة)</label>
                        <input type="text" name="item_name" placeholder="مثال: شاي سرنديب، خلطة الكرك المعبأة" required>
                    </div>
                    <div class="form-group">
                        <label>المستودع الموطن بالفرع</label>
                        <select name="inventory_type" required>
                            <option value="main_hub">1. المخزن الرئيسي للفرع (المغلق)</option>
                            <option value="kitchen_fluid">2. مخزن المطبخ والتحضير (السوائل والطهي)</option>
                            <option value="pre_made_chilled">3. مخزن الخلطات الوسيطة والخفايف (الثلاجة)</option>
                            <option value="ready_unbaked">4. مخزن الأصناف الجاهزة وغير المطهية للبيع</option>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="form-group">
                            <label>التصنيف الطبيعي</label>
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
                            <label>الكمية الحالية</label>
                            <input type="number" step="0.001" min="0" name="quantity" placeholder="0.000" required>
                        </div>
                        <div class="form-group">
                            <label>الوحدة</label>
                            <input type="text" name="unit" placeholder="كيلو، لتر، حبة" required>
                        </div>
                        <div class="form-group">
                            <label>حد الأمان</label>
                            <input type="number" step="0.001" min="0" name="min_limit" value="5.000">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:8px;">
                        <label>تكلفة الوحدة الواحدة (<?php echo get_omr_symbol(); ?>) - اختياري، لأغراض الحساب الداخلي للربح</label>
                        <input type="number" step="0.00001" min="0" name="initial_unit_cost" placeholder="0.00000">
                    </div>
                    <button type="submit" class="btn-submit"><i class="bi bi-plus-circle-fill"></i> تسجيل المادة بمخزن الفرع</button>
                </form>
            </div>

            <!-- الشاشة ب: ربط مكونات "خلطة مخزنية" (BOM) -->
            <div class="section-card">
                <h2 class="section-title"><i class="bi bi-diagram-3-fill"></i> هيكلة وتركيب "الخلطات المخزنية" (BOM)</h2>
                <form method="POST" action="main_inventory.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action_save_inventory_recipe" value="1">
                    <div class="form-group">
                        <label>اختر صنف الخلطة الرئيسي المراد تشييد تركيبته</label>
                        <select name="parent_inventory_id" required>
                            <option value="">-- اضغط لاختيار الخلطة المستهدفة --</option>
                            <?php foreach ($inventory_items as $inv): ?>
                                <option value="<?php echo htmlspecialchars($inv['id']); ?>">📦 <?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo htmlspecialchars($inv['unit']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label style="font-size:12px; font-weight:700; color:var(--text-dark); display:block; margin-bottom:6px;">المكونات والمواد الخام المستهلكة لإنتاج وحدة واحدة منها:</label>
                    <div id="dynamicInventoryRecipeRowsContainer"></div>

                    <button type="button" class="btn-add-row" onclick="addNewAddonIngredientRowToForm()"><i class="bi bi-plus-circle-fill"></i> ➕ إضافة مادة خام للخلطة</button>
                    <button type="submit" class="btn-submit" style="margin-top:12px; background-color:var(--success-green);"><i class="bi bi-shield-lock-fill"></i> اعتماد وحفظ بنية الخلطة المخزنية</button>
                </form>
            </div>
        </div>

        <!-- لوحة جرد العناصر المتاحة بمخزن الفرع -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-journal-check"></i> لوحة جرد وتصفية مخازن الفرع التفاعلية لليوم</h2>
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" id="inventorySearchInput" onkeyup="onSearchOrTabChange()" placeholder="ابحث عن صنف مخزني بـ الفرع...">
            </div>
            <div class="inventory-tabs">
                <button class="tab-btn active" id="tab-all" onclick="filterTab('all')">⭐ الكل</button>
                <button class="tab-btn" id="tab-main_hub" onclick="filterTab('main_hub')">📦 1. المخزن الرئيسي</button>
                <button class="tab-btn" id="tab-kitchen_fluid" onclick="filterTab('kitchen_fluid')">☕ 2. المطبخ</button>
                <button class="tab-btn" id="tab-pre_made_chilled" onclick="filterTab('pre_made_chilled')">🥟 3. الخلطات والثلاجة</button>
                <button class="tab-btn" id="tab-ready_unbaked" onclick="filterTab('ready_unbaked')">🛍️ 4. الجاهز للبيع</button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>اسم المادة / المنتج السجلي</th>
                            <th>موطن المستودع الحالي</th>
                            <th>التصنيف المادي</th>
                            <th>الكمية المتوفرة بـ الفرع</th>
                            <th>تكلفة الوحدة / القيمة الإجمالية</th>
                            <th>حالة الأمان والطلب</th>
                            <th style="width: 50px; text-align:center;">تعديل</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <?php if (empty($inventory_items)): ?>
                            <tr class="no-data-row"><td colspan="7" style="text-align:center; color:var(--text-muted);">لا توجد بنود مخزنية مسجلة حالياً بهذا الفرع.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventory_items as $item): 
                                $unit_cost = floatval($item['avg_unit_cost'] ?? 0);
                                $total_value = $unit_cost * floatval($item['quantity']);
                            ?>
                                <tr class="item-row cat-<?php echo htmlspecialchars($item['inventory_type']); ?>">
                                    <td class="item-name-cell"><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                    <td>
                                        <?php 
                                        if($item['inventory_type'] === 'main_hub') echo '<span style="color:#4B5563; font-weight:700;">📦 الرئيسي للفرع</span>';
                                        elseif($item['inventory_type'] === 'kitchen_fluid') echo '<span style="color:var(--primary-red); font-weight:700;">☕ المطبخ والتحضير</span>';
                                        elseif($item['inventory_type'] === 'pre_made_chilled') echo '<span style="color:var(--warning-orange); font-weight:700;">🥟 الخفائف والخلطات</span>';
                                        else echo '<span style="color:var(--success-green); font-weight:700;">🛍️ الجاهز للبيع</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <small class="badge-sub">
                                            <?php 
                                            if($item['main_category'] === 'food') echo 'مواد غذائية';
                                            elseif($item['main_category'] === 'packaging') echo 'أوعية وتغليف';
                                            elseif($item['main_category'] === 'ready_to_sell') echo 'جاهز للبيع';
                                            else echo 'مشتريات خارجية';
                                            ?>
                                        </small>
                                    </td>
                                    <td><strong><?php echo number_format($item['quantity'], 3) . ' ' . htmlspecialchars($item['unit']); ?></strong></td>
                                    <td style="font-size:11px;">
                                        <div style="color:#666;"><?php echo number_format($unit_cost, 5); ?> / <?php echo htmlspecialchars($item['unit']); ?></div>
                                        <div style="font-weight:700; color:var(--success-green);"><?php echo get_omr_symbol(); ?> <?php echo number_format($total_value, 3); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($item['quantity'] <= $item['min_limit']): ?>
                                            <span class="badge-warning">منخفض</span>
                                        <?php else: ?>
                                            <span class="badge-success">آمن</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn-edit-trigger" data-item='<?php echo htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>' onclick="openEditModalFromAttr(this)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
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
                <label>تغيير ونقل موطن المستودع بالفرع</label>
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
                    <label>الكمية المتوفرة حالياً</label>
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
const itemsPerPage = 10;

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
        const matchesTab = (currentActiveTab === 'all' || row.classList.contains('cat-' + currentActiveTab));
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
    document.getElementById('tab-' + category).classList.add('active');
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
window.addEventListener('click', function(e) { if (e.target === document.getElementById('editModalOverlay')) { closeEditModal(); } });
</script>
</body>
</html>