<?php
// stock_issue.php
// صرف وحركة المخزون (أذونات الصرف والتحويل، صرف الاستهلاك والهالك، الجرد الفعلي والتسوية، سجل الحركات) - شاي سيّار

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 الصلاحيات: المدير التنفيذي ومدير الفرع
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['executive', 'branch_manager'])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (file_exists('db.php')) {
    include_once 'db.php';
}
include_once __DIR__ . '/inventory_lib.php';

$user_role = $_SESSION['role'] ?? 'branch_manager';
$actual_branch_id = intval($_SESSION['branch_id'] ?? 1);
$allowed_tabs = ['transfer', 'out', 'count', 'log'];
$stores = inv_store_types();
$movement_types = inv_movement_types();
$out_reasons = inv_out_reasons();

// 🎯 تبديل الفرع للمدير التنفيذي (نفس اختيار شاشة المخزن الرئيسي)
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
    $back_tab = in_array($_POST['tab'] ?? '', $allowed_tabs, true) ? $_POST['tab'] : 'transfer';
    header("Location: stock_issue.php?tab=" . $back_tab);
    exit;
}

$branch_id = ($user_role === 'executive' && isset($_SESSION['selected_inventory_branch_id']))
    ? intval($_SESSION['selected_inventory_branch_id'])
    : $actual_branch_id;

$active_tab = in_array($_GET['tab'] ?? '', $allowed_tabs, true) ? $_GET['tab'] : 'transfer';

if (!function_exists('get_omr_symbol')) {
    function get_omr_symbol() {
        $base_path = 'Ro/RO.svg';
        if (file_exists($base_path)) {
            $version = filemtime($base_path);
            return '<img src="' . $base_path . '?v=' . $version . '" class="omr-official-svg-icon" alt="﷼">';
        }
        return '<span style="font-weight:bold; font-size:12px; margin-left:3px; color:#B82929;">ريال</span>';
    }
}

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_flash'])) { $success_message = $_SESSION['success_flash']; unset($_SESSION['success_flash']); }
if (isset($_SESSION['error_flash']))   { $error_message   = $_SESSION['error_flash'];   unset($_SESSION['error_flash']); }

if (isset($pdo)) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");
        inv_ensure_movements_table($pdo);
    } catch (\PDOException $e) {
        error_log('Stock Issue DB Init Error: ' . $e->getMessage());
    }
}

// 🛡️ معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo)) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_flash'] = 'خطأ أمني: طلب غير صالح (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.';
        header("Location: stock_issue.php?tab=" . $active_tab);
        exit;
    }

    $user_id = intval($_SESSION['user_id']);
    $note = trim($_POST['note'] ?? '');

    // ─── 1) إذن صرف / تحويل بين مخازن الفرع ───
    if (isset($_POST['action_issue_transfer'])) {
        $from_store = $_POST['from_store'] ?? '';
        $to_store   = $_POST['to_store'] ?? '';
        $item_ids   = $_POST['item_ids'] ?? [];
        $qtys       = $_POST['qtys'] ?? [];

        try {
            if (!isset($stores[$from_store]) || !isset($stores[$to_store])) {
                throw new \Exception('يرجى اختيار المخزن المصدر والمخزن المستلم.');
            }
            if ($from_store === $to_store) {
                throw new \Exception('لا يمكن الصرف من المخزن إلى نفسه.');
            }
            $lines = [];
            foreach ((array)$item_ids as $idx => $iid) {
                $iid = intval($iid);
                $q = floatval($qtys[$idx] ?? 0);
                if ($iid <= 0 && $q == 0) continue;
                if ($iid <= 0 || $q <= 0) throw new \Exception('كل بند يجب أن يحتوي على صنف وكمية أكبر من صفر.');
                $lines[$iid] = ($lines[$iid] ?? 0) + $q;
            }
            if (empty($lines)) throw new \Exception('أضف بنداً واحداً على الأقل لإذن الصرف.');

            $pdo->beginTransaction();
            $voucher = inv_next_voucher_no($pdo, $branch_id, 'transfer');
            $stmtSrc  = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND branch_id = ? FOR UPDATE");
            $stmtDest = $pdo->prepare("SELECT * FROM inventory WHERE item_name = ? AND inventory_type = ? AND branch_id = ? LIMIT 1 FOR UPDATE");
            $total_value = 0;

            foreach ($lines as $iid => $q) {
                $stmtSrc->execute([$iid, $branch_id]);
                $src = $stmtSrc->fetch(PDO::FETCH_ASSOC);
                if (!$src || $src['inventory_type'] !== $from_store) {
                    throw new \Exception('أحد الأصناف لا ينتمي للمخزن المصدر في هذا الفرع.');
                }
                $available = floatval($src['quantity']);
                if ($q > $available + 0.0005) {
                    throw new \Exception('الرصيد غير كافٍ للصنف (' . $src['item_name'] . '). المطلوب: ' . number_format($q, 3) . ' / المتاح: ' . number_format($available, 3) . ' ' . $src['unit']);
                }
                $cost = floatval($src['avg_unit_cost']);
                $src_after = $available - $q;
                $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND branch_id = ?")->execute([$src_after, $iid, $branch_id]);

                $stmtDest->execute([$src['item_name'], $to_store, $branch_id]);
                $dest = $stmtDest->fetch(PDO::FETCH_ASSOC);
                if ($dest) {
                    if (trim($dest['unit']) !== trim($src['unit'])) {
                        throw new \Exception('وحدة القياس للصنف (' . $src['item_name'] . ') مختلفة بين المخزنين (' . $src['unit'] . ' / ' . $dest['unit'] . '). وحّد الوحدة أولاً.');
                    }
                    $d_old_q = floatval($dest['quantity']);
                    $d_old_c = floatval($dest['avg_unit_cost']);
                    $d_new_q = $d_old_q + $q;
                    $d_new_c = ($d_new_q > 0) ? (($d_old_q * $d_old_c) + ($q * $cost)) / $d_new_q : $cost;
                    $pdo->prepare("UPDATE inventory SET quantity = ?, avg_unit_cost = ? WHERE id = ? AND branch_id = ?")->execute([$d_new_q, $d_new_c, $dest['id'], $branch_id]);
                    $dest_id = $dest['id'];
                    $dest_after = $d_new_q;
                } else {
                    $pdo->prepare("INSERT INTO inventory (branch_id, item_name, inventory_type, main_category, sub_category, quantity, unit, min_limit, avg_unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$branch_id, $src['item_name'], $to_store, $src['main_category'], $src['sub_category'], $q, $src['unit'], 0, $cost]);
                    $dest_id = $pdo->lastInsertId();
                    $dest_after = $q;
                }

                inv_log_movement($pdo, [
                    'branch_id' => $branch_id, 'voucher_no' => $voucher, 'movement_type' => 'transfer',
                    'item_id' => $iid, 'item_name' => $src['item_name'], 'from_store' => $from_store, 'to_store' => $to_store,
                    'qty' => -$q, 'unit' => $src['unit'], 'unit_cost' => $cost, 'balance_after' => $src_after,
                    'note' => $note, 'created_by' => $user_id,
                ]);
                inv_log_movement($pdo, [
                    'branch_id' => $branch_id, 'voucher_no' => $voucher, 'movement_type' => 'transfer',
                    'item_id' => $dest_id, 'item_name' => $src['item_name'], 'from_store' => $from_store, 'to_store' => $to_store,
                    'qty' => $q, 'unit' => $src['unit'], 'unit_cost' => $cost, 'balance_after' => $dest_after,
                    'note' => $note, 'created_by' => $user_id,
                ]);
                $total_value += $q * $cost;
            }
            $pdo->commit();
            $_SESSION['success_flash'] = '✅ تم اعتماد إذن الصرف رقم ' . $voucher . ' (' . count($lines) . ' بند) من ' . inv_store_label($from_store) . ' إلى ' . inv_store_label($to_store) . ' بقيمة ' . number_format($total_value, 3) . ' ر.ع.';
            $_SESSION['last_voucher'] = $voucher;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException) {
                error_log('Stock Transfer Error: ' . $e->getMessage());
                $_SESSION['error_flash'] = 'حدث خطأ في قاعدة البيانات أثناء اعتماد إذن الصرف، لم يتم ترحيل أي كمية.';
            } else {
                $_SESSION['error_flash'] = $e->getMessage();
            }
        }
        header("Location: stock_issue.php?tab=transfer");
        exit;
    }

    // ─── 2) صرف استهلاك / هالك (خروج نهائي من المخزون) ───
    if (isset($_POST['action_issue_out'])) {
        $mtype    = $_POST['out_type'] ?? '';
        $reason   = $_POST['reason'] ?? '';
        $item_ids = $_POST['item_ids'] ?? [];
        $qtys     = $_POST['qtys'] ?? [];

        try {
            if (!isset($out_reasons[$mtype])) throw new \Exception('نوع الصرف غير صالح.');
            if (!isset($out_reasons[$mtype][$reason])) throw new \Exception('يرجى اختيار سبب الصرف.');
            $lines = [];
            foreach ((array)$item_ids as $idx => $iid) {
                $iid = intval($iid);
                $q = floatval($qtys[$idx] ?? 0);
                if ($iid <= 0 && $q == 0) continue;
                if ($iid <= 0 || $q <= 0) throw new \Exception('كل بند يجب أن يحتوي على صنف وكمية أكبر من صفر.');
                $lines[$iid] = ($lines[$iid] ?? 0) + $q;
            }
            if (empty($lines)) throw new \Exception('أضف بنداً واحداً على الأقل.');

            $pdo->beginTransaction();
            $voucher = inv_next_voucher_no($pdo, $branch_id, $mtype);
            $stmtSrc = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND branch_id = ? FOR UPDATE");
            $total_value = 0;
            foreach ($lines as $iid => $q) {
                $stmtSrc->execute([$iid, $branch_id]);
                $src = $stmtSrc->fetch(PDO::FETCH_ASSOC);
                if (!$src) throw new \Exception('أحد الأصناف غير موجود في مخزون هذا الفرع.');
                $available = floatval($src['quantity']);
                if ($q > $available + 0.0005) {
                    throw new \Exception('الرصيد غير كافٍ للصنف (' . $src['item_name'] . '). المطلوب: ' . number_format($q, 3) . ' / المتاح: ' . number_format($available, 3) . ' ' . $src['unit']);
                }
                $after = $available - $q;
                $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND branch_id = ?")->execute([$after, $iid, $branch_id]);
                $cost = floatval($src['avg_unit_cost']);
                inv_log_movement($pdo, [
                    'branch_id' => $branch_id, 'voucher_no' => $voucher, 'movement_type' => $mtype,
                    'item_id' => $iid, 'item_name' => $src['item_name'], 'from_store' => $src['inventory_type'], 'to_store' => null,
                    'qty' => -$q, 'unit' => $src['unit'], 'unit_cost' => $cost, 'balance_after' => $after,
                    'reason' => $reason, 'note' => $note, 'created_by' => $user_id,
                ]);
                $total_value += $q * $cost;
            }
            $pdo->commit();
            $_SESSION['success_flash'] = '✅ تم ترحيل سند ' . $movement_types[$mtype]['label'] . ' رقم ' . $voucher . ' (' . $out_reasons[$mtype][$reason] . ') بقيمة ' . number_format($total_value, 3) . ' ر.ع.';
            $_SESSION['last_voucher'] = $voucher;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException) {
                error_log('Stock Out Error: ' . $e->getMessage());
                $_SESSION['error_flash'] = 'حدث خطأ في قاعدة البيانات أثناء الترحيل، لم يتم خصم أي كمية.';
            } else {
                $_SESSION['error_flash'] = $e->getMessage();
            }
        }
        header("Location: stock_issue.php?tab=out");
        exit;
    }

    // ─── 3) الجرد الفعلي وتسوية الفروقات ───
    if (isset($_POST['action_stocktake'])) {
        $store  = $_POST['count_store'] ?? '';
        $counts = $_POST['counted'] ?? [];
        try {
            if (!isset($stores[$store])) throw new \Exception('يرجى اختيار المخزن المراد جرده.');
            $pdo->beginTransaction();
            $voucher = inv_next_voucher_no($pdo, $branch_id, 'count_adjust');
            $stmtSrc = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND branch_id = ? AND inventory_type = ? FOR UPDATE");
            $adjusted = 0; $counted_n = 0; $variance_value = 0;
            foreach ((array)$counts as $iid => $val) {
                if (trim((string)$val) === '') continue;
                $iid = intval($iid);
                $actual = floatval($val);
                if ($actual < 0) throw new \Exception('لا يمكن إدخال كمية جرد سالبة.');
                $stmtSrc->execute([$iid, $branch_id, $store]);
                $src = $stmtSrc->fetch(PDO::FETCH_ASSOC);
                if (!$src) continue;
                $counted_n++;
                $diff = round($actual - floatval($src['quantity']), 3);
                if (abs($diff) < 0.0005) continue;
                $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND branch_id = ?")->execute([$actual, $iid, $branch_id]);
                $cost = floatval($src['avg_unit_cost']);
                inv_log_movement($pdo, [
                    'branch_id' => $branch_id, 'voucher_no' => $voucher, 'movement_type' => 'count_adjust',
                    'item_id' => $iid, 'item_name' => $src['item_name'], 'from_store' => $store, 'to_store' => $store,
                    'qty' => $diff, 'unit' => $src['unit'], 'unit_cost' => $cost, 'balance_after' => $actual,
                    'reason' => $diff > 0 ? 'surplus' : 'shortage', 'note' => $note, 'created_by' => $user_id,
                ]);
                $adjusted++;
                $variance_value += $diff * $cost;
            }
            if ($counted_n === 0) throw new \Exception('لم يتم إدخال أي كمية جرد فعلية.');
            $pdo->commit();
            $_SESSION['success_flash'] = $adjusted > 0
                ? '✅ تم اعتماد جرد ' . inv_store_label($store) . ' (سند ' . $voucher . '): ' . $counted_n . ' صنف مجرود، ' . $adjusted . ' صنف به فروقات، صافي الفرق ' . number_format($variance_value, 3) . ' ر.ع.'
                : '✅ تم جرد ' . $counted_n . ' صنف في ' . inv_store_label($store) . ' ومطابقتها للنظام بدون أي فروقات.';
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException) {
                error_log('Stocktake Error: ' . $e->getMessage());
                $_SESSION['error_flash'] = 'حدث خطأ في قاعدة البيانات أثناء اعتماد الجرد.';
            } else {
                $_SESSION['error_flash'] = $e->getMessage();
            }
        }
        header("Location: stock_issue.php?tab=count&store=" . urlencode($store));
        exit;
    }
}

// 📊 جلب البيانات
$branches_list = [];
$current_branch_name = 'الفرع الرئيسي';
$global_company_name_ar = 'شاي سيّار';
$inventory_items = [];
$movements = [];
$log_totals = [];

$f_from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
$f_to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$f_type  = isset($movement_types[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$f_item  = intval($_GET['item'] ?? 0);
$f_store = isset($stores[$_GET['store'] ?? '']) ? $_GET['store'] : '';
$count_store = isset($stores[$_GET['store'] ?? '']) ? $_GET['store'] : 'main_hub';
$f_item_name = '';

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

        $stmtInv = $pdo->prepare("SELECT id, item_name, inventory_type, main_category, quantity, unit, min_limit, avg_unit_cost FROM inventory WHERE branch_id = ? ORDER BY item_name ASC");
        $stmtInv->execute([$branch_id]);
        $inventory_items = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

        if ($active_tab === 'log') {
            $sql = "SELECT m.*, u.username FROM inventory_movements m LEFT JOIN users u ON m.created_by = u.id
                    WHERE m.branch_id = ? AND DATE(m.created_at) BETWEEN ? AND ?";
            $params = [$branch_id, $f_from, $f_to];
            if ($f_type !== '') { $sql .= " AND m.movement_type = ?"; $params[] = $f_type; }
            if ($f_item > 0)   { $sql .= " AND m.item_id = ?"; $params[] = $f_item; }
            if ($f_store !== '') { $sql .= " AND ((m.qty < 0 AND m.from_store = ?) OR (m.qty > 0 AND m.to_store = ?) OR (m.movement_type IN ('count_adjust','manual_edit') AND m.from_store = ?))"; array_push($params, $f_store, $f_store, $f_store); }
            $sql .= " ORDER BY m.id DESC LIMIT 1000";
            $stmtLog = $pdo->prepare($sql);
            $stmtLog->execute($params);
            $movements = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
            foreach ($movements as $mv) {
                $t = $mv['movement_type'];
                if (!isset($log_totals[$t])) $log_totals[$t] = ['count' => [], 'value' => 0];
                $log_totals[$t]['count'][$mv['voucher_no']] = true;
                // قيمة التحويل تُحسب مرة واحدة (سطر الخروج)، والإنتاج بقيمة الناتج (سطر الدخول)
                if (($t !== 'transfer' || floatval($mv['qty']) < 0) && ($t !== 'production' || floatval($mv['qty']) > 0)) {
                    $log_totals[$t]['value'] += ($t === 'count_adjust' || $t === 'manual_edit') ? floatval($mv['qty']) * floatval($mv['unit_cost']) : floatval($mv['total_cost']);
                }
            }
            if ($f_item > 0) {
                foreach ($inventory_items as $it) { if ($it['id'] == $f_item) { $f_item_name = $it['item_name'] . ' — ' . inv_store_label($it['inventory_type']); break; } }
            }
        }
    } catch (\Exception $e) {
        error_log('Stock Issue Fetch Error: ' . $e->getMessage());
    }
}

$items_js = array_map(function ($it) {
    return [
        'id' => intval($it['id']), 'name' => $it['item_name'], 'store' => $it['inventory_type'],
        'qty' => floatval($it['quantity']), 'unit' => $it['unit'], 'cost' => floatval($it['avg_unit_cost']),
        'min' => floatval($it['min_limit']),
    ];
}, $inventory_items);

$count_items = array_values(array_filter($inventory_items, function ($it) use ($count_store) { return $it['inventory_type'] === $count_store; }));
$store_counts = [];
foreach ($inventory_items as $it) { $store_counts[$it['inventory_type']] = ($store_counts[$it['inventory_type']] ?? 0) + 1; }

$tabs_meta = [
    'transfer' => ['label' => 'إذن صرف وتحويل', 'icon' => 'bi-arrow-left-right', 'hint' => 'من المخزن الرئيسي إلى البار / المطبخ / الثلاجة'],
    'out'      => ['label' => 'صرف استهلاك وهالك', 'icon' => 'bi-trash3', 'hint' => 'تالف، منتهي، ضيافة، وجبات موظفين'],
    'count'    => ['label' => 'الجرد الفعلي والتسوية', 'icon' => 'bi-clipboard-check', 'hint' => 'مطابقة الرصيد الفعلي مع النظام'],
    'log'      => ['label' => 'سجل الحركات وكرت الصنف', 'icon' => 'bi-journal-text', 'hint' => 'كل أذونات الصرف والتسويات'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($global_company_name_ar); ?> - صرف وحركة المخزون (<?php echo htmlspecialchars($current_branch_name); ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-outer: #0E121A; --bg-main: #EFEBE3; --bg-card: #FFFFFF;
            --primary-red: #B82929; --success-green: #0F754C; --warning-orange: #D97706; --primary-blue: #1D4ED8;
            --text-dark: #222222; --text-muted: #666666; --border-color: #E2E8F0; --soft: #FAF8F4;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Tajawal', sans-serif; }
        body { background-color: var(--bg-outer); min-height: 100vh; overflow-x: hidden; position: relative; }
        .main-wrapper { display: flex; width: 100%; min-height: 100vh; position: relative; }
        .sys-sidebar { position: fixed !important; top: 0; right: -240px !important; bottom: 0; width: 240px; z-index: 99999 !important; transition: right 0.4s ease; box-shadow: -5px 0 25px rgba(0,0,0,0.3); }
        body.sidebar-show .sys-sidebar { right: 0 !important; }
        .dashboard-container { background-color: var(--bg-main); flex-grow: 1; width: 100%; padding: 15px; text-align: right; transition: all 0.4s ease; position: relative; right: 0; }
        @media (min-width: 992px) { body.sidebar-show .dashboard-container { right: 240px; width: calc(100% - 240px); } .dashboard-container { padding: 25px; } }

        .header-zone { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(184, 41, 41, 0.15); padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .header-title-wrapper { display: inline-flex; align-items: center; gap: 10px; }
        .logo-text { color: var(--primary-red); font-size: 20px; font-weight: 700; margin: 0; }
        .cup-toggle-btn { width: 44px; height: 44px; display: inline-flex; justify-content: center; align-items: center; cursor: pointer; border-radius: 50%; background: rgba(184, 41, 41, 0.08); border: 1px solid rgba(184, 41, 41, 0.15); }
        .cup-toggle-btn img { width: 24px; height: 24px; object-fit: contain; }
        .header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px; border: 1px solid #CBD5E0; background: #fff; color: var(--text-dark); font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .exec-branch-switcher-select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--primary-red); font-size: 12px; font-weight: bold; color: var(--primary-red); background: #FFF; outline: none; cursor: pointer; }

        /* شريط مراحل العمل */
        .ops-tabs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 16px; }
        @media (min-width: 900px) { .ops-tabs { grid-template-columns: repeat(4, 1fr); } }
        .ops-tab { display: flex; gap: 10px; align-items: center; padding: 12px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; text-decoration: none; color: var(--text-dark); transition: all .2s; }
        .ops-tab i { font-size: 20px; color: var(--primary-red); width: 38px; height: 38px; border-radius: 10px; background: rgba(184,41,41,.08); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ops-tab strong { display: block; font-size: 13px; }
        .ops-tab small { display: block; font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }
        .ops-tab.active { background: var(--primary-red); border-color: var(--primary-red); color: #fff; box-shadow: 0 6px 18px rgba(184,41,41,.25); }
        .ops-tab.active i { background: rgba(255,255,255,.18); color: #fff; }
        .ops-tab.active small { color: rgba(255,255,255,.85); }

        .section-card { background-color: var(--bg-card); border-radius: 14px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 18px; border: 1px solid var(--border-color); }
        .section-title { font-size: 14px; font-weight: 700; color: var(--primary-red); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 8px; }
        .grid-3 { display: grid; grid-template-columns: 1fr; gap: 12px; }
        @media (min-width: 768px) { .grid-3 { grid-template-columns: 1fr 1fr 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 5px; width: 100%; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-dark); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; background-color: white; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-red); }

        .flow-arrow { display: flex; align-items: center; justify-content: center; color: var(--primary-red); font-size: 22px; padding-top: 18px; }

        .lines-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 14px; }
        .lines-table th { background: #F1ECE4; padding: 10px; text-align: right; font-weight: 700; border-bottom: 2px solid var(--border-color); }
        .lines-table td { padding: 8px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
        .lines-table select, .lines-table input { width: 100%; padding: 8px; border: 1px solid #CBD5E0; border-radius: 6px; font-size: 12.5px; background: #fff; outline: none; }
        .lines-table input.qty-input { max-width: 120px; font-weight: 700; }
        .avail-pill { font-size: 11px; font-weight: 700; color: var(--text-muted); white-space: nowrap; }
        .avail-pill.low { color: var(--warning-orange); }
        .avail-pill.over { color: var(--primary-red); }
        .btn-del-line { background: none; border: none; color: var(--primary-red); font-size: 20px; cursor: pointer; }
        .table-wrap { overflow-x: auto; width: 100%; border: 1px solid var(--border-color); border-radius: 10px; }
        .table-wrap > table { min-width: 620px; margin-top: 0; }

        .form-footer { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--border-color); }
        .total-box { font-size: 13px; font-weight: 700; color: var(--text-dark); }
        .total-box span { color: var(--success-green); font-size: 16px; }
        .btn-add-row { background-color: var(--text-dark); color: white; border: none; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-submit { background-color: var(--success-green); color: white; border: none; padding: 11px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-submit.red { background-color: var(--primary-red); }
        .btn-submit.orange { background-color: var(--warning-orange); }

        .seg { display: inline-flex; border: 1px solid #CBD5E0; border-radius: 10px; overflow: hidden; margin-bottom: 14px; }
        .seg label { padding: 9px 16px; font-size: 12.5px; font-weight: 700; cursor: pointer; background: #fff; display: inline-flex; align-items: center; gap: 6px; }
        .seg input { display: none; }
        .seg input:checked + label { background: var(--primary-red); color: #fff; }

        .store-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .store-chip { padding: 8px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-decoration: none; color: var(--text-dark); background: #E2E8F0; border: 1px solid #CBD5E0; }
        .store-chip.active { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .count-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 700px; }
        .count-table th { background: #F1ECE4; padding: 10px; text-align: right; border-bottom: 2px solid var(--border-color); }
        .count-table td { padding: 8px 10px; border-bottom: 1px solid #EDF2F7; }
        .count-table input { width: 110px; padding: 7px; border: 1px solid #CBD5E0; border-radius: 6px; font-weight: 700; text-align: center; }
        .var-cell { font-weight: 700; }
        .var-pos { color: var(--success-green); } .var-neg { color: var(--primary-red); } .var-zero { color: var(--text-muted); }

        .kpi-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }
        @media (min-width: 900px) { .kpi-row { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); } }
        .kpi { background: var(--soft); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; }
        .kpi small { font-size: 11px; color: var(--text-muted); font-weight: 700; display: block; }
        .kpi strong { font-size: 15px; display: block; margin-top: 4px; }

        .filter-bar { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: end; margin-bottom: 14px; }
        @media (min-width: 900px) { .filter-bar { grid-template-columns: repeat(5, 1fr) auto; } }
        .log-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 860px; }
        .log-table th { background: #F1ECE4; padding: 10px; text-align: right; border-bottom: 2px solid var(--border-color); }
        .log-table td { padding: 9px 10px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
        .mv-badge { padding: 3px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 700; display: inline-block; white-space: nowrap; }
        .qty-in { color: var(--success-green); font-weight: 700; } .qty-out { color: var(--primary-red); font-weight: 700; }
        .voucher-link { font-family: monospace; font-size: 11.5px; font-weight: 700; color: var(--primary-blue); cursor: pointer; text-decoration: underline dotted; }

        .alert-box { padding: 10px 14px; border-radius: 8px; margin-bottom: 15px; font-size: 12.5px; text-align: center; font-weight: bold; }
        .alert-success { background-color: #D1FAE5; color: var(--success-green); border: 1px solid #A7F3D0; }
        .alert-error { background-color: #FEE2E2; color: var(--primary-red); border: 1px solid #FCA5A5; }
        .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 6px; }
        .empty { text-align: center; color: var(--text-muted); padding: 24px; font-weight: 700; }
        .omr-official-svg-icon { height: 1.15em !important; width: auto !important; vertical-align: middle !important; margin-left: 3px !important; display: inline-block !important; filter: invert(21%) sepia(85%) saturate(3501%) hue-rotate(346deg) brightness(85%) contrast(85%) !important; }

        @media print {
            body { background: #fff; }
            .sys-sidebar, .sidebar-global-overlay-layer, #sysGlobalVideoLoaderZone, .header-actions, .ops-tabs, .filter-bar, .no-print, .cup-toggle-btn { display: none !important; }
            .dashboard-container { padding: 0; background: #fff; right: 0 !important; width: 100% !important; }
            .section-card { border: none; box-shadow: none; padding: 0; }
            .table-wrap { border: none; overflow: visible; }
            .log-table, .count-table { min-width: 0; }
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
                    <h1 class="logo-text">صرف وحركة المخزون</h1>
                    <span style="font-size:12px; color:#666; font-weight:bold;">📍 <?php echo htmlspecialchars($current_branch_name); ?> · <?php echo date('Y-m-d'); ?></span>
                </div>
            </div>
            <div class="header-actions">
                <a href="main_inventory.php" class="btn-ghost"><i class="bi bi-box-seam"></i> أرصدة المخازن</a>
                <?php if ($user_role === 'executive'): ?>
                    <form method="POST" action="stock_issue.php" style="margin:0; display:inline-flex; align-items:center; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                        <select name="switch_branch_id" class="exec-branch-switcher-select" onchange="this.form.submit()">
                            <?php foreach ($branches_list as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['id']); ?>" <?php echo ($b['id'] == $branch_id) ? 'selected' : ''; ?>>📍 <?php echo htmlspecialchars($b['branch_name'] ?? 'فرع #' . $b['id']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div class="alert-box alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success_message); ?>
                <?php if (!empty($_SESSION['last_voucher'])): ?>
                    &nbsp;<a class="voucher-link" href="stock_issue.php?tab=log&from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>&voucher=<?php echo urlencode($_SESSION['last_voucher']); ?>">عرض وطباعة السند</a>
                    <?php unset($_SESSION['last_voucher']); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message !== ''): ?>
            <div class="alert-box alert-error"><i class="bi bi-exclamation-octagon-fill"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- 🧭 مراحل العمل -->
        <nav class="ops-tabs">
            <?php foreach ($tabs_meta as $key => $t): ?>
                <a href="stock_issue.php?tab=<?php echo $key; ?>" class="ops-tab <?php echo $active_tab === $key ? 'active' : ''; ?>">
                    <i class="bi <?php echo $t['icon']; ?>"></i>
                    <span><strong><?php echo $t['label']; ?></strong><small><?php echo $t['hint']; ?></small></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($active_tab === 'transfer'): ?>
        <!-- ═══════════ 1) إذن صرف وتحويل ═══════════ -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-arrow-left-right"></i> إذن صرف مخزني / تحويل بين مخازن الفرع</h2>
            <form method="POST" action="stock_issue.php?tab=transfer" id="transferForm" onsubmit="return confirmVoucher(this, 'إذن الصرف')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action_issue_transfer" value="1">
                <div style="display:grid; grid-template-columns: 1fr auto 1fr; gap:10px;">
                    <div class="form-group">
                        <label>من مخزن (المصدر)</label>
                        <select name="from_store" id="tr_from" required onchange="onStoreChange('transfer')">
                            <?php foreach ($stores as $k => $s): ?>
                                <option value="<?php echo $k; ?>" <?php echo $k === 'main_hub' ? 'selected' : ''; ?>><?php echo $s['emoji'] . ' ' . $s['label']; ?> (<?php echo intval($store_counts[$k] ?? 0); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flow-arrow"><i class="bi bi-arrow-left-circle-fill"></i></div>
                    <div class="form-group">
                        <label>إلى مخزن (المستلم)</label>
                        <select name="to_store" id="tr_to" required>
                            <?php foreach ($stores as $k => $s): ?>
                                <option value="<?php echo $k; ?>" <?php echo $k === 'kitchen_fluid' ? 'selected' : ''; ?>><?php echo $s['emoji'] . ' ' . $s['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="lines-table">
                        <thead><tr><th style="width:40px;">#</th><th>الصنف</th><th style="width:130px;">الكمية</th><th style="width:140px;">الرصيد المتاح</th><th style="width:120px;">التكلفة</th><th style="width:40px;"></th></tr></thead>
                        <tbody id="lines_transfer"></tbody>
                    </table>
                </div>
                <button type="button" class="btn-add-row" style="margin-top:10px;" onclick="addLine('transfer')"><i class="bi bi-plus-circle-fill"></i> إضافة بند</button>

                <div class="form-group" style="margin-top:12px;">
                    <label>ملاحظات / المستلم</label>
                    <input type="text" name="note" maxlength="250" placeholder="مثال: تجهيز وردية المساء — استلمها: أحمد (الباريستا)">
                </div>
                <div class="form-footer">
                    <div class="total-box">إجمالي قيمة الإذن: <span id="total_transfer">0.000</span> <?php echo get_omr_symbol(); ?></div>
                    <button type="submit" class="btn-submit"><i class="bi bi-check2-circle"></i> اعتماد وترحيل إذن الصرف</button>
                </div>
                <p class="hint">💡 يُخصم الصنف من المخزن المصدر ويُضاف للمخزن المستلم بنفس التكلفة (متوسط مرجّح). إذا لم يكن الصنف موجوداً في المخزن المستلم يُنشأ تلقائياً.</p>
            </form>
        </div>

        <?php elseif ($active_tab === 'out'): ?>
        <!-- ═══════════ 2) صرف استهلاك وهالك ═══════════ -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-trash3"></i> سند صرف استهلاك / تسجيل هالك وتالف</h2>
            <form method="POST" action="stock_issue.php?tab=out" id="outForm" onsubmit="return confirmVoucher(this, 'السند')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action_issue_out" value="1">

                <div class="seg">
                    <input type="radio" name="out_type" id="ot_waste" value="waste" checked onchange="onOutTypeChange()"><label for="ot_waste"><i class="bi bi-exclamation-triangle-fill"></i> هالك / تالف</label>
                    <input type="radio" name="out_type" id="ot_cons" value="consumption" onchange="onOutTypeChange()"><label for="ot_cons"><i class="bi bi-people-fill"></i> صرف استهلاك</label>
                </div>

                <div class="grid-3">
                    <div class="form-group">
                        <label>المخزن</label>
                        <select id="out_store" onchange="onStoreChange('out')">
                            <?php foreach ($stores as $k => $s): ?>
                                <option value="<?php echo $k; ?>" <?php echo $k === 'kitchen_fluid' ? 'selected' : ''; ?>><?php echo $s['emoji'] . ' ' . $s['label']; ?> (<?php echo intval($store_counts[$k] ?? 0); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>السبب</label>
                        <select name="reason" id="out_reason" required>
                            <?php foreach ($out_reasons as $type => $reasons): foreach ($reasons as $rk => $rl): ?>
                                <option value="<?php echo $rk; ?>" data-type="<?php echo $type; ?>"><?php echo $rl; ?></option>
                            <?php endforeach; endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ملاحظات</label>
                        <input type="text" name="note" maxlength="250" placeholder="مثال: حليب منتهي بتاريخ اليوم">
                    </div>
                </div>

                <div class="table-wrap" style="margin-top:12px;">
                    <table class="lines-table">
                        <thead><tr><th style="width:40px;">#</th><th>الصنف</th><th style="width:130px;">الكمية</th><th style="width:140px;">الرصيد المتاح</th><th style="width:120px;">التكلفة</th><th style="width:40px;"></th></tr></thead>
                        <tbody id="lines_out"></tbody>
                    </table>
                </div>
                <button type="button" class="btn-add-row" style="margin-top:10px;" onclick="addLine('out')"><i class="bi bi-plus-circle-fill"></i> إضافة بند</button>

                <div class="form-footer">
                    <div class="total-box">إجمالي قيمة السند (تكلفة على الفرع): <span id="total_out" style="color:var(--primary-red);">0.000</span> <?php echo get_omr_symbol(); ?></div>
                    <button type="submit" class="btn-submit red"><i class="bi bi-check2-circle"></i> ترحيل وخصم من المخزون</button>
                </div>
                <p class="hint">💡 هذا الصرف خروج نهائي من المخزون ويظهر في تقرير الهالك والاستهلاك بسجل الحركات.</p>
            </form>
        </div>

        <?php elseif ($active_tab === 'count'): ?>
        <!-- ═══════════ 3) الجرد الفعلي ═══════════ -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-clipboard-check"></i> ورقة الجرد الفعلي — <?php echo htmlspecialchars(inv_store_label($count_store)); ?></h2>
            <div class="store-chips no-print">
                <?php foreach ($stores as $k => $s): ?>
                    <a href="stock_issue.php?tab=count&store=<?php echo $k; ?>" class="store-chip <?php echo $count_store === $k ? 'active' : ''; ?>"><?php echo $s['emoji'] . ' ' . $s['label']; ?> (<?php echo intval($store_counts[$k] ?? 0); ?>)</a>
                <?php endforeach; ?>
                <button type="button" class="btn-ghost" onclick="window.print()"><i class="bi bi-printer"></i> طباعة ورقة الجرد</button>
            </div>
            <?php if (empty($count_items)): ?>
                <div class="empty">لا توجد أصناف في هذا المخزن.</div>
            <?php else: ?>
            <form method="POST" action="stock_issue.php?tab=count&store=<?php echo $count_store; ?>" onsubmit="return confirmCount()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action_stocktake" value="1">
                <input type="hidden" name="count_store" value="<?php echo htmlspecialchars($count_store); ?>">
                <div class="search-inline no-print" style="margin-bottom:10px;">
                    <input type="text" id="countSearch" placeholder="🔍 ابحث عن صنف في ورقة الجرد..." oninput="filterCount()" style="width:100%; padding:10px; border:1px solid #CBD5E0; border-radius:8px; font-size:13px;">
                </div>
                <div class="table-wrap">
                    <table class="count-table">
                        <thead><tr><th>#</th><th>الصنف</th><th>الوحدة</th><th>رصيد النظام</th><th>الكمية الفعلية</th><th>الفرق</th><th>قيمة الفرق</th></tr></thead>
                        <tbody>
                            <?php foreach ($count_items as $i => $it): ?>
                                <tr class="count-row" data-name="<?php echo htmlspecialchars(mb_strtolower($it['item_name'])); ?>">
                                    <td><?php echo $i + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($it['item_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($it['unit']); ?></td>
                                    <td><strong><?php echo number_format($it['quantity'], 3); ?></strong></td>
                                    <td><input type="number" step="0.001" min="0" name="counted[<?php echo intval($it['id']); ?>]" data-sys="<?php echo floatval($it['quantity']); ?>" data-cost="<?php echo floatval($it['avg_unit_cost']); ?>" oninput="calcVar(this)" placeholder="—"></td>
                                    <td class="var-cell var-zero">—</td>
                                    <td class="var-val var-zero">—</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-group no-print" style="margin-top:12px;">
                    <label>ملاحظات الجرد / القائم بالجرد</label>
                    <input type="text" name="note" maxlength="250" placeholder="مثال: جرد نهاية الأسبوع — بحضور مدير الفرع">
                </div>
                <div class="form-footer no-print">
                    <div class="total-box">أصناف مجرودة: <span id="cnt_n">0</span> · بها فروقات: <span id="cnt_var" style="color:var(--warning-orange);">0</span> · صافي الفرق: <span id="cnt_val">0.000</span> <?php echo get_omr_symbol(); ?></div>
                    <button type="submit" class="btn-submit orange"><i class="bi bi-clipboard2-check-fill"></i> اعتماد الجرد وتسوية الفروقات</button>
                </div>
                <p class="hint no-print">💡 اترك الخانة فارغة للأصناف غير المجرودة. سيتم تعديل رصيد الأصناف التي بها فرق فقط، وتسجيل سند تسوية في سجل الحركات.</p>
            </form>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ═══════════ 4) سجل الحركات ═══════════ -->
        <div class="section-card">
            <h2 class="section-title"><i class="bi bi-journal-text"></i> سجل حركات المخزون <?php echo $f_item_name !== '' ? '— كرت الصنف: ' . htmlspecialchars($f_item_name) : ''; ?></h2>
            <form method="GET" action="stock_issue.php" class="filter-bar">
                <input type="hidden" name="tab" value="log">
                <?php if ($f_item > 0): ?><input type="hidden" name="item" value="<?php echo $f_item; ?>"><?php endif; ?>
                <div class="form-group"><label>من تاريخ</label><input type="date" name="from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
                <div class="form-group"><label>إلى تاريخ</label><input type="date" name="to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
                <div class="form-group"><label>نوع الحركة</label>
                    <select name="type"><option value="">الكل</option>
                        <?php foreach ($movement_types as $k => $t): ?><option value="<?php echo $k; ?>" <?php echo $f_type === $k ? 'selected' : ''; ?>><?php echo $t['label']; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>المخزن</label>
                    <select name="store"><option value="">كل المخازن</option>
                        <?php foreach ($stores as $k => $s): ?><option value="<?php echo $k; ?>" <?php echo $f_store === $k ? 'selected' : ''; ?>><?php echo $s['label']; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>بحث (صنف / رقم سند)</label><input type="text" id="logSearch" value="<?php echo htmlspecialchars($_GET['voucher'] ?? ''); ?>" oninput="filterLog()" placeholder="TR-..."></div>
                <div style="display:flex; gap:6px;">
                    <button type="submit" class="btn-submit" style="padding:10px 14px;"><i class="bi bi-funnel-fill"></i> تصفية</button>
                    <button type="button" class="btn-ghost" onclick="window.print()"><i class="bi bi-printer"></i></button>
                </div>
            </form>

            <div class="kpi-row">
                <?php foreach ($movement_types as $k => $t): $tt = $log_totals[$k] ?? ['count' => [], 'value' => 0]; ?>
                    <div class="kpi" style="border-right:4px solid <?php echo $t['color']; ?>;">
                        <small><?php echo $t['label']; ?> (<?php echo count($tt['count']); ?> سند)</small>
                        <strong style="color:<?php echo $t['color']; ?>;"><?php echo number_format($tt['value'], 3); ?> <?php echo get_omr_symbol(); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap">
                <table class="log-table" id="logTable">
                    <thead><tr><th>التاريخ والوقت</th><th>رقم السند</th><th>النوع</th><th>الصنف</th><th>المخزن / الاتجاه</th><th>الكمية</th><th>الرصيد بعد</th><th>القيمة</th><th>السبب / ملاحظات</th><th>بواسطة</th></tr></thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr><td colspan="10" class="empty">لا توجد حركات مخزنية في الفترة المحددة.</td></tr>
                        <?php else: foreach ($movements as $mv):
                            $t = $movement_types[$mv['movement_type']] ?? ['label' => $mv['movement_type'], 'color' => '#444', 'bg' => '#eee'];
                            $q = floatval($mv['qty']);
                            $reason_label = '';
                            if (!empty($mv['reason'])) {
                                foreach ($out_reasons as $rs) { if (isset($rs[$mv['reason']])) { $reason_label = $rs[$mv['reason']]; } }
                                if ($mv['reason'] === 'surplus') $reason_label = 'زيادة جرد';
                                if ($mv['reason'] === 'shortage') $reason_label = 'عجز جرد';
                                if ($mv['reason'] === 'prep') $reason_label = 'تحضير يومي';
                                if ($mv['reason'] === 'batch_input') $reason_label = 'مادة خام مستهلكة';
                                if ($mv['reason'] === 'batch_output') $reason_label = 'ناتج التصنيع';
                            }
                            if ($mv['movement_type'] === 'transfer') {
                                $dir = $q < 0 ? 'خروج من ' . inv_store_label($mv['from_store']) : 'دخول إلى ' . inv_store_label($mv['to_store']);
                            } elseif ($q > 0 && !empty($mv['to_store']) && $mv['movement_type'] !== 'count_adjust' && $mv['movement_type'] !== 'manual_edit') {
                                $dir = 'دخول إلى ' . inv_store_label($mv['to_store']);
                            } else {
                                $dir = inv_store_label($mv['from_store']);
                            }
                        ?>
                            <tr class="log-row" data-search="<?php echo htmlspecialchars(mb_strtolower($mv['item_name'] . ' ' . $mv['voucher_no'])); ?>">
                                <td><small><?php echo date('Y-m-d H:i', strtotime($mv['created_at'])); ?></small></td>
                                <td><span class="voucher-link" onclick="showVoucher('<?php echo htmlspecialchars($mv['voucher_no'], ENT_QUOTES); ?>')"><?php echo htmlspecialchars($mv['voucher_no']); ?></span></td>
                                <td><span class="mv-badge" style="background:<?php echo $t['bg']; ?>; color:<?php echo $t['color']; ?>;"><?php echo $t['label']; ?></span></td>
                                <td><a href="stock_issue.php?tab=log&item=<?php echo intval($mv['item_id']); ?>&from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&to=<?php echo date('Y-m-d'); ?>" style="color:var(--text-dark); font-weight:700; text-decoration:none;"><?php echo htmlspecialchars($mv['item_name']); ?></a></td>
                                <td><small><?php echo htmlspecialchars($dir); ?></small></td>
                                <td class="<?php echo $q >= 0 ? 'qty-in' : 'qty-out'; ?>" dir="ltr" style="text-align:right;"><?php echo ($q > 0 ? '+' : '') . number_format($q, 3) . ' ' . htmlspecialchars($mv['unit']); ?></td>
                                <td><?php echo $mv['balance_after'] !== null ? number_format($mv['balance_after'], 3) : '—'; ?></td>
                                <td><?php echo number_format(floatval($mv['total_cost']), 3); ?></td>
                                <td><small><?php echo htmlspecialchars(trim($reason_label . ($mv['note'] ? ' — ' . $mv['note'] : ''), ' —')); ?></small></td>
                                <td><small><?php echo htmlspecialchars($mv['username'] ?? '—'); ?></small></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint">💡 اضغط على اسم الصنف لعرض كرت الصنف (كل حركاته خلال 30 يوماً)، وعلى رقم السند لعرضه وطباعته.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const INV_ITEMS = <?php echo json_encode($items_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const STORE_LABELS = <?php echo json_encode(array_map(function ($s) { return $s['label']; }, $stores), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const COMPANY = <?php echo json_encode($global_company_name_ar . ' — ' . $current_branch_name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

function fmt(n, d) { return (Math.round(n * Math.pow(10, d)) / Math.pow(10, d)).toFixed(d); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function currentStore(kind) {
    const el = document.getElementById(kind === 'transfer' ? 'tr_from' : 'out_store');
    return el ? el.value : '';
}

function itemOptions(store) {
    let html = '<option value="">-- اختر الصنف --</option>';
    INV_ITEMS.filter(i => i.store === store).forEach(i => {
        html += `<option value="${i.id}">${esc(i.name)} (${esc(i.unit)})</option>`;
    });
    return html;
}

function addLine(kind) {
    const body = document.getElementById('lines_' + kind);
    if (!body) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="ln"></td>
        <td><select name="item_ids[]" onchange="updateLine(this.closest('tr'), '${kind}')">${itemOptions(currentStore(kind))}</select></td>
        <td><input type="number" class="qty-input" name="qtys[]" step="0.001" min="0.001" placeholder="0.000" oninput="updateLine(this.closest('tr'), '${kind}')"></td>
        <td><span class="avail-pill">—</span></td>
        <td class="line-cost">0.000</td>
        <td><button type="button" class="btn-del-line" onclick="this.closest('tr').remove(); renumber('${kind}'); recalc('${kind}');">&times;</button></td>`;
    body.appendChild(tr);
    renumber(kind);
}

function renumber(kind) {
    document.querySelectorAll('#lines_' + kind + ' tr').forEach((tr, i) => { tr.querySelector('.ln').textContent = i + 1; });
}

function updateLine(tr, kind) {
    const id = parseInt(tr.querySelector('select').value || 0);
    const qty = parseFloat(tr.querySelector('input').value || 0);
    const item = INV_ITEMS.find(i => i.id === id);
    const pill = tr.querySelector('.avail-pill');
    if (!item) { pill.textContent = '—'; pill.className = 'avail-pill'; tr.querySelector('.line-cost').textContent = '0.000'; recalc(kind); return; }
    pill.textContent = fmt(item.qty, 3) + ' ' + item.unit;
    pill.className = 'avail-pill' + (qty > item.qty ? ' over' : (item.qty <= item.min ? ' low' : ''));
    if (qty > item.qty) pill.textContent += ' ⚠️ غير كافٍ';
    tr.querySelector('.line-cost').textContent = fmt(qty * item.cost, 3);
    recalc(kind);
}

function recalc(kind) {
    let total = 0;
    document.querySelectorAll('#lines_' + kind + ' .line-cost').forEach(td => total += parseFloat(td.textContent || 0));
    const el = document.getElementById('total_' + kind);
    if (el) el.textContent = fmt(total, 3);
}

function onStoreChange(kind) {
    const body = document.getElementById('lines_' + kind);
    if (!body) return;
    body.innerHTML = '';
    addLine(kind);
    recalc(kind);
    if (kind === 'transfer') {
        const from = document.getElementById('tr_from').value, to = document.getElementById('tr_to');
        if (to.value === from) { to.value = from === 'main_hub' ? 'kitchen_fluid' : 'main_hub'; }
    }
}

function onOutTypeChange() {
    const type = document.querySelector('input[name="out_type"]:checked').value;
    const sel = document.getElementById('out_reason');
    let first = null;
    Array.from(sel.options).forEach(o => { const show = o.dataset.type === type; o.hidden = !show; o.disabled = !show; if (show && !first) first = o; });
    if (first) sel.value = first.value;
}

function confirmVoucher(form, label) {
    const rows = form.querySelectorAll('tbody tr');
    let n = 0, bad = false;
    rows.forEach(tr => {
        const id = tr.querySelector('select').value, q = parseFloat(tr.querySelector('input').value || 0);
        if (id && q > 0) { n++; const it = INV_ITEMS.find(i => i.id == id); if (it && q > it.qty) bad = true; }
    });
    if (n === 0) { alert('أضف بنداً واحداً على الأقل مع الكمية.'); return false; }
    if (bad) { alert('⚠️ يوجد بند كميته أكبر من الرصيد المتاح. عدّل الكمية قبل الترحيل.'); return false; }
    if (form.to_store && form.from_store.value === form.to_store.value) { alert('لا يمكن الصرف من المخزن إلى نفسه.'); return false; }
    return confirm('تأكيد ترحيل ' + label + ' (' + n + ' بند)؟ لا يمكن التراجع إلا بسند عكسي.');
}

// الجرد
function calcVar(input) {
    const tr = input.closest('tr');
    const vc = tr.querySelector('.var-cell'), vv = tr.querySelector('.var-val');
    if (input.value === '') { vc.textContent = '—'; vv.textContent = '—'; vc.className = 'var-cell var-zero'; vv.className = 'var-val var-zero'; summarizeCount(); return; }
    const diff = parseFloat(input.value) - parseFloat(input.dataset.sys);
    const val = diff * parseFloat(input.dataset.cost);
    const cls = Math.abs(diff) < 0.0005 ? 'var-zero' : (diff > 0 ? 'var-pos' : 'var-neg');
    vc.textContent = (diff > 0 ? '+' : '') + fmt(diff, 3); vc.className = 'var-cell ' + cls;
    vv.textContent = fmt(val, 3); vv.className = 'var-val ' + cls;
    summarizeCount();
}
function summarizeCount() {
    let n = 0, v = 0, total = 0;
    document.querySelectorAll('.count-table input').forEach(inp => {
        if (inp.value === '') return; n++;
        const d = parseFloat(inp.value) - parseFloat(inp.dataset.sys);
        if (Math.abs(d) >= 0.0005) { v++; total += d * parseFloat(inp.dataset.cost); }
    });
    const s = (id, t) => { const e = document.getElementById(id); if (e) e.textContent = t; };
    s('cnt_n', n); s('cnt_var', v); s('cnt_val', fmt(total, 3));
}
function filterCount() {
    const q = document.getElementById('countSearch').value.trim().toLowerCase();
    document.querySelectorAll('.count-row').forEach(r => r.style.display = r.dataset.name.includes(q) ? '' : 'none');
}
function confirmCount() {
    const n = parseInt(document.getElementById('cnt_n').textContent || 0);
    if (n === 0) { alert('أدخل الكمية الفعلية لصنف واحد على الأقل.'); return false; }
    return confirm('اعتماد الجرد لـ ' + n + ' صنف وتسوية ' + document.getElementById('cnt_var').textContent + ' فرق؟');
}

// السجل
function filterLog() {
    const el = document.getElementById('logSearch'); if (!el) return;
    const q = el.value.trim().toLowerCase();
    document.querySelectorAll('.log-row').forEach(r => r.style.display = r.dataset.search.includes(q) ? '' : 'none');
}
function showVoucher(no) {
    const rows = Array.from(document.querySelectorAll('.log-row')).filter(r => r.dataset.search.includes(no.toLowerCase()));
    if (!rows.length) return;
    let lines = '';
    rows.forEach(r => {
        const c = r.querySelectorAll('td');
        lines += `<tr><td>${c[3].innerText}</td><td>${c[4].innerText}</td><td dir="ltr">${c[5].innerText}</td><td>${c[7].innerText}</td></tr>`;
    });
    const first = rows[rows.length - 1].querySelectorAll('td');
    const w = window.open('', '_blank', 'width=720,height=820');
    if (!w) return;
    w.document.write(`<html dir="rtl"><head><meta charset="utf-8"><title>سند ${esc(no)}</title>
        <style>body{font-family:Tahoma,Arial;padding:24px;color:#222} h2{color:#B82929;margin:0 0 6px} table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px} td,th{border:1px solid #ccc;padding:8px;text-align:right} th{background:#F1ECE4} .meta{font-size:13px;line-height:1.9} .sign{display:flex;justify-content:space-between;margin-top:60px;font-size:13px}</style></head>
        <body><h2>${esc(COMPANY)}</h2><div class="meta"><b>${first[2].innerText}</b> — رقم السند: <b>${esc(no)}</b><br>التاريخ: ${first[0].innerText} · بواسطة: ${first[9].innerText}<br>${first[8].innerText ? 'ملاحظات: ' + first[8].innerText : ''}</div>
        <table><thead><tr><th>الصنف</th><th>المخزن / الاتجاه</th><th>الكمية</th><th>القيمة</th></tr></thead><tbody>${lines}</tbody></table>
        <div class="sign"><span>توقيع أمين المخزن: ..................</span><span>توقيع المستلم: ..................</span></div>
        <script>window.onload=function(){window.print();}<\/script></body></html>`);
    w.document.close();
}

document.addEventListener('DOMContentLoaded', function () {
    const cupToggle = document.getElementById('cupToggle');
    if (cupToggle) cupToggle.addEventListener('click', function (e) { e.stopPropagation(); document.body.classList.toggle('sidebar-show'); });
    if (document.getElementById('lines_transfer')) { addLine('transfer'); addLine('transfer'); }
    if (document.getElementById('lines_out')) { onOutTypeChange(); addLine('out'); }
    filterLog();
});
</script>
</body>
</html>
