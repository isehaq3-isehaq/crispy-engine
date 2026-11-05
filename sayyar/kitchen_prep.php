<?php
// kitchen_prep.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['executive', 'branch_manager', 'kitchen_staff', 'cashier'])) {
    header("Location: login.php");
    exit;
}

// 🛡️ إنشاء رمز حماية CSRF لمنع الهجمات وتزوير الطلبات
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (file_exists('db.php')) {
    include_once 'db.php';
}
include_once __DIR__ . '/inventory_lib.php';

$success_message = '';
$error_message = '';
$user_role = $_SESSION['role'] ?? 'kitchen_staff';

// 🎯 [معالجة التبديل الديناميكي للفرع الخاص بالمدير التنفيذي] - كان الزر موجوداً بالواجهة بدون أي معالجة سيرفر فعلية
if ($user_role === 'executive' && isset($_GET['switch_branch_id'])) {
    $requested_branch_id = intval($_GET['switch_branch_id']);
    if ($requested_branch_id > 0 && isset($pdo)) {
        try {
            $stmtCheckBranch = $pdo->prepare("SELECT id FROM branches WHERE id = ? LIMIT 1");
            $stmtCheckBranch->execute([$requested_branch_id]);
            if ($stmtCheckBranch->fetchColumn()) {
                $_SESSION['selected_kitchen_prep_branch_id'] = $requested_branch_id;
            }
        } catch (\Exception $e) {
            error_log("Kitchen Prep switch_branch_id validation error: " . $e->getMessage());
        }
    }
}

$branch_id = ($user_role === 'executive' && isset($_SESSION['selected_kitchen_prep_branch_id']))
    ? $_SESSION['selected_kitchen_prep_branch_id']
    : ($_SESSION['branch_id'] ?? 1);

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

// 🏗️ تأسيس جدول تحضيرات السوائل والمواد التشغيلية بالفرع
if (isset($pdo)) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_preparations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL DEFAULT 1,
            prep_item_name VARCHAR(150) NOT NULL,
            prepared_qty DECIMAL(10,3) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            prepared_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        inv_ensure_movements_table($pdo);
    } catch (\Exception $e) {}
}

// 🛠️ معالجة تحضير سائل / خلطة جديدة للمطبخ الخفيف بالفرع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_submit_prep'])) {
    // 🛡️ التحقق من رمز الحماية CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "خطأ أمني: طلب غير صالح (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.";
    } else {
        $prep_item_name = trim($_POST['prep_item_name'] ?? '');
        $prepared_qty   = floatval($_POST['prepared_qty'] ?? 0);
        $unit           = trim($_POST['unit'] ?? 'لتر');

        if (!empty($prep_item_name) && $prepared_qty > 0) {
            try {
                if (isset($pdo)) {
                    $pdo->beginTransaction();

                    // 1. تسديد قيد التحضير بجدول التحضيرات الخاص بالفرع
                    $stmtIns = $pdo->prepare("INSERT INTO kitchen_preparations (branch_id, prep_item_name, prepared_qty, unit, prepared_by) VALUES (?, ?, ?, ?, ?)");
                    $stmtIns->execute([$branch_id, $prep_item_name, $prepared_qty, $unit, $_SESSION['user_id']]);

                    // 2. تحديث وتغذية مخزون السوائل/التحضيرات المتاحة بالمطبخ
                    // 🛡️ [إصلاح حرج] تصفية بالفرع الحالي - كانت هذه الاستعلامات تتجاهل الفرع نهائياً، فتتجمّع كل تحضيرات كل الفروع في مخزون فرع واحد فقط
                    $recv = inv_receive_stock($pdo, $branch_id, $prep_item_name, 'kitchen_fluid', $prepared_qty, $unit, 0, 'food', 'pre_mixed');

                    // 📒 توثيق التحضير في سجل حركات المخزون
                    inv_log_movement($pdo, [
                        'branch_id' => $branch_id, 'voucher_no' => inv_next_voucher_no($pdo, $branch_id, 'production'), 'movement_type' => 'production',
                        'item_id' => $recv['id'], 'item_name' => $prep_item_name, 'from_store' => null, 'to_store' => 'kitchen_fluid',
                        'qty' => $prepared_qty, 'unit' => $unit, 'unit_cost' => 0, 'balance_after' => $recv['balance'],
                        'reason' => 'prep', 'note' => 'تحضير يومي من شاشة المطبخ', 'created_by' => intval($_SESSION['user_id']),
                    ]);

                    $pdo->commit();
                    $success_message = "🧪 تم تسجيل تحضير ($prepared_qty $unit) من ($prep_item_name) وتغذية مخزن المطبخ بنجاح!";
                }
            } catch (\Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log("Kitchen Prep Submit Error: " . $e->getMessage());
                $error_message = "خطأ أثناء تسجيل التحضير.";
            }
        } else {
            $error_message = "يرجى تعبئة اسم المادة وتحديد كمية صحيحة أكبر من صفر.";
        }
    }
}

// 📊 جلب كشف التحضيرات اليومية للفرع
$preparations_log = [];
$branches_list = [];
$current_branch_name = "الفرع الرئيسي";

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

        $stmtLog = $pdo->prepare("SELECT kp.*, u.username 
                                  FROM kitchen_preparations kp 
                                  LEFT JOIN users u ON kp.prepared_by = u.id 
                                  WHERE kp.branch_id = ? AND DATE(kp.created_at) = CURRENT_DATE() 
                                  ORDER BY kp.id DESC");
        $stmtLog->execute([$branch_id]);
        $preparations_log = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>شاي سيّار - تحضيرات وتجهيز المطبخ (<?php echo htmlspecialchars($current_branch_name); ?>)</title>
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

        .dashboard-container { background-color: var(--bg-main); flex-grow: 1; width: 100%; padding: 25px; text-align: right; transition: all 0.4s ease; position: relative; right: 0; }
        @media (min-width: 992px) { body.sidebar-show .dashboard-container { right: 240px; width: calc(100% - 240px); } }
        
        .header-zone { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(184, 41, 41, 0.15); padding-bottom: 12px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header-title-wrapper { display: inline-flex; align-items: center; gap: 10px; }
        .logo-text { color: var(--primary-red); font-size: 20px; font-weight: 700; margin: 0; }
        
        .cup-toggle-btn { width: 44px; height: 44px; display: inline-flex; justify-content: center; align-items: center; cursor: pointer; border-radius: 50%; background: rgba(184, 41, 41, 0.08); border: 1px solid rgba(184, 41, 41, 0.15); }
        .cup-toggle-btn img { width: 24px; height: 24px; object-fit: contain; }

        .section-card { background-color: var(--bg-card); border-radius: 16px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid var(--border-color); }
        .section-title { font-size: 14px; font-weight: 700; color: var(--primary-red); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 8px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: 12px; font-weight: bold; color: var(--text-dark); }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; background-color: white; }

        .btn-submit { background-color: var(--success-green); color: white; border: none; padding: 11px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }

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
                    <h1 class="logo-text">تحضيرات وتجهيز المطبخ (Prep Work)</h1>
                    <span style="font-size:12px; color:#666; font-weight:bold;">📍 <?php echo htmlspecialchars($current_branch_name); ?></span>
                </div>
            </div>

            <!-- 🎯 [شريط التبديل للمدير التنفيذي] -->
            <?php if ($user_role === 'executive'): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; font-weight:bold;">تحضيرات فرع:</span>
                    <select class="exec-branch-switcher-select" onchange="window.location.href='kitchen_prep.php?switch_branch_id=' + this.value">
                        <?php foreach ($branches_list as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($b['id'] == $branch_id) ? 'selected' : ''; ?>>
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

        <!-- 🧪 شاشة إدخال قيد تحضير يومي جديد بالفرع -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-mortarboard-fill"></i> تسجيل تحضير وإعداد السوائل اليومية للفرع</h2>
            <form method="POST" action="kitchen_prep.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action_submit_prep" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>اسم المادة السائلة / الخلطة المجهزة</label>
                        <input type="text" name="prep_item_name" placeholder="مثال: شاي سرنديب مركز، حليب الكرك المغلي" required>
                    </div>
                    <div class="form-group">
                        <label>الكمية المجهزة</label>
                        <input type="number" step="0.001" name="prepared_qty" placeholder="0.000" required>
                    </div>
                    <div class="form-group">
                        <label>وحدة القياس</label>
                        <select name="unit" required>
                            <option value="لتر">لتر</option>
                            <option value="ملي">ملي</option>
                            <option value="كيلو">كيلو</option>
                            <option value="طبق/حافظة">طبق / حافظة</option>
                        </select>
                    </div>
                </div>
                <div style="text-align:left; margin-top:10px;">
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-plus-circle-fill"></i> تسجيل وإيداع بمخزن المطبخ
                    </button>
                </div>
            </form>
        </div>

        <!-- 📜 جدول سجل تحضيرات اليوم بالفرع -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-journal-check"></i> كشف تحضيرات اليوم الحية للفرع</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم المادة / الخلطة</th>
                            <th>الكمية المجهزة</th>
                            <th>الوقت</th>
                            <th>المسؤول عن التحضير</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($preparations_log)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:20px;">لا توجد عمليات تحضير مسجلة اليوم بهذا الفرع.</td></tr>
                        <?php else: ?>
                            <?php foreach ($preparations_log as $log): ?>
                                <tr>
                                    <td><strong>#<?php echo $log['id']; ?></strong></td>
                                    <td><strong style="color:var(--primary-red);"><?php echo htmlspecialchars($log['prep_item_name']); ?></strong></td>
                                    <td><span style="font-weight:bold; color:var(--success-green);"><?php echo number_format($log['prepared_qty'], 3) . ' ' . htmlspecialchars($log['unit']); ?></span></td>
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