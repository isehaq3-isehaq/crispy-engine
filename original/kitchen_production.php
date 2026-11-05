<?php
// تفعيل كشف الأخطاء مؤقتاً لاصطياد المشكلة
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// kitchen_production.php
// واجهة إنتاج وتصنيع الخلطات والأصناف المجهزة (Batch Production) - محصن أمنياً ومعمارياً - شاي سيّار

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 التحقق من صلاحية الدخول الإدارية للمطبخ والإنتاج
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['executive', 'branch_manager', 'kitchen_staff'])) {
    header("Location: login.php");
    exit;
}

// 🛡️ إنشاء رمز حماية CSRF لمنع الهجمات وتزوير الطلبات
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// استدعاء ملف الاتصال الآمن
if (file_exists('db.php')) {
    include_once 'db.php';
}

$user_role = $_SESSION['role'] ?? 'kitchen_staff';
$actual_branch_id = intval($_SESSION['branch_id'] ?? 1);

// 🎯 [معالجة التبديل الديناميكي للفرع الخاص بالمدير التنفيذي مع التحقق من وجوده الفعلي]
if ($user_role === 'executive' && isset($_GET['switch_branch_id'])) {
    $sw_val = $_GET['switch_branch_id'];
    if (ctype_digit((string)$sw_val) && isset($pdo)) {
        $stmtChk = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
        $stmtChk->execute([$sw_val]);
        if ($stmtChk->fetchColumn()) {
            $_SESSION['selected_production_branch_id'] = $sw_val;
        } else {
            unset($_SESSION['selected_production_branch_id']);
        }
    }
}
$branch_id = ($user_role === 'executive' && isset($_SESSION['selected_production_branch_id'])) 
    ? intval($_SESSION['selected_production_branch_id']) 
    : $actual_branch_id;

$success_message = '';
$error_message = '';

// 👑 [دالة حقن رمز الريال العُماني القياسي الموحد لشاي سيار]
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

// 🏗️ تأسيس جدول وثائق وسجلات أمر الإنتاج الداخلي للفرع
if (isset($pdo)) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_production_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL DEFAULT 1,
            produced_item_id INT NOT NULL,
            produced_qty DECIMAL(10,3) NOT NULL,
            produced_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 🛡️ فحص وإضافة عمود الفرع لجدول المخزون إن لم يكن موجوداً
        $checkInvCol = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'branch_id'")->fetch();
        if (!$checkInvCol) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN branch_id INT NOT NULL DEFAULT 1");
        }

        // 🛡️ [تتبع تكلفة الوحدة] لتوريث تكلفة المواد الخام المستهلكة إلى الصنف المُصنَّع تلقائياً
        $checkCostCol = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'avg_unit_cost'")->fetch();
        if (!$checkCostCol) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN avg_unit_cost DECIMAL(12,5) NOT NULL DEFAULT 0.00000");
        }
        
    } catch (\PDOException $e) {
        error_log("Production DB Init Error: " . $e->getMessage());
    }
}

// 🛡️ 🛠️ معالجة تنفيذ أمر إنتاج وتصنيع جديد لدفعة خلطات/منتجات بالفرع
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'خطأ أمني: طلب غير صالح (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.';
    } elseif (isset($_POST['action_execute_batch_production'])) {
        
        $produced_item_id = intval($_POST['produced_item_id'] ?? 0);
        $produced_qty     = floatval($_POST['produced_qty'] ?? 0);

        if ($produced_item_id > 0 && $produced_qty > 0 && $produced_qty <= 9999 && isset($pdo)) {
            try {
                $pdo->beginTransaction();

                $stmtCheckTarget = $pdo->prepare("SELECT item_name FROM inventory WHERE id = ? AND branch_id = ? FOR UPDATE");
                $stmtCheckTarget->execute([$produced_item_id, $branch_id]);
                $target_item_name = $stmtCheckTarget->fetchColumn();

                if (!$target_item_name) {
                    throw new \Exception("الصنف المُراد تصنيعه غير موجود في سجلات مخزن هذا الفرع.");
                }

                $stmtRecipe = $pdo->prepare("SELECT child_inventory_id, required_qty FROM inventory_recipes WHERE parent_inventory_id = ? ORDER BY child_inventory_id ASC");
                $stmtRecipe->execute([$produced_item_id]);
                $recipe_items = $stmtRecipe->fetchAll(PDO::FETCH_ASSOC);

                if (empty($recipe_items)) {
                    throw new \Exception("عذراً، لا توجد مكونات مسجلة لهذه الخلطة! قم بتأسيس تركيبتها (BOM) أولاً من شاشة المشتريات والمخازن.");
                }

                $all_ids_to_lock = [$produced_item_id];
                $recipe_map = []; 
                
                foreach ($recipe_items as $r_item) {
                    $child_id = intval($r_item['child_inventory_id']);
                    
                    if ($child_id === $produced_item_id) {
                        throw new \Exception("خطأ في بنية الوصفة: لا يمكن أن يكون الصنف مادة خام لنفسه.");
                    }
                    
                    if (!in_array($child_id, $all_ids_to_lock)) {
                        $all_ids_to_lock[] = $child_id;
                    }
                    $recipe_map[$child_id] = floatval($r_item['required_qty']) * $produced_qty;
                }
                
                sort($all_ids_to_lock); 

                $locked_inventory = [];
                $stmtLock = $pdo->prepare("SELECT id, quantity, item_name, avg_unit_cost FROM inventory WHERE id = ? AND branch_id = ? FOR UPDATE");
                
                foreach ($all_ids_to_lock as $lock_id) {
                    $stmtLock->execute([$lock_id, $branch_id]);
                    $inv_data = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$inv_data) {
                        throw new \Exception("الصنف أو المادة الخام (ID: $lock_id) غير موجودة في سجلات مخزن هذا الفرع.");
                    }
                    $locked_inventory[$lock_id] = $inv_data;
                }

                foreach ($recipe_map as $child_id => $total_needed) {
                    $current_qty = floatval($locked_inventory[$child_id]['quantity']);
                    if ($current_qty < $total_needed) {
                        $mat_name = $locked_inventory[$child_id]['item_name'];
                        throw new \Exception("⚠️ رصيد المادة الخام ($mat_name) غير كافٍ للإنتاج. المطلوب: " . number_format($total_needed, 3) . "، المتاح: " . number_format($current_qty, 3) . ".");
                    }
                }

                // 🏗️ [تتبع تكلفة الوحدة] حساب تكلفة المواد الخام المستهلكة إجمالاً، لتوريثها للصنف المُصنَّع تلقائياً
                $total_raw_materials_cost = 0.000;
                foreach ($recipe_map as $child_id => $total_needed) {
                    $child_unit_cost = floatval($locked_inventory[$child_id]['avg_unit_cost']);
                    $total_raw_materials_cost += ($total_needed * $child_unit_cost);
                }
                $batch_produced_unit_cost = ($produced_qty > 0) ? ($total_raw_materials_cost / $produced_qty) : 0.000;

                $stmtDeduct = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND branch_id = ?");
                foreach ($recipe_map as $child_id => $total_needed) {
                    $stmtDeduct->execute([$total_needed, $child_id, $branch_id]);
                }

                // 🎯 دمج تكلفة الدفعة الجديدة مع تكلفة الرصيد الحالي للصنف المُصنَّع عبر معادلة المتوسط المرجّح
                $parent_old_qty  = floatval($locked_inventory[$produced_item_id]['quantity']);
                $parent_old_cost = floatval($locked_inventory[$produced_item_id]['avg_unit_cost']);
                $parent_new_total_qty = $parent_old_qty + $produced_qty;
                $parent_new_avg_cost = ($parent_new_total_qty > 0)
                    ? (($parent_old_qty * $parent_old_cost) + ($produced_qty * $batch_produced_unit_cost)) / $parent_new_total_qty
                    : $parent_old_cost;

                $stmtAddParent = $pdo->prepare("UPDATE inventory SET quantity = quantity + ?, avg_unit_cost = ? WHERE id = ? AND branch_id = ?");
                $stmtAddParent->execute([$produced_qty, $parent_new_avg_cost, $produced_item_id, $branch_id]);

                $stmtLog = $pdo->prepare("INSERT INTO kitchen_production_logs (branch_id, produced_item_id, produced_qty, produced_by) VALUES (?, ?, ?, ?)");
                $stmtLog->execute([$branch_id, $produced_item_id, $produced_qty, $_SESSION['user_id']]);

                $pdo->commit();
                $success_message = "🏭 تم تنفيذ أمر التصنيع بنجاح! تم خصم المواد الخام وإيداع دُفعة الإنتاج (" . htmlspecialchars($target_item_name) . ") في مخزن الفرع فوراً.";
                
            } catch (\PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Production Execute DB Error: " . $e->getMessage());
                $error_message = "حدث خطأ داخلي في قاعدة البيانات أثناء تشغيل التصنيع.";
            } catch (\Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "الرجاء اختيار صنف الخلطة وتحديد كمية تصنيع صحيحة (أكبر من 0 وأقل من 9999).";
        }
    }
}

// 📊 جلب كشوفات البيانات المغذية للشاشة
$branches_list = [];
$current_branch_name = "الفرع الرئيسي";
$global_company_name_ar = "شاي سيّار";
$inventory_items = [];
$production_history_log = [];

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

        $stmtItems = $pdo->prepare("SELECT id, item_name, unit, quantity FROM inventory WHERE branch_id = ? OR branch_id = 0 ORDER BY item_name ASC");
        $stmtItems->execute([$branch_id]);
        $inventory_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtHistory = $pdo->prepare("SELECT l.*, i.item_name, i.unit, u.username 
                                      FROM kitchen_production_logs l 
                                      JOIN inventory i ON l.produced_item_id = i.id 
                                      LEFT JOIN users u ON l.produced_by = u.id 
                                      WHERE l.branch_id = ? AND DATE(l.created_at) = CURRENT_DATE() 
                                      ORDER BY l.id DESC");
        $stmtHistory->execute([$branch_id]);
        $production_history_log = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    } catch (\Exception $e) {
        error_log("Production Fetch Data Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($global_company_name_ar); ?> - تصنيع الخلطات والدفعات (<?php echo htmlspecialchars($current_branch_name); ?>)</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-outer: #0E121A; --bg-main: #EFEBE3; --bg-card: #FFFFFF;
            --primary-red: #B82929; --success-green: #0F754C; --warning-orange: #D97706; --primary-blue: #1D4ED8;
            --text-dark: #222222; --text-muted: #666666; --border-color: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Tajawal', sans-serif; }
        body { background-color: var(--bg-outer); min-height: 100vh; overflow-x: hidden; position: relative; }
        
        .main-wrapper { display: flex; width: 100%; min-height: 100vh; position: relative; }
        .sys-sidebar { position: fixed !important; top: 0; right: -240px !important; left: auto !important; bottom: 0; width: 240px; z-index: 99999 !important; transition: right 0.4s ease; box-shadow: -5px 0 25px rgba(0,0,0,0.3); }
        body.sidebar-show .sys-sidebar { right: 0 !important; }

        .dashboard-container { background-color: var(--bg-main); flex-grow: 1; width: 100%; padding: 25px; text-align: right; transition: all 0.4s ease; position: relative; right: 0; }
        @media (min-width: 992px) { body.sidebar-show .dashboard-container { right: 240px; width: calc(100% - 240px); } }
        
        .header-zone { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(184, 41, 41, 0.15); padding-bottom: 12px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header-title-wrapper { display: inline-flex; align-items: center; gap: 10px; }
        .logo-text { color: var(--primary-red); font-size: 20px; font-weight: 700; margin: 0; }
        
        .cup-toggle-btn { width: 44px; height: 44px; display: inline-flex; justify-content: center; align-items: center; cursor: pointer; border-radius: 50%; background: rgba(184, 41, 41, 0.08); border: 1px solid rgba(184, 41, 41, 0.15); }
        .cup-toggle-btn img { width: 24px; height: 24px; object-fit: contain; }

        .section-card { background-color: var(--bg-card); border-radius: 16px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid var(--border-color); }
        .section-title { font-size: 14px; font-weight: 700; color: var(--primary-red); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 8px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: 12px; font-weight: bold; color: var(--text-dark); }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; background-color: white; }

        .btn-submit { background-color: var(--warning-orange); color: white; border: none; padding: 11px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }

        .table-responsive { background: white; border-radius: 10px; border: 1px solid var(--border-color); overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 13px; }
        th { background-color: #F1ECE4; color: var(--text-dark); padding: 12px; font-weight: 700; border-bottom: 2px solid var(--border-color); }
        td { padding: 12px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }

        .exec-branch-switcher-select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--primary-red); font-size: 12px; font-weight: bold; color: var(--primary-red); background: #FFF; outline: none; cursor: pointer; }
        .alert-box { padding: 10px 14px; border-radius: 8px; margin-bottom: 15px; font-size: 12px; text-align: center; font-weight: bold; }
        .alert-success { background-color: #DEF7EC; color: var(--success-green); border: 1px solid #BCF0DA; }
        .alert-error { background-color: #FEE2E2; color: var(--primary-red); border: 1px solid #FCA5A5; }
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
                    <h1 class="logo-text">تصنيع الخلطات والدفعات (Batch Production)</h1>
                    <span style="font-size:12px; color:#666; font-weight:bold;">📍 <?php echo htmlspecialchars($current_branch_name); ?></span>
                </div>
            </div>

            <!-- 🎯 [شريط التبديل المباشر بين الفروع للمدير التنفيذي] -->
            <?php if ($user_role === 'executive'): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; font-weight:bold;">تصنيع فرع:</span>
                    <select class="exec-branch-switcher-select" onchange="window.location.href='kitchen_production.php?switch_branch_id=' + this.value">
                        <?php foreach ($branches_list as $b): ?>
                            <option value="<?php echo htmlspecialchars($b['id']); ?>" <?php echo ($b['id'] == $branch_id) ? 'selected' : ''; ?>>
                                📍 <?php echo htmlspecialchars($b['branch_name'] ?? 'فرع #' . $b['id']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert-box alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert-box alert-error"><i class="bi bi-exclamation-circle-fill"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- 🏭 لوحة تشغيل أوامر الإنتاج والتصنيع الفوري -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-hammer"></i> لوحة تشغيل أوامر تصنيع الدفعات والخلطات بـ الفرع</h2>
            <form method="POST" action="kitchen_production.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action_execute_batch_production" value="1">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>حدد صنف الخلطة / الصنف المجهز المراد تصنيعه</label>
                        <select name="produced_item_id" required>
                            <option value="">-- اختر صنف الخلطة المصنعة --</option>
                            <?php foreach ($inventory_items as $inv): ?>
                                <option value="<?php echo htmlspecialchars($inv['id']); ?>">🏭 <?php echo htmlspecialchars($inv['item_name']); ?> (الوحدة: <?php echo htmlspecialchars($inv['unit']); ?>) | الرصيد الحالي: <?php echo number_format($inv['quantity'], 3); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>الكمية المُراد تصنيعها</label>
                        <input type="number" step="0.001" min="0.001" max="9999" name="produced_qty" placeholder="مثال: 50.000" required style="font-weight:bold; color:var(--primary-red);">
                    </div>
                </div>
                <div style="text-align:left; margin-top:10px;">
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-lightning-charge-fill"></i> بدء تشغيل ومعالجة أمر التصنيع
                    </button>
                </div>
            </form>
        </div>

        <!-- 📜 جدول سجل حركات التصنيع والإنتاج بالفرع اليوم -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-journal-check"></i> سجل أوامر التصنيع والإنتاج المنفذة اليوم بالفرع</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الخلطة / الصنف المُنتج</th>
                            <th>الكمية المصنعة</th>
                            <th>وقت التصنيع</th>
                            <th>المسؤول</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($production_history_log)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:20px;">لا توجد عمليات تصنيع دفعات مسجلة اليوم بهذا الفرع.</td></tr>
                        <?php else: ?>
                            <?php foreach ($production_history_log as $log): ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($log['id']); ?></strong></td>
                                    <td><strong style="color:var(--primary-red);">🏭 <?php echo htmlspecialchars($log['item_name']); ?></strong></td>
                                    <td><span style="font-weight:bold; color:var(--success-green);"><?php echo number_format($log['produced_qty'], 3) . ' ' . htmlspecialchars($log['unit']); ?></span></td>
                                    <td><small style="color:#666;"><?php echo date('H:i', strtotime($log['created_at'])); ?></small></td>
                                    <td><?php echo htmlspecialchars($log['username'] ?? 'طاقم المطبخ'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const cupToggle = document.getElementById("cupToggle");

    if (cupToggle) {
        cupToggle.addEventListener("click", function(e) {
            e.stopPropagation();
            document.body.classList.toggle("sidebar-show");
        });
    }
});
</script>
</body>
</html>