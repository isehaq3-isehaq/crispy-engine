<?php
// inventory_lib.php
// مكتبة مشتركة لوحدة المخزون - شاي سيّار
// تعريف المخازن، وجدول حركات المخزون الموحد (Stock Movements Ledger) ودوال التسجيل

if (!function_exists('inv_store_types')) {
    // 🏬 المخازن المعتمدة في الفرع بترتيب سير العمل في المقهى
    function inv_store_types() {
        return [
            'main_hub'         => ['label' => 'المخزن الرئيسي',          'short' => 'الرئيسي',  'icon' => 'bi-box-seam',      'emoji' => '📦', 'color' => '#4B5563'],
            'kitchen_fluid'    => ['label' => 'المطبخ والبار (التحضير)', 'short' => 'المطبخ/البار', 'icon' => 'bi-cup-hot',    'emoji' => '☕', 'color' => '#B82929'],
            'pre_made_chilled' => ['label' => 'الثلاجة والخلطات الوسيطة', 'short' => 'الثلاجة',  'icon' => 'bi-snow',          'emoji' => '🥟', 'color' => '#D97706'],
            'ready_unbaked'    => ['label' => 'الجاهز للبيع (العرض)',     'short' => 'الجاهز للبيع', 'icon' => 'bi-shop',     'emoji' => '🛍️', 'color' => '#0F754C'],
        ];
    }
}

if (!function_exists('inv_store_label')) {
    function inv_store_label($type) {
        $t = inv_store_types();
        return isset($t[$type]) ? $t[$type]['label'] : 'خارج المخازن';
    }
}

if (!function_exists('inv_category_label')) {
    function inv_category_label($cat) {
        $map = ['food' => 'مواد غذائية', 'packaging' => 'أوعية وتغليف', 'ready_to_sell' => 'جاهز للبيع', 'external' => 'مشتريات خارجية'];
        return $map[$cat] ?? 'غير مصنف';
    }
}

if (!function_exists('inv_movement_types')) {
    // 🔁 أنواع حركات المخزون
    function inv_movement_types() {
        return [
            'transfer'     => ['label' => 'إذن صرف / تحويل',  'prefix' => 'TR', 'color' => '#1D4ED8', 'bg' => '#DBEAFE'],
            'consumption'  => ['label' => 'صرف استهلاك',       'prefix' => 'CN', 'color' => '#7C3AED', 'bg' => '#EDE9FE'],
            'waste'        => ['label' => 'هالك / تالف',        'prefix' => 'WS', 'color' => '#B82929', 'bg' => '#FEE2E2'],
            'count_adjust' => ['label' => 'تسوية جرد',          'prefix' => 'ST', 'color' => '#D97706', 'bg' => '#FEF3C7'],
            'manual_edit'  => ['label' => 'تعديل يدوي',         'prefix' => 'ED', 'color' => '#4B5563', 'bg' => '#E5E7EB'],
            'purchase'     => ['label' => 'توريد مشتريات',     'prefix' => 'PU', 'color' => '#0F754C', 'bg' => '#D1FAE5'],
            'production'   => ['label' => 'إنتاج وتحضير',      'prefix' => 'PR', 'color' => '#0E7490', 'bg' => '#CFFAFE'],
        ];
    }
}

if (!function_exists('inv_out_reasons')) {
    // أسباب الصرف الخارجي (لا ينتقل لمخزن آخر)
    function inv_out_reasons() {
        return [
            'consumption' => [
                'operations'  => 'استخدام تشغيلي (تنظيف/تجهيز)',
                'staff_meal'  => 'وجبات وضيافة الموظفين',
                'hospitality' => 'ضيافة وعينات للزبائن',
                'tasting'     => 'تذوق وضبط جودة',
            ],
            'waste' => [
                'expired'   => 'منتهي الصلاحية',
                'spoiled'   => 'تالف / فاسد',
                'spilled'   => 'انسكاب أو كسر',
                'returned'  => 'مرتجع من زبون',
                'wrong_mix' => 'خطأ تحضير',
            ],
        ];
    }
}

if (!function_exists('inv_ensure_movements_table')) {
    function inv_ensure_movements_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL DEFAULT 1,
            voucher_no VARCHAR(40) NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            item_id INT NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            from_store VARCHAR(50) DEFAULT NULL,
            to_store VARCHAR(50) DEFAULT NULL,
            qty DECIMAL(12,3) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT '',
            unit_cost DECIMAL(12,5) NOT NULL DEFAULT 0.00000,
            total_cost DECIMAL(14,5) NOT NULL DEFAULT 0.00000,
            balance_after DECIMAL(12,3) DEFAULT NULL,
            reason VARCHAR(50) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_branch_date (branch_id, created_at),
            KEY idx_item (item_id),
            KEY idx_voucher (voucher_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
}

if (!function_exists('inv_next_voucher_no')) {
    // رقم سند متسلسل يومي: TR-261104-0003
    function inv_next_voucher_no($pdo, $branch_id, $movement_type) {
        $types = inv_movement_types();
        $prefix = $types[$movement_type]['prefix'] ?? 'MV';
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT voucher_no) FROM inventory_movements WHERE branch_id = ? AND movement_type = ? AND DATE(created_at) = CURRENT_DATE()");
        $stmt->execute([$branch_id, $movement_type]);
        $seq = intval($stmt->fetchColumn()) + 1;
        return $prefix . '-' . date('ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('inv_receive_stock')) {
    /**
     * إدخال كمية لمخزن في الفرع مع تحديث تكلفة الوحدة بالمتوسط المرجّح.
     * يبحث عن الصنف بالاسم داخل نفس الفرع والمخزن، وينشئه إن لم يوجد. يُستدعى داخل Transaction.
     * يعيد: ['id' => .., 'balance' => ..]
     */
    function inv_receive_stock($pdo, $branch_id, $item_name, $store, $qty, $unit, $unit_cost, $main_category = 'food', $sub_category = '') {
        $stmt = $pdo->prepare("SELECT id, quantity, avg_unit_cost FROM inventory WHERE branch_id = ? AND item_name = ? AND inventory_type = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$branch_id, $item_name, $store]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $old_q = max(0, floatval($row['quantity']));
            $old_c = floatval($row['avg_unit_cost']);
            $new_q = floatval($row['quantity']) + $qty;
            $base = $old_q + $qty;
            $new_c = ($unit_cost > 0 && $base > 0) ? (($old_q * $old_c) + ($qty * $unit_cost)) / $base : $old_c;
            $pdo->prepare("UPDATE inventory SET quantity = ?, avg_unit_cost = ? WHERE id = ? AND branch_id = ?")->execute([$new_q, $new_c, $row['id'], $branch_id]);
            return ['id' => intval($row['id']), 'balance' => $new_q];
        }
        $pdo->prepare("INSERT INTO inventory (branch_id, item_name, inventory_type, main_category, sub_category, quantity, unit, min_limit, avg_unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)")
            ->execute([$branch_id, $item_name, $store, $main_category, $sub_category, $qty, $unit, max(0, $unit_cost)]);
        return ['id' => intval($pdo->lastInsertId()), 'balance' => $qty];
    }
}

if (!function_exists('inv_log_movement')) {
    function inv_log_movement($pdo, array $m) {
        $stmt = $pdo->prepare("INSERT INTO inventory_movements
            (branch_id, voucher_no, movement_type, item_id, item_name, from_store, to_store, qty, unit, unit_cost, total_cost, balance_after, reason, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $qty = floatval($m['qty']);
        $unit_cost = floatval($m['unit_cost'] ?? 0);
        $stmt->execute([
            intval($m['branch_id']),
            $m['voucher_no'],
            $m['movement_type'],
            intval($m['item_id']),
            $m['item_name'],
            $m['from_store'] ?? null,
            $m['to_store'] ?? null,
            $qty,
            $m['unit'] ?? '',
            $unit_cost,
            abs($qty) * $unit_cost,
            isset($m['balance_after']) ? floatval($m['balance_after']) : null,
            $m['reason'] ?? null,
            isset($m['note']) ? mb_substr($m['note'], 0, 255) : null,
            intval($m['created_by'] ?? 0),
        ]);
    }
}
